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

        $label = trim($_POST['service_label'] ?? '');
        if ($label !== '') {
            $serviceId = slugify($label);
            $exists = false;
            foreach ($client['services'] as $s) { if ($s['service_id'] === $serviceId) $exists = true; }
            if (!$exists) {
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
            }
        }
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Service+added');
        exit;

    /* ---- TARGET CITIES (bulk add from the suggestion table / remove) ---- */
    case 'target_cities':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['tc_action'] ?? '';

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
                }
            }
        } elseif ($action === 'remove') {
            $cityId = $_POST['city_id'] ?? '';
            $client['target_cities'] = array_values(array_filter($client['target_cities'], fn($tc) => $tc['city_id'] !== $cityId));
        }

        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Target+cities+updated');
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

            $page['page_id'] = $pageId;
            $page['seo'] = [
                'page_title'       => trim($_POST['page_title'] ?? ''),
                'meta_description' => trim($_POST['meta_description'] ?? ''),
                'og_image'         => trim($_POST['og_image'] ?? ''),
            ];

            $svcTitles = $_POST['svc_title'] ?? [];
            $svcTexts  = $_POST['svc_text'] ?? [];
            $services  = [];
            foreach ($svcTitles as $i => $title) {
                $title = trim($title);
                if ($title === '') continue;
                $services[] = ['title' => $title, 'text' => trim($svcTexts[$i] ?? '')];
            }

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
            foreach ($page['sections'] as $s) {
                if (($s['type'] ?? '') === 'related_links') { $existingLinks = $s['links'] ?? []; break; }
            }

            $page['sections'] = [
                ['type' => 'hero', 'headline' => trim($_POST['hero_headline'] ?? ''), 'subheading' => trim($_POST['hero_subheading'] ?? ''), 'image' => trim($_POST['hero_image'] ?? ''), 'cta_text' => trim($_POST['hero_cta_text'] ?? ''), 'phone' => trim($_POST['hero_phone'] ?? '')],
                ['type' => 'text', 'heading' => trim($_POST['text_heading'] ?? ''), 'body' => trim($_POST['text_body'] ?? '')],
                ['type' => 'services_list', 'heading' => trim($_POST['services_heading'] ?? ''), 'items' => $services],
                ['type' => 'faq', 'heading' => trim($_POST['faq_heading'] ?? ''), 'items' => $faqs],
                ['type' => 'cta', 'heading' => trim($_POST['cta_heading'] ?? ''), 'body' => trim($_POST['cta_body'] ?? ''), 'phone' => trim($_POST['cta_phone'] ?? ''), 'button_text' => trim($_POST['cta_button_text'] ?? '')],
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
        }

        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Page+updated');
        exit;

    default:
        header('Location: clients.php?msg=error:Unknown+section');
        exit;
}
