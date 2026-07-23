<?php
/**
 * infra/actions/golive.php — Phase-3 go-live actions (CSRF-guarded, PRG).
 * action=schedule (assign daily batches) | release (switch one now) | refresh (poll CF for live).
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/golive.php';

$back = '../index.php?view=golive';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

switch ($_POST['action'] ?? '') {
    case 'schedule':
        $perDay = (int) ($_POST['per_day'] ?? 20);
        $start  = trim($_POST['start_date'] ?? '');
        $n = infra_golive_schedule($perDay, $start);
        infra_set_flash('ok', "Scheduled {$n} domain(s), {$perDay}/day from " . ($start ?: 'today') . '.');
        break;

    case 'release':
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $r = infra_golive_release($domain);
        $type = $r['ok'] ? 'ok' : ($r['manual'] ? 'warn' : 'err');
        infra_set_flash($type, "Release '{$domain}': {$r['message']}"
            . ($r['ns'] ? "\nNameservers: " . implode(', ', $r['ns']) : ''));
        break;

    case 'refresh':
        $n = infra_golive_refresh_live();
        infra_set_flash('ok', "Live-status refresh: {$n} domain(s) newly detected live.");
        break;

    default:
        infra_set_flash('err', 'Unknown action.');
}
header('Location: ' . $back);
