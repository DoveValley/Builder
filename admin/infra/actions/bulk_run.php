<?php
/**
 * infra/actions/bulk_run.php — streaming bulk provisioner.
 * POST (fetch) with a newline-separated domain list; streams a live progress
 * log line-by-line as each domain is provisioned via infra_provision_one().
 * CSRF-guarded. Self-contained streaming (no dependency on the factory SSE lib).
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/provision.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');   // don't let a proxy buffer the stream
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(0);

function bulk_emit(string $s): void { echo $s . "\n"; @flush(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    bulk_emit('ERROR: invalid request (bad CSRF token).');
    exit;
}

$optsBase = [
    'register' => !empty($_POST['do_register']),
    'years'    => (int) ($_POST['years'] ?? 1),
    'plesk'    => !empty($_POST['do_plesk']),
    'cf'       => !empty($_POST['do_cf']),
];

// '__auto__' = round-robin spread across registries (footprint); else fixed for all.
$srvSel = $_POST['server_id'] ?? '';
$cfSel  = $_POST['cf_account_id'] ?? '';
$regSel = $_POST['registrar'] ?? '';
$rrSrv = $srvSel === '__auto__';
$rrCf  = $cfSel  === '__auto__';
$rrReg = $regSel === '__auto__';
$serverList = array_values(infra_servers());
$cfList     = array_values(infra_cf_accounts());
$regList    = infra_registrar_names();

$fixedServer = null;  foreach ($serverList as $s) if (($s['id'] ?? '') === $srvSel) $fixedServer = $s;
$fixedAccount = null; foreach ($cfList as $a)     if (($a['id'] ?? '') === $cfSel)  $fixedAccount = $a;
$rr      = fn(array $list, int $i) => $list ? $list[$i % count($list)] : null;
$findSrv = function ($id) use ($serverList) { foreach ($serverList as $s) if (($s['id'] ?? '') === $id) return $s; return null; };
$findCf  = function ($id) use ($cfList)     { foreach ($cfList as $a)     if (($a['id'] ?? '') === $id) return $a; return null; };

// parse + dedupe + validate the domain list
$raw     = preg_split('/[\s,]+/', trim((string)($_POST['domains'] ?? '')));
$domains = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $raw)))));

if (!$domains) { bulk_emit('Nothing to do — no domains provided.'); exit; }
if (!$optsBase['register'] && !$optsBase['plesk'] && !$optsBase['cf']) { bulk_emit('Nothing selected (pick register / Plesk / Cloudflare).'); exit; }

$total = count($domains);
$mode = ($rrSrv || $rrCf || $rrReg) ? ' (round-robin: ' . implode('+', array_filter([$rrSrv?'server':'', $rrCf?'cf':'', $rrReg?'registrar':''])) . ')' : '';
bulk_emit("Bulk provisioning {$total} domain(s) — staged only, idempotent{$mode}.");
bulk_emit(str_repeat('─', 48));

$okCount = 0; $failCount = 0;
foreach ($domains as $i => $dom) {
    $n = $i + 1;
    bulk_emit("");
    bulk_emit("[{$n}/{$total}] {$dom}");
    if (!infra_valid_domain($dom)) {
        bulk_emit('  ✗ invalid domain — skipped');
        $failCount++;
        continue;
    }
    // Sticky: an already-provisioned domain keeps its recorded assignment (avoids
    // re-creating it on a different server). New domains advance the persistent counter.
    $ex = infra_state_get_domain($dom);
    if ($rrSrv) {
        $reuse  = ($ex['server_id'] ?? '') !== '' ? $findSrv($ex['server_id']) : null;
        $server = $reuse ?: $rr($serverList, infra_state_counter_next('rr_server'));
    } else { $server = $fixedServer; }
    if ($rrCf) {
        $reuse   = ($ex['cf_account_id'] ?? '') !== '' ? $findCf($ex['cf_account_id']) : null;
        $account = $reuse ?: $rr($cfList, infra_state_counter_next('rr_cf'));
    } else { $account = $fixedAccount; }
    if ($rrReg) {
        $regName = ($ex['registrar'] ?? '') !== '' ? $ex['registrar'] : (string) ($rr($regList, infra_state_counter_next('rr_registrar')) ?? '');
    } else { $regName = $regSel === '__auto__' ? '' : $regSel; }
    $opts = $optsBase + ['registrar' => $regName];
    if ($rrSrv || $rrCf || $rrReg) {
        bulk_emit('  [assigned] server=' . ($server['id'] ?? '—') . '  cf=' . ($account['id'] ?? '—') . '  registrar=' . ($regName ?: '—'));
    }
    $res = infra_provision_one($dom, $server, $account, $opts);
    foreach ($res['lines'] as $line) bulk_emit('  ' . $line);
    if ($res['ok']) { $okCount++; bulk_emit('  → staged ✓'); }
    else            { $failCount++; bulk_emit('  → partial/failed'); }
}

infra_cache_flush();   // created Plesk sites / CF zones — invalidate discovery cache
bulk_emit("");
bulk_emit(str_repeat('─', 48));
bulk_emit("DONE — {$okCount} staged, {$failCount} failed of {$total}.");
