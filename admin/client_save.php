<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/infra/lib/keywords.php'; // infra_kw_has_creds()/infra_kw_provider()/infra_kw_dfs_post() — the 'pages' section's check_rank action

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: clients.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: clients.php?msg=error:Invalid+request+token');
    exit;
}

$section = $_POST['section'] ?? '';

switch ($section) {

    /* ---- IDENTITY ---- */
    case 'identity':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $name = trim($_POST['business_name'] ?? '');
        if ($name === '') {
            header('Location: client.php?id=' . urlencode($client_id) . '&msg=error:Business+name+cannot+be+empty');
            exit;
        }
        // Stored as a bare hostname, no scheme/trailing slash — the Reporting
        // tab's GSC page-URL lookup does 'https://' . $domain . ... and breaks
        // (double scheme) if this ever holds a full URL instead.
        $domain = preg_replace('~^https?://~i', '', trim($_POST['domain'] ?? ''));
        $domain = rtrim($domain, '/');

        $client['identity'] = [
            'business_name'     => $name,
            'domain'            => $domain,
            'industry_category' => trim($_POST['industry_category'] ?? ''),
            'contact_name'      => trim($_POST['contact_name'] ?? ''),
            'contact_email'     => trim($_POST['contact_email'] ?? ''),
            'contact_phone'     => trim($_POST['contact_phone'] ?? ''),
        ];
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&msg=success:Identity+saved+-+Regenerate+%2B+Publish+affected+pages+to+push+the+change+live');
        exit;

    /* ---- HUBS (add / edit / delete) ---- */
    case 'hubs':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['hub_action'] ?? '';

        if ($action === 'add') {
            $client['hubs'][] = [
                'hub_id'  => $client_id . '-hub-' . (count($client['hubs']) + 1),
                'name'    => trim($_POST['name'] ?? ''),
                'address' => [
                    'street' => trim($_POST['street'] ?? ''),
                    'city'   => trim($_POST['city'] ?? ''),
                    'state'  => trim($_POST['state'] ?? ''),
                    'zip'    => trim($_POST['zip'] ?? ''),
                ],
                'phone' => trim($_POST['phone'] ?? ''),
                'geo'   => ['lat' => null, 'lng' => null],
            ];
        } elseif ($action === 'delete') {
            $hub_id = $_POST['hub_id'] ?? '';
            $stillServing = false;
            foreach ($client['target_cities'] ?? [] as $tc) {
                if (($tc['served_by_hub'] ?? '') === $hub_id) { $stillServing = true; break; }
            }
            if ($stillServing) {
                header('Location: client.php?id=' . urlencode($client_id) . '&msg=error:Cannot+delete+a+hub+that+still+serves+a+target+city');
                exit;
            }
            $client['hubs'] = array_values(array_filter($client['hubs'], fn($h) => $h['hub_id'] !== $hub_id));
        }

        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&msg=success:Hub+updated');
        exit;

    /* ---- BILLING ---- */
    case 'billing':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['billing'] = [
            'setup_fee_per_location'   => (float) ($_POST['setup_fee_per_location'] ?? 0),
            'monthly_fee_per_location' => (float) ($_POST['monthly_fee_per_location'] ?? 0),
            'start_date'               => trim($_POST['start_date'] ?? ''),
        ];
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&msg=success:Billing+updated');
        exit;

    /* ---- BRANDING ---- */
    case 'branding':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['branding'] = [
            'logo_url'      => trim($_POST['logo_url'] ?? ''),
            'primary_color' => trim($_POST['primary_color'] ?? ''),
            'accent_color'  => trim($_POST['accent_color'] ?? ''),
            'font'          => trim($_POST['font'] ?? ''),
        ];
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=branding&msg=success:Branding+updated');
        exit;

    /* ---- CONTENT PROFILE ---- */
    case 'content_profile':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        // 'source' (Base profile) is deliberately NOT writable here — it's set
        // once at creation from the industry category and shown read-only,
        // per the design doc's explicit instruction.
        $splitLines = fn($s) => array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $s ?? '')))));
        $client['content_profile']['excluded_services'] = array_values((array) ($_POST['excluded_services'] ?? []));
        $client['content_profile']['tone']               = trim($_POST['tone'] ?? 'professional');
        $client['content_profile']['always_mention']      = $splitLines($_POST['always_mention'] ?? '');
        $client['content_profile']['never_mention']       = $splitLines($_POST['never_mention'] ?? '');

        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=content_profile&msg=success:Content+profile+updated');
        exit;

    /* ---- REPORTING ---- */
    case 'reporting':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['reporting']['gsc_property'] = trim($_POST['gsc_property'] ?? '');
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=reporting&msg=success:Reporting+settings+saved');
        exit;

    /* ---- INTEGRATIONS (analytics / CDN — stored, inert until a real account exists) ---- */
    case 'integrations':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['integrations']['ga_measurement_id']  = trim($_POST['ga_measurement_id'] ?? '');
        $client['integrations']['cloudflare_account']  = trim($_POST['cloudflare_account'] ?? '');
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=success:Integrations+saved');
        exit;

    /* ---- DELIVERY SETTINGS (support phone/message) ---- */
    case 'delivery_settings':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['delivery']['support_phone']   = trim($_POST['support_phone'] ?? '');
        $client['delivery']['support_message'] = trim($_POST['support_message'] ?? '');
        lpc_save_client($client_id, $client);
        // Takes effect on the client's next scheduled check-in either way, but
        // rebuilding now means it's live immediately rather than waiting on an
        // unrelated page publish to happen to refresh the manifest.
        lpc_rebuild_manifest($client_id);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=success:Support+info+saved');
        exit;

    /* ---- TOGGLE ACTIVE (disable / re-enable) ---- */
    case 'toggle_active':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['delivery']['active'] = !$client['delivery']['active'];
        lpc_save_client($client_id, $client);
        // Both the key lookup (gates every request) and the manifest (carries
        // the active flag's effect to the plugin's next check-in) need to
        // reflect this immediately — not wait for the next unrelated publish.
        lpc_ensure_delivery_key($client_id);
        lpc_rebuild_manifest($client_id);
        $msg = $client['delivery']['active'] ? 'success:Client+re-enabled' : 'success:Client+disabled';
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=' . $msg);
        exit;

    /* ---- REGENERATE API KEY ---- */
    case 'regenerate_key':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        lpc_set_client_api_key($client_id, lpc_generate_api_key());
        lpc_ensure_delivery_key($client_id); // drops the old key's entry, adds the new one
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=success:Key+regenerated');
        exit;

    /* ---- EMAIL TO CUSTOMER ---- */
    case 'email_to_customer':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $ok = lpc_send_client_email(
            $client_id,
            trim($_POST['recipient'] ?? ''),
            !empty($_POST['include_key']),
            !empty($_POST['include_plugin_link']),
            $_POST['message'] ?? ''
        );
        $msg = $ok ? 'success:Email+sent' : 'error:Could+not+send+email+-+check+the+recipient+address';
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=' . $msg);
        exit;

    /* ---- SERVICES ---- */
    case 'services':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        if (($_POST['service_action'] ?? 'add') === 'remove') {
            $serviceId = $_POST['service_id'] ?? '';
            $client['services'] = array_values(array_filter($client['services'], fn($s) => $s['service_id'] !== $serviceId));
            // Pulled from every city's service list too, so the matrix column
            // actually disappears instead of leaving a dangling reference. Any
            // page already generated for this service is left on disk, not
            // deleted — re-adding the same service (same slug) brings it right
            // back into the matrix, same low-risk pattern as a disabled client.
            foreach ($client['target_cities'] as &$tc) {
                $tc['services'] = array_values(array_filter($tc['services'], fn($sid) => $sid !== $serviceId));
            }
            unset($tc);
            lpc_save_client($client_id, $client);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Service+removed');
            exit;
        }

        if (($_POST['service_action'] ?? '') === 'upload_image') {
            $serviceId = $_POST['service_id'] ?? '';
            $file = $_FILES['service_image'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:No+file+uploaded');
                exit;
            }
            $uploaded = lpc_upload_service_image($client_id, $file['tmp_name'], $file['name']);
            if ($uploaded === false) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Upload+failed+-+check+file+type%2Fsize');
                exit;
            }
            foreach ($client['services'] as &$s) {
                if ($s['service_id'] !== $serviceId) continue;
                $s['image_real']   = $uploaded['filename'];
                $s['image_real_w'] = $uploaded['width'];
                $s['image_real_h'] = $uploaded['height'];
                $s['image_active'] = 'real'; // a fresh upload becomes active immediately, same as Pic Drop's own "newest wins" default
                break;
            }
            unset($s);
            lpc_save_client($client_id, $client);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Image+uploaded');
            exit;
        }

        if (($_POST['service_action'] ?? '') === 'generate_image') {
            $serviceId = $_POST['service_id'] ?? '';
            $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $serviceId);
            if (!$service) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Service+not+found');
                exit;
            }
            $niche_brief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
            $result = lpc_generate_service_image($client_id, $service['label'], $niche_brief);
            if (!$result['ok']) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:' . urlencode($result['msg']));
                exit;
            }
            foreach ($client['services'] as &$s) {
                if ($s['service_id'] !== $serviceId) continue;
                $s['image_ai']   = $result['filename'];
                $s['image_ai_w'] = $result['width'];
                $s['image_ai_h'] = $result['height'];
                $s['image_active'] = 'ai';
                break;
            }
            unset($s);
            lpc_save_client($client_id, $client);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:AI+image+generated');
            exit;
        }

        if (($_POST['service_action'] ?? '') === 'set_active_image') {
            $serviceId = $_POST['service_id'] ?? '';
            $which = $_POST['which'] ?? '';
            if (!in_array($which, ['real', 'ai'], true)) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Invalid+selection');
                exit;
            }
            foreach ($client['services'] as &$s) {
                if ($s['service_id'] !== $serviceId) continue;
                $s['image_active'] = $which;
                break;
            }
            unset($s);
            lpc_save_client($client_id, $client);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Active+image+switched');
            exit;
        }

        $label = trim($_POST['service_label'] ?? '');
        if ($label === '') {
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Service+name+cannot+be+empty');
            exit;
        }
        $serviceId = slugify($label);
        $exists = false;
        foreach ($client['services'] as $s) { if ($s['service_id'] === $serviceId) $exists = true; }
        if ($exists) {
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:That+service+already+exists');
            exit;
        }
        $client['services'][] = ['service_id' => $serviceId, 'label' => $label];
        // "Applied to all cities by default" per the design — a new service
        // column should show up as a Not Created cell for every existing
        // target city, not just future ones.
        foreach ($client['target_cities'] as &$tc) {
            if (!in_array($serviceId, $tc['services'], true)) {
                $tc['services'][] = $serviceId;
            }
        }
        unset($tc);
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Service+added');
        exit;

    /* ---- TARGET CITIES (bulk add from the suggestion table / remove) ---- */
    case 'target_cities':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['tc_action'] ?? '';

        $newCityIds = [];
        if ($action === 'add_selected') {
            $hubId = trim($_POST['hub_id'] ?? '');
            foreach ((array) ($_POST['selected'] ?? []) as $packed) {
                [$cityId, $city, $state] = array_pad(explode('|', $packed, 3), 3, '');
                if ($cityId === '') continue;
                $exists = false;
                foreach ($client['target_cities'] as $tc) { if ($tc['city_id'] === $cityId) $exists = true; }
                if (!$exists) {
                    $client['target_cities'][] = [
                        'city_id' => $cityId, 'city' => $city, 'state' => $state,
                        'served_by_hub' => $hubId,
                        'services' => array_column($client['services'], 'service_id'),
                    ];
                    $newCityIds[] = $cityId;
                }
            }
        } elseif ($action === 'remove') {
            $cityId = $_POST['city_id'] ?? '';
            $client['target_cities'] = array_values(array_filter($client['target_cities'], fn($tc) => $tc['city_id'] !== $cityId));
        } elseif ($action === 'toggle_research') {
            // Manual tracking only, same shape as a page's Locked toggle — no
            // automated research pipeline writes this yet (Phase 3, not built).
            $cityId = $_POST['city_id'] ?? '';
            foreach ($client['target_cities'] as &$tc) {
                if ($tc['city_id'] !== $cityId) continue;
                $tc['research_done'] = empty($tc['research_done']);
                $tc['researched_at'] = $tc['research_done'] ? gmdate('c') : null;
                break;
            }
            unset($tc);
            lpc_save_client($client_id, $client);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:City+updated');
            exit;
        } elseif ($action === 'check_demand') {
            // Real DataForSEO cost — the matrix's own button already shows a
            // cost-warning confirm() before this ever posts. Session-cached
            // only, same "transient, not written into client.json" choice the
            // wizard's own city-level keyword fetch already made.
            $cityId = $_POST['city_id'] ?? '';
            $tc = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $cityId);
            if (!$tc) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:City+not+found');
                exit;
            }
            $result = lpc_service_keyword_check($client['services'] ?? [], $tc['city'], $tc['state']);
            if ($result['ok']) {
                $_SESSION['lpc_kw_city'][$client_id][$cityId] = $result['data'];
                $msg = 'success:Demand+data+fetched';
            } else {
                $msg = 'error:' . urlencode($result['msg']);
            }
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=' . $msg);
            exit;
        } elseif ($action === 'toggle_service') {
            // Closes a real gap: today a service applies to every city or
            // none (client-wide exclude only) — there's never been a way to
            // drop ONE service from ONE city. A page already on disk is left
            // alone either way (soft toggle, same pattern as the client-wide
            // remove-service action) — but a PUBLISHED page is refused here,
            // since silently pulling a live page out of the matrix without a
            // bigger unpublish decision is a different, bigger action than
            // this toggle is meant to be.
            $cityId    = $_POST['city_id'] ?? '';
            $serviceId = $_POST['service_id'] ?? '';
            $tc = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $cityId);
            if (!$tc) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:City+not+found');
                exit;
            }
            if (in_array($serviceId, $tc['services'], true)) {
                $existingPage = lpc_load_page($client_id, $cityId . '__' . $serviceId);
                if ($existingPage && ($existingPage['status'] ?? '') === 'published') {
                    header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Unpublish+this+page+before+excluding+it+from+the+city');
                    exit;
                }
            }
            foreach ($client['target_cities'] as &$t) {
                if ($t['city_id'] !== $cityId) continue;
                if (in_array($serviceId, $t['services'], true)) {
                    $t['services'] = array_values(array_filter($t['services'], fn($sid) => $sid !== $serviceId));
                } else {
                    $t['services'][] = $serviceId;
                }
                break;
            }
            unset($t);
            lpc_save_client($client_id, $client);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Service+updated');
            exit;
        }

        lpc_save_client($client_id, $client);

        // "Confirming a target city auto-generates draft content for every
        // service on it immediately" per the design doc — real AI cost per
        // page, so the UI shows a cost-warning confirm before this ever
        // fires (see client.php's "Add Selected" button), not a silent charge.
        if ($newCityIds) {
            set_time_limit(300); // several real API calls in one request — the default limit would cut this off partway through
            $generated = 0;
            foreach ($newCityIds as $cityId) {
                $r = lpc_generate_drafts_for_target_city($client_id, $cityId);
                $generated += $r['generated'];
            }
            $msg = 'success:Added+' . count($newCityIds) . '+cit(ies)%2C+' . $generated . '+draft(s)+generated';
        } else {
            $msg = 'success:Target+cities+updated';
        }
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=' . $msg);
        exit;

    /* ---- PAGES (generate a blank draft / publish one or more) ---- */
    case 'pages':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['page_action'] ?? '';

        if ($action === 'save_content') {
            $pageId = trim($_POST['page_id'] ?? '');
            if (!lpc_valid_page_id($pageId)) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Invalid+page');
                exit;
            }
            $page = lpc_load_page($client_id, $pageId) ?? lpc_default_page_data();
            $wasPublished = ($page['status'] ?? '') === 'published';

            // Never silently blank (same rule as the AI-generation path) — a
            // cleared field falls back to whatever default/existing value
            // would otherwise apply, rather than storing an empty keyword.
            $primaryKeyword = trim($_POST['primary_keyword'] ?? '');
            if ($primaryKeyword === '') {
                [$_ctid, $_svcid] = array_pad(explode('__', $pageId, 2), 2, '');
                $_target  = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $_ctid);
                $_service = lpc_find_by_id($client['services'] ?? [], 'service_id', $_svcid);
                $primaryKeyword = ($_target && $_service)
                    ? lpc_default_primary_keyword($_service['label'], $_target['city'], $_target['state'], $_service['keyword_pattern'] ?? '')
                    : $page['seo']['primary_keyword'];
            }
            $secondaryKeywords = array_values(array_filter(array_map('trim', explode(',', $_POST['secondary_keywords'] ?? ''))));

            $page['page_id'] = $pageId;
            $page['seo'] = [
                'page_title'         => trim($_POST['page_title'] ?? ''),
                'meta_description'   => trim($_POST['meta_description'] ?? ''),
                'og_image'           => trim($_POST['og_image'] ?? ''),
                'primary_keyword'    => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
            ];

            // Shared helper: build a repeatable {title,text} item list from two
            // parallel POST arrays, dropping any row left blank.
            $buildItems = function ($titles, $texts) {
                $out = [];
                foreach ((array) $titles as $i => $title) {
                    $title = trim($title);
                    if ($title === '') continue;
                    $out[] = ['title' => $title, 'text' => trim($texts[$i] ?? '')];
                }
                return $out;
            };

            $highlights = $buildItems($_POST['highlights_title'] ?? [], $_POST['highlights_text'] ?? []);
            $steps      = $buildItems($_POST['steps_title'] ?? [], $_POST['steps_text'] ?? []);
            $services   = $buildItems($_POST['svc_title'] ?? [], $_POST['svc_text'] ?? []);

            $faqQs = $_POST['faq_q'] ?? [];
            $faqAs = $_POST['faq_a'] ?? [];
            $faqs  = [];
            foreach ($faqQs as $i => $q) {
                $q = trim($q);
                if ($q === '') continue;
                $faqs[] = ['q' => $q, 'a' => trim($faqAs[$i] ?? '')];
            }

            // related_links is deliberately not touched here — it's auto-computed
            // at publish time (lpc_compute_related_links()), never hand-authored.
            $existingLinks = [];
            // image_width/image_height aren't editable fields on this form (they're
            // a derived technical detail, not content) — carry forward whatever the
            // image-assignment flow last measured rather than dropping them every
            // time someone saves content, which would silently undo the real
            // <img width/height> attributes a Lighthouse audit flagged as missing.
            $existingImgW = null; $existingImgH = null;
            // service_area facts — same "not touched here" rule as related_links
            // above; carried forward only so the editor doesn't look blank
            // between a manual Save and the next Publish, which is what
            // actually recomputes these.
            $existingServiceAreaIntro = ''; $existingServiceAreaState = ''; $existingOfficeCity = ''; $existingNearbyCities = [];
            foreach ($page['sections'] as $s) {
                if (($s['type'] ?? '') === 'related_links') { $existingLinks = $s['links'] ?? []; }
                if (($s['type'] ?? '') === 'hero') { $existingImgW = $s['image_width'] ?? null; $existingImgH = $s['image_height'] ?? null; }
                if (($s['type'] ?? '') === 'service_area') {
                    $existingServiceAreaIntro = $s['intro'] ?? '';
                    $existingServiceAreaState = $s['state'] ?? '';
                    $existingOfficeCity       = $s['office_city'] ?? '';
                    $existingNearbyCities     = $s['nearby_cities'] ?? [];
                }
            }

            $page['sections'] = [
                ['type' => 'hero', 'headline' => trim($_POST['hero_headline'] ?? ''), 'subheading' => trim($_POST['hero_subheading'] ?? ''), 'image' => trim($_POST['hero_image'] ?? ''), 'image_alt' => trim($_POST['hero_image_alt'] ?? ''), 'image_width' => $existingImgW, 'image_height' => $existingImgH, 'cta_text' => trim($_POST['hero_cta_text'] ?? ''), 'phone' => trim($_POST['hero_phone'] ?? '')],
                ['type' => 'text', 'key' => 'city_intro', 'heading' => trim($_POST['city_intro_heading'] ?? ''), 'body' => trim($_POST['city_intro_body'] ?? '')],
                ['type' => 'highlights', 'key' => 'feature_columns_local', 'heading' => trim($_POST['highlights_heading'] ?? ''), 'items' => $highlights],
                ['type' => 'text', 'key' => 'local_relevance', 'heading' => trim($_POST['local_relevance_heading'] ?? ''), 'body' => trim($_POST['local_relevance_body'] ?? '')],
                ['type' => 'steps', 'key' => 'how_it_works', 'heading' => trim($_POST['steps_heading'] ?? ''), 'items' => $steps],
                ['type' => 'text', 'key' => 'seasonal_calendar', 'heading' => trim($_POST['seasonal_heading'] ?? ''), 'body' => trim($_POST['seasonal_body'] ?? '')],
                ['type' => 'text', 'key' => 'why_choose_us', 'heading' => trim($_POST['why_choose_us_heading'] ?? ''), 'body' => trim($_POST['why_choose_us_body'] ?? '')],
                ['type' => 'services_list', 'heading' => trim($_POST['services_heading'] ?? ''), 'items' => $services],
                ['type' => 'faq', 'heading' => trim($_POST['faq_heading'] ?? ''), 'items' => $faqs],
                ['type' => 'cta', 'heading' => trim($_POST['cta_heading'] ?? ''), 'body' => trim($_POST['cta_body'] ?? ''), 'phone' => trim($_POST['cta_phone'] ?? ''), 'button_text' => trim($_POST['cta_button_text'] ?? '')],
                // service_area, like related_links just below, is auto-computed
                // at publish time (lpc_compute_service_area()) — never hand-authored.
                ['type' => 'service_area', 'heading' => 'Our Service Area', 'intro' => $existingServiceAreaIntro, 'state' => $existingServiceAreaState, 'office_city' => $existingOfficeCity, 'nearby_cities' => $existingNearbyCities],
                ['type' => 'related_links', 'heading' => 'Related Services', 'links' => $existingLinks],
            ];

            $page['created_at'] = $page['created_at'] ?: gmdate('c');
            $page['updated_at'] = gmdate('c');
            // Editing content that's already live means what's published no longer
            // matches what's on disk — flip to Needs Update rather than silently
            // leaving the matrix showing "Published" for stale content. A page
            // that was only ever a draft stays a draft; nothing has gone live yet.
            $page['status'] = $wasPublished ? 'needs_update' : 'draft';

            lpc_save_page($client_id, $pageId, $page);
            header('Location: page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId) . '&msg=success:Saved');
            exit;
        } elseif ($action === 'publish') {
            // Single page_id (singular) = the editor's own Publish button, stays in
            // context by returning there. page_ids[] (plural) = the matrix's bulk
            // publish, returns to the matrix. Same action, two callers, two sensible
            // places to land afterward.
            if (!empty($_POST['page_id'])) {
                $pageId = trim($_POST['page_id']);
                lpc_publish_page($client_id, $pageId);
                header('Location: page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId) . '&msg=success:Published');
                exit;
            }
            $pageIds = $_POST['page_ids'] ?? [];
            if (!is_array($pageIds)) $pageIds = [$pageIds];
            foreach ($pageIds as $pageId) {
                lpc_publish_page($client_id, trim($pageId));
            }
        } elseif ($action === 'check_rank') {
            $result = lpc_refresh_rank_for_client($client_id);
            if (!$result['ok']) {
                $msg = 'error:' . urlencode($result['msg']);
            } elseif ($result['msg']) {
                $msg = 'warning:' . urlencode($result['msg']); // partial failure (e.g. rate-limited mid-batch)
            } else {
                $msg = 'success:Checked+' . $result['checked'] . '+page(s)';
            }
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=reporting&msg=' . $msg);
            exit;
        } elseif ($action === 'regenerate_ai') {
            $pageId = trim($_POST['page_id'] ?? '');
            $result = lpc_generate_page_content($client_id, $pageId);
            $msg = $result['ok'] ? 'success:Regenerated' : ('error:' . urlencode($result['msg']));
            header('Location: page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId) . '&msg=' . $msg);
            exit;
        } elseif ($action === 'toggle_lock') {
            $pageId = trim($_POST['page_id'] ?? '');
            $page = lpc_valid_page_id($pageId) ? lpc_load_page($client_id, $pageId) : null;
            if (!$page) {
                header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=error:Page+not+found');
                exit;
            }
            $page['locked'] = empty($page['locked']);
            $page['updated_at'] = gmdate('c');
            lpc_save_page($client_id, $pageId, $page);
            $msg = $page['locked'] ? 'success:Page+locked' : 'success:Page+unlocked';
            header('Location: page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId) . '&msg=' . $msg);
            exit;
        }

        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Page+updated');
        exit;

    default:
        header('Location: clients.php?msg=error:Unknown+section');
        exit;
}
