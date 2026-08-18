<?php
/**
 * infra/actions/pipeline_refresh.php — sweep ONE column of the go-live grid.
 *
 * Per column rather than per page, and never on page load: the grid renders stored
 * answers instantly, and this is the only thing that goes to the network. Per column
 * rather than per cell because that is the shape the APIs have — the host and upload
 * answers come one box at a time, the zone and DNS answers one Cloudflare account at
 * a time, so sixty-five rows cost a handful of calls rather than sixty-five.
 *
 * POST only, CSRF-guarded, and the session lock is dropped as soon as the token has
 * been checked — a sweep of the Live column is sixty-five outbound requests, and
 * holding the session would queue up every other click in the console behind it.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/pipeline.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ../index.php?view=bulk');
    exit;
}

$step  = (string) ($_POST['step'] ?? '');
$batch = (string) ($_POST['batch'] ?? '');
$back  = '../index.php?view=bulk' . ($batch !== '' ? '&batch=' . urlencode($batch) : '');

$label = '';
foreach (infra_pipeline_steps() as $s) if ($s['key'] === $step) $label = $s['label'];
if ($label === '') {
    infra_set_flash('err', 'No such step.');
    header('Location: ' . $back);
    exit;
}

// Auth and CSRF are settled; let go of the session so this does not block the console.
// Nothing below writes to $_SESSION — a write after the release vanishes silently.
infra_session_release();
set_time_limit(0);

$r = infra_pipeline_refresh($step, $batch);

// Say what was found, in the words the column uses. "Checked 65" alone does not tell
// you whether anything moved.
$msg = $label . ': checked ' . $r['checked'] . ' domain' . ($r['checked'] === 1 ? '' : 's') . ' — '
     . $r['ok'] . ' done, ' . $r['todo'] . ' not yet'
     . ($r['fail'] > 0 ? ', ' . $r['fail'] . ' failed' : '') . '.';

infra_set_flash($r['fail'] > 0 ? 'warn' : 'ok', $msg);
header('Location: ' . $back);
