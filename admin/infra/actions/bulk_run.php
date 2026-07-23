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

$opts = [
    'register'  => !empty($_POST['do_register']),
    'registrar' => $_POST['registrar'] ?? '',
    'years'     => (int) ($_POST['years'] ?? 1),
    'plesk'     => !empty($_POST['do_plesk']),
    'cf'        => !empty($_POST['do_cf']),
];

$server = null;  foreach (infra_servers() as $s)    if (($s['id'] ?? '') === ($_POST['server_id'] ?? ''))    $server = $s;
$account = null; foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === ($_POST['cf_account_id'] ?? '')) $account = $a;

// parse + dedupe + validate the domain list
$raw     = preg_split('/[\s,]+/', trim((string)($_POST['domains'] ?? '')));
$domains = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $raw)))));

if (!$domains) { bulk_emit('Nothing to do — no domains provided.'); exit; }
if (!$opts['register'] && !$opts['plesk'] && !$opts['cf']) { bulk_emit('Nothing selected (pick register / Plesk / Cloudflare).'); exit; }

$total = count($domains);
bulk_emit("Bulk provisioning {$total} domain(s) — staged only, idempotent.");
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
    $res = infra_provision_one($dom, $server, $account, $opts);
    foreach ($res['lines'] as $line) bulk_emit('  ' . $line);
    if ($res['ok']) { $okCount++; bulk_emit('  → staged ✓'); }
    else            { $failCount++; bulk_emit('  → partial/failed'); }
}

bulk_emit("");
bulk_emit(str_repeat('─', 48));
bulk_emit("DONE — {$okCount} staged, {$failCount} failed of {$total}.");
