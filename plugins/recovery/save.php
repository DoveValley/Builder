<?php
/**
 * Recovery plugin — save handler.
 *
 * Auth + CSRF are already verified by admin/plugin_save.php before this runs, and
 * core functions (load_data, slugify, recovery_*) are loaded. This ONLY writes the
 * plugin's own files under sites/{site}/data/recovery/ — never any factory file.
 *
 * Actions: map (type→template), phasing, carrier_add/carrier_delete,
 *          state_add/state_delete, city_add/city_delete.
 */

require_once __DIR__ . '/data.php';

$base   = 'index.php?tab=plugins&plugin=recovery';
$action = $_POST['action'] ?? '';

$done = function (bool $ok, string $okMsg) use ($base) {
    $m = $ok ? 'success:' . rawurlencode($okMsg) : 'error:' . rawurlencode('Could not save');
    header('Location: ' . $base . '&msg=' . $m);
    exit;
};
$fail = function (string $msg) use ($base) {
    header('Location: ' . $base . '&msg=error:' . rawurlencode($msg));
    exit;
};
// slug from an explicit field, else derived from the name
$slugFrom = function (string $slugField, string $name): string {
    $s = trim($_POST[$slugField] ?? '');
    return slugify($s !== '' ? $s : $name);
};

switch ($action) {

    case 'map': {
        $cfg = recovery_config();
        foreach (array_keys(recovery_types()) as $t) {
            $cfg['templates'][$t] = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['tpl_' . $t] ?? '');
        }
        $done(recovery_save_config($cfg), 'Template mapping saved');
    }

    case 'phasing': {
        $cfg = recovery_config();
        $cfg['phasing']['publish_city_company'] = !empty($_POST['publish_city_company']);
        $cfg['phasing']['min_city_population']  = max(0, (int) ($_POST['min_city_population'] ?? 0));
        $done(recovery_save_config($cfg), 'Phasing settings saved');
    }

    case 'carrier_add': {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') $fail('Carrier name is required');
        $slug = $slugFrom('slug', $name);
        $rows = recovery_carriers();
        foreach ($rows as $r) if (($r['slug'] ?? '') === $slug) $fail("Carrier '$slug' already exists");
        $rows[] = ['slug' => $slug, 'name' => $name];
        $done(recovery_save_rows('carriers.json', $rows), "Added carrier '$name'");
    }
    case 'carrier_delete': {
        $slug = $_POST['slug'] ?? '';
        $rows = array_filter(recovery_carriers(), fn($r) => ($r['slug'] ?? '') !== $slug);
        $done(recovery_save_rows('carriers.json', $rows), 'Carrier removed');
    }

    case 'state_add': {
        $name = trim($_POST['name'] ?? '');
        $ss   = strtoupper(trim($_POST['ss'] ?? ''));
        if ($name === '' || strlen($ss) !== 2) $fail('State name + 2-letter abbreviation are required');
        $slug = $slugFrom('slug', $name);
        $rows = recovery_states();
        foreach ($rows as $r) if (($r['slug'] ?? '') === $slug) $fail("State '$slug' already exists");
        $rows[] = ['slug' => $slug, 'name' => $name, 'ss' => $ss];
        $done(recovery_save_rows('states.json', $rows), "Added state '$name'");
    }
    case 'state_delete': {
        $slug = $_POST['slug'] ?? '';
        $rows = array_filter(recovery_states(), fn($r) => ($r['slug'] ?? '') !== $slug);
        $done(recovery_save_rows('states.json', $rows), 'State removed');
    }

    case 'city_add': {
        $name  = trim($_POST['name'] ?? '');
        $state = trim($_POST['state'] ?? '');
        if ($name === '' || $state === '') $fail('City name + state are required');
        if (recovery_state($state) === null) $fail("Unknown state '$state'");
        $slug = $slugFrom('slug', $name);
        $rows = recovery_cities();
        foreach ($rows as $r) {
            if (($r['slug'] ?? '') === $slug && ($r['state'] ?? '') === $state) $fail("City '$slug' already exists in that state");
        }
        $row = ['slug' => $slug, 'name' => $name, 'state' => $state];
        if (($pop = (int) ($_POST['population'] ?? 0)) > 0) $row['population'] = $pop;
        $rows[] = $row;
        $done(recovery_save_rows('cities.json', $rows), "Added city '$name'");
    }
    case 'city_delete': {
        $slug  = $_POST['slug'] ?? '';
        $state = $_POST['state'] ?? '';
        $rows  = array_filter(recovery_cities(), fn($r) => !(($r['slug'] ?? '') === $slug && ($r['state'] ?? '') === $state));
        $done(recovery_save_rows('cities.json', $rows), 'City removed');
    }
    case 'deploy_save': {
        // Writes the site's own deploy.json (same schema the factory deploy reads) — no
        // factory file is modified. Mirrors admin/deploy_save.php's field handling.
        $deployFile = ACTIVE_SITE_DIR . '/deploy.json';
        $cfg = is_file($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
        $cfg['canonical_domain'] = rtrim(sanitize_url(trim($_POST['canonical_domain'] ?? '')), '/');
        $cfg['ftp_host']    = preg_replace('#^ftps?://#i', '', trim($_POST['ftp_host'] ?? ''));
        $cfg['ftp_port']    = max(1, min(65535, (int) ($_POST['ftp_port'] ?? 21)));
        $cfg['ftp_user']    = trim($_POST['ftp_user'] ?? '');
        if (trim($_POST['ftp_pass'] ?? '') !== '') $cfg['ftp_pass'] = trim($_POST['ftp_pass']);
        $cfg['ftp_path']    = '/' . ltrim(trim($_POST['ftp_path'] ?? '/'), '/');
        $cfg['ftp_passive'] = !empty($_POST['ftp_passive']);
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $done($json !== false && file_put_contents($deployFile, $json) !== false, 'Deploy settings saved');
    }

    case 'enrich_intersections': {
        // Spawn the intersection-AI runner in the BACKGROUND (300+ API calls can't run
        // inside a web request). Idempotent + single-writer, so we just guard against a
        // second concurrent run (two full-file writers would race).
        $already = trim((string) @shell_exec('pgrep -f enrich_intersections_cli.php 2>/dev/null'));
        if ($already !== '') $fail('An intersection AI run is already in progress — let it finish first.');

        $script = __DIR__ . '/enrich_intersections_cli.php';
        $php    = is_file(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : (is_file('/usr/bin/php') ? '/usr/bin/php' : 'php');
        $args   = ' --parallel=6';
        $st     = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['state'] ?? ''));
        if ($st !== '')             $args .= ' --state=' . escapeshellarg($st);
        if (!empty($_POST['refresh'])) $args .= ' --refresh';

        $cmd = 'nohup env ' . escapeshellarg('MULTISITE_SITE_BASE=' . ACTIVE_SITE_DIR)
             . ' ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . $args
             . ' > /tmp/enrich_intersections.log 2>&1 &';
        @exec($cmd);
        $done(true, 'Intersection AI generation started in the background — refresh this page to watch coverage climb.');
    }

    case 'build_deploy_bg': {
        // Background Build & Deploy for the panel progress meter. This is an AJAX
        // endpoint (fetch), so it responds JSON — not a redirect like $done/$fail.
        $jsonOut = function (array $payload) {
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        };

        // Guard against a second concurrent run. The "[d]" bracket trick keeps the
        // pattern from matching pgrep's own command line (a plain "deploy_cli.php"
        // pattern self-matches and reports a phantom "already in progress").
        $already = trim((string) @shell_exec('pgrep -f "[d]eploy_cli.php" 2>/dev/null'));
        if ($already !== '') $jsonOut(['success' => false, 'message' => 'A deploy is already in progress.']);

        // FTP must be configured, or the background run would just fail invisibly.
        $cfgFile = ACTIVE_SITE_DIR . '/deploy.json';
        $cfg = is_file($cfgFile) ? (json_decode(file_get_contents($cfgFile), true) ?: []) : [];
        if (empty($cfg['ftp_host']) || empty($cfg['ftp_user']) || empty($cfg['ftp_pass'])) {
            $jsonOut(['success' => false, 'message' => 'FTP settings are incomplete — enter host, username, and password first.']);
        }

        // Seed the status so the meter shows immediately (same shape deploy_cli.php writes).
        $now = time();
        @file_put_contents(ACTIVE_SITE_DIR . '/deploy_status.json', json_encode([
            'phase' => 'build', 'running' => true, 'ts' => $now, 'started' => $now,
            'msg' => 'Starting…', 'log' => [['t' => 'log', 'm' => 'Starting build…', 'ts' => $now]], 'issues' => [],
            'build_done' => 0, 'build_total' => 0, 'up_done' => 0, 'up_total' => 0,
        ]));

        // Spawn. Log to a site-dir file (www-data-owned) — a shared /tmp file can be
        // left root-owned by a prior CLI run, which makes the redirect (and the whole
        // spawn) fail silently as www-data.
        $script = __DIR__ . '/deploy_cli.php';
        $php    = is_file(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : (is_file('/usr/bin/php') ? '/usr/bin/php' : 'php');
        $args   = !empty($_POST['force_all']) ? ' --force' : '';
        $bgLog  = ACTIVE_SITE_DIR . '/deploy_cli.log';
        @exec('nohup env ' . escapeshellarg('MULTISITE_SITE_BASE=' . ACTIVE_SITE_DIR)
            . ' ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . $args
            . ' > ' . escapeshellarg($bgLog) . ' 2>&1 &');

        // Confirm it actually launched so the panel can report a hard failure instead
        // of spinning forever on the seed.
        usleep(400000);
        $launched = trim((string) @shell_exec('pgrep -f "[d]eploy_cli.php" 2>/dev/null')) !== '';
        $jsonOut(['success' => true, 'started' => $now, 'launched' => $launched]);
    }

    case 'build_only':
    case 'build_deploy': {
        @set_time_limit(0); @ignore_user_abort(true);
        require_once __DIR__ . '/build.php';
        require_once BASE_DIR . '/includes/static_build.php';
        if (function_exists('progress_set_sink')) progress_set_sink(function (...$a) {});

        $deployFile = ACTIVE_SITE_DIR . '/deploy.json';
        $cfg = is_file($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
        $out = ACTIVE_SITE_DIR . '/output/';
        $b   = recovery_full_build($out, $cfg['canonical_domain'] ?? 'https://r.q111.xyz');

        if ($action === 'build_only') $done(true, 'Built ' . $b['pages'] . ' pages to output/.');

        if (empty($cfg['ftp_host']) || empty($cfg['ftp_user']) || empty($cfg['ftp_pass'])) {
            $fail('Built ' . $b['pages'] . ' pages, but FTP settings are incomplete — enter host, username, and password first.');
        }
        require_once BASE_DIR . '/includes/multisite/deploy.php';
        $r = deploy_site($cfg, $out, ACTIVE_SITE_DIR . '/deploy_manifest.json', !empty($_POST['force_all']));
        if (($r['status'] ?? '') === 'fatal') {
            $fail('Built ' . $b['pages'] . ' pages. Deploy failed: ' . ($r['msg'] ?? 'error'));
        }
        $done(true, 'Built ' . $b['pages'] . ' pages · uploaded ' . ($r['uploaded'] ?? 0) . ' file(s)'
                    . (!empty($r['failed']) ? ', ' . $r['failed'] . ' failed' : ''));
    }
}

$fail('Unknown action');
