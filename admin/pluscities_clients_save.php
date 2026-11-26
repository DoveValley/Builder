<?php
/** admin/pluscities_clients_save.php — save handler for admin/pluscities_clients.php. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_clients.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_clients.php?msg=error:Invalid+request+token'); exit;
}

$actor  = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');
$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $businessName = trim((string) ($_POST['business_name'] ?? ''));
    $domain       = trim((string) ($_POST['domain'] ?? ''));
    $agencyId     = trim((string) ($_POST['agency_id'] ?? ''));
    $nicheId      = trim((string) ($_POST['niche_id'] ?? ''));
    $hubAddress   = trim((string) ($_POST['hub_address'] ?? ''));
    $hubPhone     = trim((string) ($_POST['hub_phone'] ?? ''));
    $firstCityId  = trim((string) ($_POST['first_city_id'] ?? ''));

    if ($businessName === '' || $domain === '' || $agencyId === '' || $nicheId === '' || $hubAddress === '' || $hubPhone === '' || $firstCityId === '') {
        header('Location: pluscities_clients.php?msg=error:Missing+required+fields'); exit;
    }
    // Agency scoping — an Agency Admin/Editor can only create a client under
    // their own agency, same choke point as everywhere else in this engine.
    if (lpc_current_actor_role() !== 'super_admin' && $agencyId !== lpc_current_actor_agency_id()) {
        header('Location: pluscities_clients.php?msg=error:Not+your+agency'); exit;
    }
    $niche = pluscities_niche_resolved($nicheId);
    if (!$niche) {
        header('Location: pluscities_clients.php?msg=error:Unknown+niche'); exit;
    }

    $clientId = slugify($businessName) ?: 'client';
    $base = $clientId; $i = 2;
    while (file_exists(PLUSCITIES_CLIENTS_DIR . $clientId . '.json')) { $clientId = $base . '-' . $i; $i++; }

    $licenses = [];
    if (trim((string) ($_POST['license_number'] ?? '')) !== '') {
        $licenses[] = [
            'number' => trim((string) $_POST['license_number']),
            'state'  => strtoupper(trim((string) ($_POST['license_state'] ?? ''))),
            'trade'  => trim((string) ($_POST['license_trade'] ?? '')),
        ];
    }

    $client = [
        'client_id'      => $clientId,
        'agency_id'      => $agencyId,
        'niche_id'       => $nicheId,
        'domain'         => $domain,
        'site_rev'       => 0,
        'plugin_rev'     => 0,
        'plugin_version' => '',
        'last_seen_at'   => '',
        'class_prefix'   => 'lpc-' . preg_replace('/[^a-z0-9]/', '', strtolower($clientId)),
        'radius_minutes' => (int) ($_POST['radius_minutes'] ?? 30),
        'identity'       => [
            'business_name' => $businessName,
            'hubs'          => [['address' => $hubAddress, 'phone' => $hubPhone]],
        ],
        'licenses'          => $licenses,
        'years_in_business' => (int) ($_POST['years_in_business'] ?? 0) ?: null,
        'hours' => [
            'mon_fri' => trim((string) ($_POST['hours_mon_fri'] ?? '')),
            'sat'     => trim((string) ($_POST['hours_sat'] ?? '')),
            'sun'     => trim((string) ($_POST['hours_sun'] ?? '')),
        ],
        'banned_phrases'  => [],
        'photos_by_city'  => [],
        'reviews_by_city' => [],
        'price_ranges'    => null,
        'billing'         => ['tier' => '50', 'custom_override' => null],
        'requires_client_approval' => false,
        'status'      => 'active',
        'canceled_at' => null,
        'services' => array_column($niche['services'] ?? [], 'slug'),
        'target_cities' => [[
            'city_id' => $firstCityId,
            'drive_minutes' => (int) ($_POST['first_city_drive_minutes'] ?? 15),
            'confirmed_by_client' => false,
        ]],
        'url_base_path' => trim((string) ($_POST['url_base_path'] ?? 'service-area')) ?: 'service-area',
        // accent/dark: resolved at render by pluscities_client_colors() —
        // override wins if set, else detected_*, else the fixed fallback.
        // Nothing here calls pluscities_detect_colors() yet — that only runs
        // from the branding screen's explicit "Detect Now" action, same
        // "never guess silently" rule as everything else in this engine.
        'branding' => [
            'logo_url'        => '',
            'brand_color'     => '',
            'accent_override' => null,
            'dark_override'   => null,
            'detected_accent' => null,
            'detected_dark'   => null,
            'colors_detected_at' => null,
        ],
        'images'   => [],
        // Both top_text and footer_text fall back to a runtime default when
        // blank (pluscities_render_hub_blocks()) — nothing to pre-fill here.
        'hub_content' => [
            'top_text'               => '',
            'footer_text'            => '',
            'hub_city_format'        => 'simple',
            'hub_city_format_custom' => '',
        ],
    ];
    pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);

    if (!is_dir(PLUSCITIES_CLIENTS_SECRETS_DIR)) mkdir(PLUSCITIES_CLIENTS_SECRETS_DIR, 0775, true);
    file_put_contents(PLUSCITIES_CLIENTS_SECRETS_DIR . $clientId . '.txt', bin2hex(random_bytes(20)));

    pluscities_log_event($actor, 'create_client', 'client:' . $clientId, '');

    // First page — real agency-chosen block picks, 2026-11-22 (previously a
    // hardcoded array_slice with no human choice at all — see
    // project_lpc_to_pluscities_migration in memory). Falls back to the old
    // hardcoded slice ONLY if the form submitted zero picks (e.g. JS
    // disabled) — client creation must never silently fail to produce a
    // working first page just because the picker didn't run client-side.
    $firstService = $niche['services'][0]['slug'] ?? null;
    if ($firstService) {
        $picks = pluscities_validate_block_picks(
            $niche,
            (array) ($_POST['city_block_picks'] ?? []),
            (array) ($_POST['service_block_picks'] ?? [])
        );
        $cityPicks    = $picks['city']    ?: array_slice($niche['city_blocks'] ?? [], 0, 6);
        $servicePicks = $picks['service'] ?: array_slice($niche['service_blocks'] ?? [], 0, 4);
        $result = pluscities_create_page($clientId, $firstCityId, $firstService, $cityPicks, $servicePicks, $actor);
        if (!$result['ok']) {
            header('Location: pluscities_clients.php?msg=warning:' . urlencode('Client created, but the first page failed: ' . $result['msg'])); exit;
        }
        header('Location: pluscities_page.php?page_id=' . urlencode($result['page_id']) . '&msg=success:' . urlencode('Client "' . $businessName . '" created')); exit;
    }

    header('Location: pluscities_clients.php?msg=success:' . urlencode('Client "' . $businessName . '" created (no services on this niche yet — add a page manually)'));
    exit;
}

if ($action === 'toggle_client_approval') {
    // Agency Admin territory, not Agency Editor — this is a trust-level
    // decision about how much the client is involved, same spirit as other
    // agency-level settings (editor_can_publish) living one role up from
    // Editor. pluscities_actor_can_access_client() still applies underneath,
    // so an Agency Admin can only flip this for their own agency's clients.
    if (!in_array(lpc_current_actor_role(), ['super_admin', 'agency_admin'], true)) {
        header('Location: pluscities_clients.php?msg=error:Not+authorized'); exit;
    }
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    if (!pluscities_actor_can_access_client($clientId)) {
        header('Location: pluscities_clients.php?msg=error:Not+authorized'); exit;
    }
    $client = pluscities_client_load($clientId);
    if (!$client) {
        header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
    }
    $client['requires_client_approval'] = empty($client['requires_client_approval']);
    pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);
    pluscities_log_event($actor, 'toggle_client_approval', 'client:' . $clientId, $client['requires_client_approval'] ? 'on' : 'off');
    $msg = $client['requires_client_approval'] ? 'success:Client+approval+turned+on' : 'success:Client+approval+turned+off';
    header('Location: pluscities_clients.php?msg=' . $msg);
    exit;
}

if ($action === 'cancel' || $action === 'reactivate') {
    // Agency Admin territory, same as toggle_client_approval — a real
    // business decision about an agency's own client relationship, not
    // something an Agency Editor should be able to trigger.
    if (!in_array(lpc_current_actor_role(), ['super_admin', 'agency_admin'], true)) {
        header('Location: pluscities_clients.php?msg=error:Not+authorized'); exit;
    }
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    if (!pluscities_actor_can_access_client($clientId)) {
        header('Location: pluscities_clients.php?msg=error:Not+authorized'); exit;
    }
    $client = pluscities_client_load($clientId);
    if (!$client) {
        header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
    }
    if ($action === 'cancel') {
        $client['status'] = 'canceled';
        $client['canceled_at'] = gmdate('c');
        $logAction = 'cancel_client';
        $msg = 'success:Client+cancelled+-+pages+will+redirect+to+their+homepage+after+the+grace+period';
    } else {
        $client['status'] = 'active';
        $client['canceled_at'] = null;
        $logAction = 'reactivate_client';
        $msg = 'success:Client+reactivated';
    }
    pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);
    pluscities_log_event($actor, $logAction, 'client:' . $clientId, '');
    header('Location: pluscities_clients.php?msg=' . $msg);
    exit;
}

if ($action === 'suspend_site' || $action === 'unsuspend_site') {
    // Platform Admin only, deliberately — unlike Cancel (an agency's own
    // business decision about its client), this is the platform's kill
    // switch for abuse/non-payment and has no grace period: pages go dark
    // immediately. Added 2026-11-24.
    if (lpc_current_actor_role() !== 'super_admin') {
        header('Location: pluscities_clients.php?msg=error:Not+authorized'); exit;
    }
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    $client = pluscities_client_load($clientId);
    if (!$client) {
        header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
    }
    if ($action === 'suspend_site') {
        $client['site_suspended'] = true;
        $client['site_suspended_at'] = gmdate('c');
        pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);

        $db = pluscities_db();
        $st = $db->prepare('SELECT page_id FROM pages WHERE client_id = ? AND status = "live"');
        $st->execute([$clientId]);
        $count = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pageId) {
            pluscities_unpublish_page($pageId, $actor);
            $count++;
        }
        pluscities_log_event($actor, 'suspend_site', 'client:' . $clientId, $count . ' page(s) unpublished');
        header('Location: pluscities_clients.php?msg=' . urlencode('success:Site suspended — ' . $count . ' page(s) unpublished')); exit;
    } else {
        $client['site_suspended'] = false;
        $client['site_suspended_at'] = null;
        pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);
        pluscities_log_event($actor, 'unsuspend_site', 'client:' . $clientId, '');
        header('Location: pluscities_clients.php?msg=' . urlencode('success:Site un-suspended — republish pages from the Pages screen to bring them back live')); exit;
    }
}

header('Location: pluscities_clients.php?msg=error:Unknown+action');
