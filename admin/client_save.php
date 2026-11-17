<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/infra/lib/keywords.php'; // infra_kw_has_creds()/infra_kw_provider()/infra_kw_dfs_post() — the 'pages' section's check_rank action
require_once __DIR__ . '/../includes/multisite/geocode.php'; // ms_geocode_city() — the 'hubs' section's add action

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
// Every section/action here edits or publishes something — an End Client
// gets read-only reporting only, never a write path, regardless of which
// client their session is scoped to.
if (lpc_current_actor_role() === 'end_client') { header('Location: client_portal.php'); exit; }
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
        $client = lpc_load_client_for_current_actor($client_id);
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
        $client = lpc_load_client_for_current_actor($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['hub_action'] ?? '';

        if ($action === 'add') {
            // Same geocode + service_radius_miles the wizard's own hub-add
            // step already sets (client_wizard.php) — missing here meant a
            // hub added after onboarding could never get city suggestions
            // (lpc_city_candidates() returns [] with no geo, and casts a
            // missing radius to 0, filtering out every candidate even if it
            // didn't).
            $hubCity  = trim($_POST['city'] ?? '');
            $hubState = trim($_POST['state'] ?? '');
            $geo = ['lat' => null, 'lng' => null];
            $g = ms_geocode_city($hubCity, $hubState, $hubState);
            if (is_array($g) && isset($g['lat'], $g['lng'])) {
                $geo = ['lat' => (float) $g['lat'], 'lng' => (float) $g['lng']];
            }
            $client['hubs'][] = [
                'hub_id'  => $client_id . '-hub-' . (count($client['hubs']) + 1),
                'name'    => trim($_POST['name'] ?? ''),
                'address' => [
                    'street' => trim($_POST['street'] ?? ''),
                    'city'   => $hubCity,
                    'state'  => $hubState,
                    'zip'    => trim($_POST['zip'] ?? ''),
                ],
                'phone' => trim($_POST['phone'] ?? ''),
                'service_radius_miles' => (int) ($_POST['service_radius_miles'] ?? 45),
                'geo'   => $geo,
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
        $client = lpc_load_client_for_current_actor($client_id);
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
        $client = lpc_load_client_for_current_actor($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['branding'] = [
            'logo_url'      => sanitize_url(trim($_POST['logo_url'] ?? '')),
            'primary_color' => trim($_POST['primary_color'] ?? ''),
            'accent_color'  => trim($_POST['accent_color'] ?? ''),
            'font'          => trim($_POST['font'] ?? ''),
        ];
        lpc_save_client($client_id, $client);
        // Same reasoning as delivery_settings/toggle_active below: the plugin reads
        // colors/font/logo from the manifest, not from any per-page data, so without
        // this a branding change sits in client.json doing nothing until some
        // unrelated page happens to get republished — found live, no badge/error ever
        // surfaced this, just a silent no-op.
        lpc_rebuild_manifest($client_id);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=branding&msg=success:Branding+updated');
        exit;

    /* ---- CONTENT PROFILE ---- */
    case 'content_profile':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client_for_current_actor($client_id);
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
        $client = lpc_load_client_for_current_actor($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['reporting']['gsc_property'] = trim($_POST['gsc_property'] ?? '');
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=reporting&msg=success:Reporting+settings+saved');
        exit;

    /* ---- INTEGRATIONS (analytics / CDN — stored, inert until a real account exists) ---- */
    case 'integrations':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client_for_current_actor($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['integrations']['ga_measurement_id']  = trim($_POST['ga_measurement_id'] ?? '');
        $client['integrations']['cloudflare_account']  = trim($_POST['cloudflare_account'] ?? '');
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=success:Integrations+saved');
        exit;

    /* ---- DELIVERY SETTINGS (support phone/message) ---- */
    case 'delivery_settings':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client_for_current_actor($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['delivery']['support_phone']   = trim($_POST['support_phone'] ?? '');
        $client['delivery']['support_message'] = trim($_POST['support_message'] ?? '');
        $placement = $_POST['nav_link_placement'] ?? 'footer';
        $client['delivery']['nav_link_placement'] = in_array($placement, ['off', 'footer', 'nav'], true) ? $placement : 'footer';
        $client['delivery']['nav_link_label']     = trim($_POST['nav_link_label'] ?? '') ?: 'View Our Service Locations';
        // Existed as a data field long before it had a UI control — every
        // consumer (the manifest, the plugin's own transient TTL) already
        // read whatever was here, so this just makes it an actual setting
        // instead of something only editable by hand-editing client.json.
        $refreshMinutes = (int) ($_POST['refresh_interval_minutes'] ?? 360);
        $client['delivery']['refresh_interval_minutes'] = max(5, $refreshMinutes ?: 360);
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
        $client = lpc_load_client_for_current_actor($client_id);
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
        $client = lpc_load_client_for_current_actor($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        lpc_set_client_api_key($client_id, lpc_generate_api_key());
        lpc_ensure_delivery_key($client_id); // drops the old key's entry, adds the new one
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=delivery&msg=success:Key+regenerated');
        exit;

    /* ---- EMAIL TO CUSTOMER ---- */
    case 'email_to_customer':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client_for_current_actor($client_id);
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
        $client = lpc_load_client_for_current_actor($client_id);
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
        $client = lpc_load_client_for_current_actor($client_id);
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
        } elseif ($action === 'run_research') {
            // Real lookup, not a manual flag — see lpc_run_city_research()'s
            // own doc comment for why this replaced the old toggle_research
            // action. Free (local DB reads, no external API), so no cost
            // confirm() needed the way Check Demand/Climate Data have.
            $cityId = $_POST['city_id'] ?? '';
            $r = lpc_run_city_research($client_id, $cityId);
            $msg = $r['ok']
                ? ($r['found'] ? 'success:Research+complete' : 'warning:' . urlencode($r['msg']))
                : 'error:' . urlencode($r['msg']);
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=' . $msg);
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
        } elseif ($action === 'fetch_climate_data') {
            // Per CITY, not per page — the underlying NOAA chart is the same
            // for every service at a city (a client only has one niche, which
            // is what picks the chart), and the result is shared-cached by
            // city_id + chart id regardless of which page's editor triggered
            // it. A per-page button (the original placement) would have shown
            // a separate "Fetch" action on every service at the same city,
            // each doing the identical fetch into the identical cache entry —
            // real external cost/slowness with no benefit to repeating it.
            $cityId = $_POST['city_id'] ?? '';
            $nicheBrief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
            $result = lpc_fetch_climate_data($cityId, $nicheBrief['niche_id'] ?? '');
            $msg = $result['ok'] ? 'success:' . urlencode($result['msg']) : 'error:' . urlencode($result['msg']);
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
        $client = lpc_load_client_for_current_actor($client_id);
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
            $seasonal   = $buildItems($_POST['seasonal_title'] ?? [], $_POST['seasonal_text'] ?? []);

            $buildLines = fn($raw) => array_values(array_filter(array_map('trim', explode("\n", $raw ?? ''))));
            $comparisonItemsA = $buildLines($_POST['comparison_items_a'] ?? '');
            $comparisonItemsB = $buildLines($_POST['comparison_items_b'] ?? '');

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
            // area_map image — same "not touched here, carried forward" rule;
            // the image itself only ever comes from lpc_resolve_area_map() at
            // publish time, never hand-typed.
            $existingMapImage = ''; $existingMapAlt = ''; $existingMapW = null; $existingMapH = null;
            // trust_bar items — same "not touched here" rule; only ever comes
            // from the niche profile at publish time.
            $existingTrustBarItems = [];
            // safety_toolkit items — same "not touched here" rule; only ever
            // comes from plugins/safety_toolkit's niche data at publish time.
            $existingSafetyToolkitItems = [];
            // data_chart — same "not touched here" rule; only ever comes from
            // lpc_resolve_data_chart() at publish time, which itself only
            // ever reads whatever "Fetch Climate Data" already cached. A
            // missing capture here would silently drop this section (and the
            // real NOAA chart/table it carries) on the very next manual Save.
            $existingChartHeading = ''; $existingChartImage = ''; $existingChartAlt = '';
            $existingChartW = null; $existingChartH = null; $existingChartTableHtml = '';
            foreach ($page['sections'] as $s) {
                if (($s['type'] ?? '') === 'related_links') { $existingLinks = $s['links'] ?? []; }
                if (($s['type'] ?? '') === 'hero') { $existingImgW = $s['image_width'] ?? null; $existingImgH = $s['image_height'] ?? null; }
                if (($s['type'] ?? '') === 'service_area') {
                    $existingServiceAreaIntro = $s['intro'] ?? '';
                    $existingServiceAreaState = $s['state'] ?? '';
                    $existingOfficeCity       = $s['office_city'] ?? '';
                    $existingNearbyCities     = $s['nearby_cities'] ?? [];
                }
                if (($s['type'] ?? '') === 'area_map') {
                    $existingMapImage = $s['image'] ?? '';
                    $existingMapAlt   = $s['image_alt'] ?? '';
                    $existingMapW     = $s['image_width'] ?? null;
                    $existingMapH     = $s['image_height'] ?? null;
                }
                if (($s['type'] ?? '') === 'trust_bar') { $existingTrustBarItems = $s['items'] ?? []; }
                if (($s['type'] ?? '') === 'safety_toolkit') { $existingSafetyToolkitItems = $s['items'] ?? []; }
                if (($s['type'] ?? '') === 'data_chart') {
                    $existingChartHeading  = $s['heading'] ?? '';
                    $existingChartImage    = $s['image'] ?? '';
                    $existingChartAlt      = $s['image_alt'] ?? '';
                    $existingChartW        = $s['image_width'] ?? null;
                    $existingChartH        = $s['image_height'] ?? null;
                    $existingChartTableHtml = $s['table_html'] ?? '';
                }
            }

            $page['sections'] = [
                ['type' => 'hero', 'headline' => trim($_POST['hero_headline'] ?? ''), 'subheading' => trim($_POST['hero_subheading'] ?? ''), 'image' => trim($_POST['hero_image'] ?? ''), 'image_alt' => trim($_POST['hero_image_alt'] ?? ''), 'image_width' => $existingImgW, 'image_height' => $existingImgH, 'cta_text' => trim($_POST['hero_cta_text'] ?? ''), 'phone' => trim($_POST['hero_phone'] ?? '')],
                // trust_bar, like related_links/service_area/area_map below, is
                // auto-computed at publish time from the niche profile.
                ['type' => 'trust_bar', 'items' => $existingTrustBarItems],
                ['type' => 'text', 'key' => 'city_intro', 'heading' => trim($_POST['city_intro_heading'] ?? ''), 'body' => trim($_POST['city_intro_body'] ?? '')],
                ['type' => 'comparison', 'key' => 'comparison', 'heading' => trim($_POST['comparison_heading'] ?? ''), 'intro' => trim($_POST['comparison_intro'] ?? ''), 'label_a' => trim($_POST['comparison_label_a'] ?? ''), 'items_a' => $comparisonItemsA, 'label_b' => trim($_POST['comparison_label_b'] ?? ''), 'items_b' => $comparisonItemsB],
                ['type' => 'highlights', 'key' => 'feature_columns_local', 'heading' => trim($_POST['highlights_heading'] ?? ''), 'items' => $highlights],
                ['type' => 'text', 'key' => 'local_relevance', 'heading' => trim($_POST['local_relevance_heading'] ?? ''), 'body' => trim($_POST['local_relevance_body'] ?? '')],
                ['type' => 'steps', 'key' => 'how_it_works', 'heading' => trim($_POST['steps_heading'] ?? ''), 'items' => $steps],
                ['type' => 'highlights', 'key' => 'seasonal_calendar', 'heading' => trim($_POST['seasonal_heading'] ?? ''), 'items' => $seasonal],
                ['type' => 'text', 'key' => 'why_choose_us', 'heading' => trim($_POST['why_choose_us_heading'] ?? ''), 'body' => trim($_POST['why_choose_us_body'] ?? '')],
                ['type' => 'services_list', 'heading' => trim($_POST['services_heading'] ?? ''), 'items' => $services],
                ['type' => 'faq', 'heading' => trim($_POST['faq_heading'] ?? ''), 'items' => $faqs],
                // safety_toolkit, like trust_bar above, is auto-computed at
                // publish time from plugins/safety_toolkit's niche data.
                ['type' => 'safety_toolkit', 'heading' => 'Verify Your Provider', 'intro' => 'Before you choose a provider, these independent organizations can help you confirm licensing, industry standards, and track record.', 'items' => $existingSafetyToolkitItems],
                ['type' => 'cta', 'heading' => trim($_POST['cta_heading'] ?? ''), 'body' => trim($_POST['cta_body'] ?? ''), 'phone' => trim($_POST['cta_phone'] ?? ''), 'button_text' => trim($_POST['cta_button_text'] ?? '')],
                // area_map, like related_links/service_area below, is auto-computed
                // at publish time (lpc_resolve_area_map()) — heading resets to its
                // fixed default, the image carries forward unchanged.
                ['type' => 'area_map', 'heading' => 'Service Area Map', 'image' => $existingMapImage, 'image_alt' => $existingMapAlt, 'image_width' => $existingMapW, 'image_height' => $existingMapH],
                // data_chart, like area_map above, is auto-computed at
                // publish time (lpc_resolve_data_chart()) from whatever
                // "Fetch Climate Data" already cached for this city.
                ['type' => 'data_chart', 'heading' => $existingChartHeading, 'image' => $existingChartImage, 'image_alt' => $existingChartAlt, 'image_width' => $existingChartW, 'image_height' => $existingChartH, 'table_html' => $existingChartTableHtml],
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
            $gated = lpc_requires_approval_gate($client_id);
            if (!empty($_POST['page_id'])) {
                $pageId = trim($_POST['page_id']);
                lpc_publish_page_gated($client_id, $pageId);
                $msg = $gated ? 'success:Submitted+for+review' : 'success:Published';
                header('Location: page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId) . '&msg=' . $msg);
                exit;
            }
            $pageIds = $_POST['page_ids'] ?? [];
            if (!is_array($pageIds)) $pageIds = [$pageIds];
            $ok = 0; $failed = 0; $details = [];
            foreach ($pageIds as $pageId) {
                $pageId = trim($pageId);
                $r = lpc_publish_page_gated($client_id, $pageId);
                if ($r) { $ok++; $details[$pageId] = ['status' => 'ok', 'warnings' => []]; }
                else    { $failed++; $details[$pageId] = ['status' => 'warned', 'warnings' => ['Publish failed']]; }
            }
            // Real gap found live (2026-11-16): this branch never set its own
            // $msg/exit, so it fell through to the generic "Page updated"
            // redirect at the bottom of this case — true regardless of how
            // many pages were actually published, or whether any failed.
            $actionLabel = $gated ? 'Submitted for review' : 'Published';
            $_SESSION['lpc_bulk_result'][$client_id] = ['action' => $actionLabel, 'details' => $details];
            $msg = 'success:' . $ok . '+page(s)+' . ($gated ? 'submitted+for+review' : 'published') . ($failed ? ',+' . $failed . '+failed' : '');
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=' . $msg);
            exit;
        } elseif ($action === 'generate_one') {
            // AJAX, ONE page per call — replaces the old bulk_generate (one
            // big blocking multi-page POST). Real incident, 2026-11-16: with
            // real AI text regen + a real climate re-fetch checked across 8+
            // pages, that one request ran for several minutes with the
            // browser showing nothing at all until it finished — easily
            // long enough to hit a server/proxy timeout before completing,
            // which is indistinguishable from "the button does nothing."
            // The matrix's own JS now loops this one page at a time
            // (lpcGenerateSequential() in client.php), showing live progress
            // after each response instead of going silent for minutes.
            header('Content-Type: application/json');
            $pageId = trim($_POST['page_id'] ?? '');
            $opts = [
                'refresh_hero'    => isset($_POST['opt_refresh_hero']),
                'refetch_climate' => isset($_POST['opt_refetch_climate']),
                'regenerate_text' => isset($_POST['opt_regenerate_text']),
                'skip_locked'     => isset($_POST['opt_skip_locked']),
            ];
            $r = lpc_generate_and_verify_page($client_id, $pageId, $opts);
            echo json_encode(['page_id' => $pageId, 'result' => $r]);
            exit;
        } elseif ($action === 'bulk_research') {
            // "Research Selected" button — research is a per-CITY concept
            // (one city either has real Census data on file or it doesn't,
            // regardless of which service page you were looking at), but the
            // matrix's checkboxes are per PAGE — collapse to unique cities
            // first so checking all 3 services under one city doesn't run
            // the same free lookup three times.
            $pageIds = $_POST['page_ids'] ?? [];
            if (!is_array($pageIds)) $pageIds = [$pageIds];
            $cityIds = [];
            foreach ($pageIds as $pageId) {
                [$cid] = explode('__', trim($pageId), 2);
                if ($cid !== '') $cityIds[$cid] = true;
            }
            $found = 0; $empty = 0; $details = [];
            foreach (array_keys($cityIds) as $cityId) {
                $tc = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $cityId);
                $label = $tc ? ($tc['city'] . ', ' . $tc['state']) : $cityId;
                $r = lpc_run_city_research($client_id, $cityId);
                if ($r['found']) {
                    $found++;
                    $details[$label] = ['status' => 'ok', 'warnings' => []];
                } else {
                    $empty++;
                    $details[$label] = ['status' => 'warned', 'warnings' => [$r['msg'] ?: 'No data found']];
                }
            }
            $_SESSION['lpc_bulk_result'][$client_id] = ['action' => 'Researched', 'details' => $details];
            $msg = 'success:' . $found . '+cit(ies)+researched' . ($empty ? ',+' . $empty . '+with+no+data+found+(see+below)' : '');
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=' . $msg);
            exit;
        } elseif ($action === 'bulk_live_check') {
            // "Check Live Selected" — the one check that actually fetches the
            // real published page and compares it, instead of only verifying
            // our own data (see lpc_check_live_page()'s own doc comment for
            // the real incident this closes). Genuinely per-PAGE, unlike
            // Research/Climate, so no city-collapsing here.
            set_time_limit(300); // up to one real HTTP fetch per selected page
            $pageIds = $_POST['page_ids'] ?? [];
            if (!is_array($pageIds)) $pageIds = [$pageIds];
            $current = 0; $stale = 0; $failed = 0; $details = [];
            foreach ($pageIds as $pageId) {
                $pageId = trim($pageId);
                $r = lpc_check_live_page($client_id, $pageId);
                if (!$r['ok']) {
                    $failed++;
                    $details[$pageId] = ['status' => 'warned', 'warnings' => [$r['msg']]];
                } elseif ($r['stale']) {
                    $stale++;
                    $details[$pageId] = ['status' => 'warned', 'warnings' => $r['issues']];
                } else {
                    $current++;
                    $details[$pageId] = ['status' => 'ok', 'warnings' => []];
                }
            }
            $_SESSION['lpc_bulk_result'][$client_id] = ['action' => 'Live-checked', 'details' => $details];
            $msg = 'success:' . $current . '+page(s)+confirmed+current+live';
            if ($stale)  $msg .= ',+' . $stale . '+stale+on+the+live+site+(see+below)';
            if ($failed) $msg .= ',+' . $failed . '+could+not+be+checked';
            header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=' . $msg);
            exit;
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
