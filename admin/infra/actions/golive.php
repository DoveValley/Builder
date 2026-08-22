<?php
/**
 * infra/actions/golive.php — Phase-3 go-live actions (CSRF-guarded, PRG).
 * action=schedule (assign daily batches) | release (switch one now) | refresh (poll CF for live).
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/golive.php';
require_once __DIR__ . '/../lib/pipeline.php';

$back = '../index.php?view=golive';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();

switch ($_POST['action'] ?? '') {
    case 'schedule':
        $perDay = (int) ($_POST['per_day'] ?? 20);
        $start  = trim($_POST['start_date'] ?? '');
        // Gated: a date scheduled for a domain the release will refuse just moves the
        // failure to a morning when nobody is watching.
        $r = infra_golive_schedule($perDay, $start, ['gate' => true]);
        infra_set_flash($r['skipped'] > 0 ? 'warn' : 'ok',
            "Scheduled {$r['scheduled']} domain(s), {$perDay}/day from " . ($start ?: 'today') . '.'
            . ($r['skipped'] > 0 ? " {$r['skipped']} skipped — nothing is uploaded to them yet." : ''));
        break;

    case 'release':
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        // Overriding the empty-site gate has to be an explicit tick, never a default.
        $force = !empty($_POST['force']);
        // Locked the same way infra_pipeline_do() locks the 'golive' step — this is
        // the OTHER caller of infra_golive_release() and used to race it, including
        // two concurrent registrar nameserver-switch calls for the same domain.
        $r = infra_pipeline_lock($domain, 'golive', fn() => infra_golive_release($domain, $force));
        $type = $r['ok'] ? 'ok' : ($r['manual'] ? 'warn' : 'err');
        infra_set_flash($type, "Release '{$domain}': {$r['message']}"
            . ($r['ns'] ? "\nNameservers: " . implode(', ', $r['ns']) : ''));
        break;

    case 'refresh':
        infra_cache_force();                 // re-sweep Cloudflare live, don't use cached zones
        $n = infra_golive_refresh_live();
        infra_set_flash('ok', "Live-status refresh: {$n} domain(s) newly detected live.");
        break;

    default:
        infra_set_flash('err', 'Unknown action.');
}
header('Location: ' . $back);
