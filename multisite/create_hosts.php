<?php
/**
 * Phase 2 — create the host area for every row in a batch, and store what comes back.
 *
 *   php multisite/create_hosts.php <master_id> --batch=bN [--force] [--only=DOMAIN[,DOMAIN...]]
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
$only     = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))  $only    = strtolower(substr($a, 7));
}
$onlyList = $only !== '' ? array_values(array_filter(array_map('trim', explode(',', $only)))) : [];

if ($masterId === '' || $batchId === '') { fwrite(STDERR, "usage: create_hosts.php <master_id> --batch=bN [--force] [--only=DOMAIN[,DOMAIN...]]\n"); exit(2); }
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
   that already has a working one. A domain fleet.db already confirms LIVE is left
   alone even under --force unless named explicitly in --only — same rule as
   Generate/Upload (see run_campaign.php/upload_sites.php): re-provisioning a live
   site's host resets its FTP account and can interrupt a site that's actually
   serving traffic, and that should never happen just because it shared a batch with
   new rows someone meant to force. */
$todo = []; $liveSkipped = 0;
foreach ($rows as $i => $r) {
    $domain = strtolower(trim((string) ($r['domain'] ?? '')));
    if ($domain === '') continue;
    if ($onlyList && !in_array($domain, $onlyList, true)) continue;
    $has = trim((string) ($r['ftp_host'] ?? '')) !== '' && trim((string) ($r['ftp_user'] ?? '')) !== '';
    if ($has && !$force) continue;
    // A domain not named in --only is left alone if fleet.db confirms it's LIVE, even
    // under --force — same rule as Generate/Upload. Only checked once we already know
    // this row would otherwise be touched (has creds + force, or needs one at all).
    if (!$onlyList) {
        $rec = infra_state_get_domain($domain);
        if ($rec && ($rec['status'] ?? '') === 'live') {
            echo "SKIP (already live) {$domain} — name it in --only to force-recreate its host anyway\n";
            $liveSkipped++;
            continue;
        }
    }
    $todo[$i] = $domain;
}

printf("Batch %s/%s — %d target(s), %d needing a host%s.\n", $masterId, $batchId, count($rows), count($todo),
    $liveSkipped > 0 ? ", {$liveSkipped} already-live skipped" : '');
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

/* Hand rows out to boxes. count 0 = take whatever is left.
 *
 * Quotas are for the WHOLE batch (every row in params.csv), not just today's queue —
 * this run may be one of several (test 2 domains, then a few more, then the rest),
 * and a box's quota has to account for rows it ALREADY got from an earlier run, or
 * every separate run hands that box up to its full count again and the early boxes
 * in plan order end up overloaded. "Already there" is counted fresh from params.csv
 * each run (matching each row's real ftp_host against this box), not from any
 * in-memory counter — so it's correct no matter how many times, or in what order,
 * this script has been run before.
 *
 * WHICH BOX each row gets is randomized, not tied to its position in the CSV — see
 * the round-by-round assignment below, which also guarantees no box repeats twice
 * in a row across the batch's own list order. */
$totalTargets = count($rows);
$haveByServerId = [];   // server_id => how many of ALL this batch's rows already point there
foreach ($rows as $r) {
    $h = trim((string) ($r['ftp_host'] ?? ''));
    if ($h === '') continue;
    foreach ($fleet as $sid => $srv) {
        if (($srv['host'] ?? '') === $h) { $haveByServerId[$sid] = ($haveByServerId[$sid] ?? 0) + 1; break; }
    }
}

$quota = [];       // server_id => total quota for the whole batch
$explicitSum = 0; $zeroBoxIds = [];
foreach ($plan as $p) {
    $srv = $fleet[$p['server_id']] ?? null;
    if (!$srv) { printf("  ! %s is in the plan but not in the console — skipped\n", $p['label'] ?? $p['server_id']); continue; }
    $take = (int) ($p['count'] ?? 0);
    if ($take > 0) { $quota[$p['server_id']] = $take; $explicitSum += $take; }
    else            { $zeroBoxIds[] = $p['server_id']; }
}
$remainder = max(0, $totalTargets - $explicitSum);
$zn = count($zeroBoxIds);
$zeroBase = $zn ? intdiv($remainder, $zn) : 0;
$zeroExtra = $zn ? $remainder % $zn : 0;
foreach ($zeroBoxIds as $k => $sid) $quota[$sid] = $zeroBase + ($k < $zeroExtra ? 1 : 0);

// This run's real need per box = quota minus what it already has. Never negative —
// a box that's already over its quota (plan shrunk, or it was hand-assigned extra
// rows) just gets no more, it is never "owed" a correction.
//
// The SEQUENCE this need is turned into matters as much as the final split: a single
// shuffle of "box A x5, box B x5, ..." guarantees an even total but not an even
// SPREAD — box A could easily land twice in a row, or take all of the first ten
// slots before box B gets its first. Built round by round instead: each round is a
// fresh random ordering of every box that still needs more, so no box can repeat
// until every other box-with-remaining-need has had a turn. One seam to guard: two
// independent rounds back to back could still coincidentally start/end on the same
// box, so a round's first pick is swapped with another of its own picks whenever it
// would repeat the previous round's last one.
$need = [];   // server_id => how many more THIS run still owes that box
foreach ($quota as $sid => $q) {
    $owed = max(0, $q - ($haveByServerId[$sid] ?? 0));
    if ($owed > 0) $need[$sid] = $owed;
}

// Seed the "previous pick" from the REAL row immediately before this run's first
// target, in list order — not null — so a staged run (test 2 domains, then the rest
// later, in a separate invocation with no memory of the first) still can't hand the
// row right after that boundary the same box as the row right before it.
$queue = array_keys($todo);
$last  = null;
if ($queue) {
    $firstIdx = $queue[0];
    if ($firstIdx > 0) {
        $prevHost = trim((string) ($rows[$firstIdx - 1]['ftp_host'] ?? ''));
        if ($prevHost !== '') {
            foreach ($fleet as $sid => $srv) {
                if (($srv['host'] ?? '') === $prevHost) { $last = $sid; break; }
            }
        }
    }
}

$slots = [];   // ordered sequence of server arrays — position IS the order rows are handed out
while ($need) {
    $round = array_keys($need);
    shuffle($round);
    if ($last !== null && count($round) > 1 && $round[0] === $last) {
        $j = random_int(1, count($round) - 1);
        [$round[0], $round[$j]] = [$round[$j], $round[0]];
    }
    foreach ($round as $sid) {
        $slots[] = $fleet[$sid];
        $last = $sid;
        if (--$need[$sid] <= 0) unset($need[$sid]);
    }
}

// Rows are handed the sequence in THEIR OWN order (not shuffled) — this batch's row
// order is the intended go-live sequence (see the target-list editor), so the "no
// box repeats until every box has had a turn" guarantee above applies directly to
// the order sites will actually go live in, not to some other order nobody sees.
$assignment = [];   // row index => server
$n = min(count($slots), count($queue));
for ($i = 0; $i < $n; $i++) $assignment[$queue[$i]] = $slots[$i];
$unplaced = array_slice($queue, $n);

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
$total = count($assignment); $n = 0;
foreach ($assignment as $i => $srv) {
    $n++;
    $domain = $todo[$i];
    // Locked against the infra console's own Host column button — this batch job
    // and that button both provision the same domain the same way, and used to be
    // able to race each other unprotected.
    $res = infra_provision_locked($domain, $srv, null, ['site' => true, 'cf' => false, 'restart' => false]);
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
        printf("  [%d/%d] ✓ %-34s %-8s ftp %s\n", $n, $total, $domain, $srv['label'] ?? '', $user);
    } else {
        $fail++;
        $why = '';
        foreach ($res['lines'] as $l) if (str_contains($l, '✗')) $why = trim(str_replace('Host: ✗', '', $l));
        printf("  [%d/%d] ✗ %-34s %-8s %s\n", $n, $total, $domain, $srv['label'] ?? '', $why ?: 'failed');
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
// Unallocated rows are folded into the exit code, not just $fail's count in the log
// line, so a plan that doesn't cover the whole batch can't finish looking clean
// (exit 0, "0 failed") to the caller — the JS side reads THIS to decide whether to
// show "Done." and auto-reload, not the printed sentence.
exit(($fail > 0 || $unplaced) ? 1 : 0);
