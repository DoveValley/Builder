<?php
/**
 * Phase 2 — create the host area for every row in a batch, and store what comes back.
 *
 *   php multisite/create_hosts.php <master_id> --batch=bN [--force]
 *
 * For each target that has no credentials yet: create the vhost and its folder on the
 * chosen Hestia box, create an FTP account scoped to that folder alone, and write the
 * credentials back into the batch's params.csv so the run can deploy with them.
 *
 * WHY IT WRITES BACK. Provisioning stores credentials in fleet.db; the batch runner
 * reads them from params.csv. Nothing joined those two halves, so a batch would build
 * fifty sites and log "No FTP creds in row — skipping deploy" for every one. This is
 * that join.
 *
 * WHICH BOX EACH ROW GETS comes from the batch's own plan (servers.json, set on the
 * batch page): each entry takes `count` rows, and 0 means "whatever is left".
 *
 * THE RESTART IS ONCE PER BOX, at the end. nginx does not notice new vhosts until it
 * restarts, and until it does it serves Hestia's default page with a 200 — so every
 * step reports success and the sites are not live. Restarting per domain would mean
 * fifty interruptions to achieve what one achieves.
 *
 * Safe to run twice: rows that already have credentials are skipped, and
 * hestia_site_exists() guards the creation itself.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "create_hosts.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../admin/infra/lib/provision.php';   // the Hestia + fleet-state module

$args     = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId  = '';
$force    = in_array('--force', $args, true);
foreach ($args as $a) if (str_starts_with($a, '--batch=')) $batchId = substr($a, 8);

if ($masterId === '' || $batchId === '') { fwrite(STDERR, "usage: create_hosts.php <master_id> --batch=bN [--force]\n"); exit(2); }
if (!ms_batch_exists($masterId, $batchId)) { fwrite(STDERR, "No such batch: {$masterId}/{$batchId}\n"); exit(2); }

$csvPath = ms_batch_dir($masterId, $batchId) . '/params.csv';
if (!is_file($csvPath)) { fwrite(STDERR, "This batch has no target list yet.\n"); exit(2); }

$parsed = ms_parse_csv($csvPath);
$rows   = $parsed['rows'];
$header = $parsed['header'];
if (!$rows) { fwrite(STDERR, "The target list is empty.\n"); exit(2); }

/* Tag every domain in the target list into the Infra console's own registry under
 * this batch, so the go-live pipeline (zone/DNS/live) has something to show for it —
 * unconditionally, not just rows that get a host created THIS run, since a row
 * already holding credentials from a prior run is just as much a member of this
 * batch and must not need --force to become visible there. infra_state_upsert_domain()
 * creates the row if this domain has never been tracked at all, so this works for
 * ad-hoc domains too, not just ones bought through D.Buy. */
foreach ($rows as $r) {
    $d = strtolower(trim((string) ($r['domain'] ?? '')));
    if ($d !== '') infra_state_upsert_domain(['domain' => $d, 'batch' => $masterId . '/' . $batchId]);
}

/* The plan: which boxes, how many each. */
$plan = ms_batch_servers($masterId, $batchId);
if (!$plan) { fwrite(STDERR, "No deployment servers picked for this batch — choose them first.\n"); exit(2); }

$fleet = [];
foreach (infra_hestia_servers() as $s) $fleet[$s['id'] ?? ''] = $s;

/* Which rows still need a host. A row that already has credentials is left alone
   unless --force, because re-creating it would issue a second FTP account for a site
   that already has a working one. */
$todo = [];
foreach ($rows as $i => $r) {
    $domain = strtolower(trim((string) ($r['domain'] ?? '')));
    if ($domain === '') continue;
    $has = trim((string) ($r['ftp_host'] ?? '')) !== '' && trim((string) ($r['ftp_user'] ?? '')) !== '';
    if ($has && !$force) continue;
    $todo[$i] = $domain;
}

printf("Batch %s/%s — %d target(s), %d needing a host.\n", $masterId, $batchId, count($rows), count($todo));
if (!$todo) {
    echo "Nothing to do — every row already has credentials.\n";
    // Still worth telling the go-live pipeline what is already true, so a batch that
    // has always had its hosts (nothing new to create) is not the one case where its
    // assign/host cells never get checked at all.
    require_once __DIR__ . '/../admin/infra/lib/pipeline.php';
    infra_pipeline_refresh('assign', $masterId . '/' . $batchId);
    infra_pipeline_refresh('host', $masterId . '/' . $batchId);
    exit(0);
}

/* Hand rows out to boxes, in plan order. count 0 = take whatever is left. */
$queue      = array_keys($todo);
$assignment = [];          // row index => server
$unplaced   = [];
foreach ($plan as $p) {
    $srv = $fleet[$p['server_id']] ?? null;
    if (!$srv) { printf("  ! %s is in the plan but not in the console — skipped\n", $p['label'] ?? $p['server_id']); continue; }
    $take = (int) ($p['count'] ?? 0);
    $n    = $take > 0 ? $take : count($queue);
    for ($k = 0; $k < $n && $queue; $k++) {
        $assignment[array_shift($queue)] = $srv;
    }
}
$unplaced = $queue;

foreach ($plan as $p) {
    $c = count(array_filter($assignment, fn($s) => ($s['id'] ?? '') === $p['server_id']));
    printf("  %-10s %s\n", $p['label'] ?? $p['server_id'], $c . ' site' . ($c === 1 ? '' : 's'));
}
if ($unplaced) {
    printf("  ! %d row(s) have nowhere to go — the plan does not allocate them. They are left untouched.\n", count($unplaced));
}
echo str_repeat('-', 52) . "\n";

/* Create. restart=false throughout; each touched box is restarted once at the end. */
$touched = []; $ok = 0; $fail = 0;
foreach ($assignment as $i => $srv) {
    $domain = $todo[$i];
    $res = infra_provision_one($domain, $srv, null, ['site' => true, 'cf' => false, 'restart' => false]);
    $rec = infra_state_get_domain($domain);
    $user = (string) ($rec['ftp_user'] ?? '');
    $pass = (string) ($rec['ftp_pass'] ?? '');

    if ($res['ok'] && $user !== '' && $pass !== '') {
        // Everything the deploy needs, stored on the row itself. ftp_path matters:
        // on Hestia the FTP login lands IN the docroot, while shared hosting puts you
        // above it — writing the real path here means the deploy never has to guess.
        $rows[$i]['ftp_host'] = (string) ($srv['host'] ?? '');
        $rows[$i]['ftp_user'] = $user;
        $rows[$i]['ftp_pass'] = $pass;
        $rows[$i]['ftp_path'] = '/home/' . $user;
        $touched[$srv['id'] ?? ''] = $srv;
        $ok++;
        printf("  ✓ %-34s %-8s ftp %s\n", $domain, $srv['label'] ?? '', $user);
    } else {
        $fail++;
        $why = '';
        foreach ($res['lines'] as $l) if (str_contains($l, '✗')) $why = trim(str_replace('Host: ✗', '', $l));
        printf("  ✗ %-34s %-8s %s\n", $domain, $srv['label'] ?? '', $why ?: 'failed');
    }
}

/* Save the target list before restarting: the credentials are the valuable part and
   must survive even if a restart fails. */
if ($ok > 0) {
    foreach (['ftp_host', 'ftp_user', 'ftp_pass', 'ftp_path'] as $c) {
        if (!in_array($c, $header, true)) $header[] = $c;
    }
    ms_write_csv($csvPath, $header, $rows);
    @chown($csvPath, 'www-data'); @chgrp($csvPath, 'www-data');
    printf("\nTarget list updated — %d row(s) now carry credentials.\n", $ok);
}

/* Tell the go-live pipeline what this run just confirmed. Its own prerequisite
 * checks trust ONLY a stored, checked cell — never an inferred one (see
 * infra_pipeline_do()'s "and has never been checked" refusal) — so without this, a
 * domain provisioned entirely through the multisite wizard would refuse its first
 * "Create zone" press with "blocked: Box is not done yet", even though the box is
 * plainly assigned. One call per step for the WHOLE batch, not per domain: both
 * checks are priced per BOX, not per domain (infra_host_domain_index() sweeps the
 * fleet once and infra_pipeline_refresh() reuses it for every row it is asked
 * about), so this costs the same whether the batch is 1 domain or 50. */
require_once __DIR__ . '/../admin/infra/lib/pipeline.php';
$golivetag = $masterId . '/' . $batchId;
infra_pipeline_refresh('assign', $golivetag);
infra_pipeline_refresh('host', $golivetag);

/* One restart per box, at the end. */
if ($touched) {
    echo "\nRestarting the web server so the new sites are served:\n";
    foreach ($touched as $srv) {
        $w = hestia_restart_web($srv);
        printf("  %-10s %s\n", $srv['label'] ?? $srv['id'],
            $w['ok'] ? '✓ restarted' : '✗ ' . $w['message'] . ' — sites exist but serve the default page until this succeeds');
        if (!$w['ok']) $fail++;
    }
}

echo "\n" . str_repeat('=', 52) . "\n";
printf("DONE — %d created, %d failed%s.\n", $ok, $fail, $unplaced ? ', ' . count($unplaced) . ' unallocated' : '');
exit($fail > 0 ? 1 : 0);
