<?php
/**
 * infra/actions/health.php — the three sweeps behind the DNS / Health tab.
 *
 * Nothing on that tab goes to the network on its own; these are what do. POST only,
 * CSRF-guarded, session released after the token check because a nameserver sweep over
 * two hundred domains would otherwise queue every other click in the console behind it.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/health.php';

$back = '../index.php?view=health';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back);
    exit;
}
infra_session_release();
set_time_limit(0);

$arg = strtolower(trim((string) ($_POST['arg'] ?? '')));

switch ($_POST['action'] ?? '') {

    case 'read_registrar':
        $r = infra_health_registrar_sync($arg);
        if (!$r['ok']) { infra_set_flash('err', $arg . ': ' . $r['msg']); break; }
        // A CHANGE of answer is the news. "36 unknown are still unknown" is noise;
        // "18 you believed were renewing are not" is the reason to have pressed it.
        $n = count($r['changed']);
        $msg = $arg . ': read ' . $r['read'] . ' domain(s) from the registrar, '
             . $r['updated'] . ' of ours updated';
        if ($n) {
            $sample = array_slice($r['changed'], 0, 4, true);
            $bits = [];
            foreach ($sample as $d => $c) $bits[] = $d . ' ' . $c;
            $msg .= ' — ' . $n . ' changed: ' . implode(', ', $bits) . ($n > 4 ? ', …' : '');
        } else {
            $msg .= ' — nothing had changed.';
        }
        infra_set_flash($n ? 'warn' : 'ok', $msg);
        break;

    case 'sweep_ns':
        // One DNS query per owned domain. Cheap, but not free, and never on page load.
        $n = 0; $bad = 0;
        foreach (infra_health_rows() as $row) {
            $r = infra_health_ns_run($row['domain']);
            $n++;
            if (!empty($r['error'])) $bad++;
        }
        infra_set_flash($bad ? 'warn' : 'ok',
            'Looked up ' . $n . ' domain(s) in public DNS'
            . ($bad ? ' — ' . $bad . ' do not resolve at all.' : '.'));
        break;

    case 'sweep_zones':
        // Two calls per zone. Only domains that HAVE a zone are asked; the rest have
        // nothing to inspect and asking about them would just be slower.
        $accts = [];
        foreach (infra_cf_accounts() as $a) $accts[(string) ($a['id'] ?? '')] = $a;
        $n = 0;
        foreach (infra_health_rows() as $row) {
            $z = $row['zone'] ?? null;
            if (!$z || empty($accts[$z['account_id']])) continue;
            infra_health_zone_run($accts[$z['account_id']], (string) $z['zone_id']);
            $n++;
        }
        infra_set_flash('ok', 'Inspected ' . $n . ' zone(s).');
        break;

    default:
        infra_set_flash('err', 'Unknown action.');
}

header('Location: ' . $back);
