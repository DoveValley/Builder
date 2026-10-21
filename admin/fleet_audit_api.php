<?php
/**
 * admin/fleet_audit_api.php — the endpoint for the two fleet-level audit panels on
 * the Site Manager: the fingerprint check and the index check.
 *
 * SEPARATE FROM multisite_api.php ON PURPOSE. These are fleet-wide and have nothing
 * to do with a batch; folding them into the batch API would have meant two more cases
 * in a 2,000-line switch, and every later reader having to work out that the "ms"
 * endpoints were no longer only about multisite. Both panels, both CLI tools and this
 * file all run the same scripts, so no answer can differ by where it was asked from.
 *
 * Same contract as multisite_api.php: logged-in session required, all POSTs CSRF
 * checked, JSON out.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch.php';   // ms_launch_job / ms_job_running

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Bad CSRF token.']); exit;
}

/** Each audit: where its job files live, its done-marker, and the script behind it. */
$AUDITS = [
    'fingerprint' => [
        'dir'      => BASE_DIR . '/admin/infra/state/fingerprint',
        'sentinel' => '__FINGERPRINT_DONE__',
        'script'   => BASE_DIR . '/tools/audit_fleet_fingerprints.php',
        'busy'     => 'The fingerprint check is already running — wait for it to finish.',
    ],
    'index' => [
        'dir'      => BASE_DIR . '/admin/infra/state/indexcheck',
        'sentinel' => '__INDEXCHECK_DONE__',
        'script'   => BASE_DIR . '/tools/audit_fleet_index.php',
        'busy'     => 'The index check is already running — wait for it to finish.',
    ],
    'rank' => [
        'dir'      => BASE_DIR . '/admin/infra/state/rankcheck',
        'sentinel' => '__RANKCHECK_DONE__',
        'script'   => BASE_DIR . '/tools/audit_fleet_rank.php',
        'busy'     => 'The rank sweep is already running — wait for it to finish.',
    ],
    'crawl' => [
        'dir'      => BASE_DIR . '/admin/infra/state/crawlcheck',
        'sentinel' => '__CRAWLCHECK_DONE__',
        'script'   => BASE_DIR . '/tools/collect_fleet_crawl.php',
        'busy'     => 'The crawl collection is already running — wait for it to finish.',
    ],
];

$action = (string) ($_GET['action'] ?? '');

/* The thresholds behind every audit box. Handled before the per-audit dispatch
 * because it belongs to all of them, not to one. */
if ($action === 'settings_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
    require_once __DIR__ . '/infra/lib/audit_settings.php';
    // Clamping lives in the module, not here, so the CLI and the form cannot end up
    // enforcing different limits.
    echo json_encode(['saved' => infra_audit_settings_save($_POST)]);
    exit;
}

$which  = (string) ($_REQUEST['audit'] ?? '');
$cfg    = $AUDITS[$which] ?? null;
if (!$cfg) { http_response_code(400); echo json_encode(['error' => 'Unknown audit.']); exit; }

/** Is a run of this audit in flight right now? */
$runningId = function () use ($cfg): ?string {
    foreach (glob($cfg['dir'] . '/*.out') ?: [] as $f) {
        // ms_job_running(), not a bare mtime: a killed job stops touching its .out file
        // but is in no sense still running, and would block every relaunch after it.
        if (strpos((string) @file_get_contents($f), $cfg['sentinel']) === false && ms_job_running($f)) {
            return basename($f, '.out');
        }
    }
    return null;
};

switch ($action) {
    case 'run':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        echo json_encode(ms_launch_job($cfg['dir'], $cfg['sentinel'], $cfg['busy'], $cfg['script'], []));
        break;

    case 'rows':
        // Rendered on demand — the per-domain tables are the bulk of the page weight,
        // and most visits never open the <details> that reveals them.
        switch ($which) {
            case 'crawl':
                require_once __DIR__ . '/infra/lib/crawl_audit.php';
                $html = crawl_audit_render_rows(crawl_audit_latest((int) ($_GET['days'] ?? 7))['rows']);
                break;
            case 'index':
                require_once __DIR__ . '/infra/lib/index_audit.php';
                $html = index_audit_render_rows(index_audit_latest()['rows']);
                break;
            case 'rank':
                require_once __DIR__ . '/infra/lib/rank_audit.php';
                require_once __DIR__ . '/infra/lib/audit_settings.php';
                $html = rank_audit_render_rows(rank_audit_latest()['domains'], (int) infra_audit_setting('too_new_days'));
                break;
            default:
                http_response_code(400); echo json_encode(['error' => 'No per-domain table for this audit.']); exit;
        }
        echo json_encode(['html' => $html]);
        break;

    case 'status':
        $run = $runningId();
        $out = ['running' => $run !== null, 'run_id' => $run];
        // The tail is what makes a two-minute wait legible instead of a spinner.
        if ($run !== null) {
            $log = (string) @file_get_contents($cfg['dir'] . '/' . $run . '.out');
            $out['log'] = implode("\n", array_slice(array_filter(explode("\n", $log)), -6));
        }
        echo json_encode($out);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
