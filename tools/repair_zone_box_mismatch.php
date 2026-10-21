<?php
/**
 * Put a domain back on the box its Cloudflare zone's account is bound to.
 *
 * ── THE FAULT THIS REPAIRS ───────────────────────────────────────────────────────
 * On 2026-10-21, 11 water-batch domains had their zone created correctly — each one in
 * the account bound to the box they were on at 04:16 — and were then REASSIGNED to an
 * unrelated box at 04:57. The site was built and uploaded to the new box while the A
 * records stayed pointing at the old one. Nothing reported an error: on go-live all
 * eleven would have served Hestia's default page with a 200 OK.
 *
 * Two defects let it happen, both fixed alongside this script:
 *   · pipeline.php's 'assign' step keyed its "the zone decides the box" guard on
 *     cf_account_id rather than on either that OR cf_zone_id, so a blank account read
 *     as "no zone yet" and the round-robin ran.
 *   · provision.php wrote `cf_account_id => $account['id'] ?? ''` unconditionally, so a
 *     host-only run — which legitimately passes no account — erased the field.
 *
 * ── WHY IT MOVES THE BOX AND NOT THE DNS ─────────────────────────────────────────
 * Cloudflare cannot move a zone between accounts, so the account is fixed, and
 * cf_alloc.php binds each account to exactly one box. The domain's correct home is
 * therefore the account's box, and the A records already point there — they were never
 * touched. Repointing them at the new box would "work" and would quietly break the
 * containment model: the nameserver pair would link the domain to one group of twenty
 * domains while its IP linked it to a different group. That chaining is the whole thing
 * cf_alloc.php exists to prevent.
 *
 * So: move the domain back. The DNS needs no change at all.
 *
 * ── WHAT IT DOES, PER DOMAIN ─────────────────────────────────────────────────────
 *   1. Finds the zone LIVE across every configured account and refuses unless exactly
 *      one account holds it. The cached sweep agrees today, but placement is permanent
 *      and a stale cache is not something to stake it on.
 *   2. Backfills cf_account_id.
 *   3. Re-runs 'assign', which now walks the repaired guard and sets server_id back.
 *   4. Deletes the vhost on the WRONG box (this is where the real site currently sits).
 *   5. Deletes the stub vhost on the TARGET box, then re-runs 'host' to recreate it.
 *      The delete is not optional: infra_provision_one() skips a vhost that already
 *      exists, so recreating over the stub would mint no FTP login and the upload would
 *      have nothing to log in with.
 *   6. Re-runs 'zone' — the zone already exists and its account is preserved, so this is
 *      the idempotent pass that reinstalls the origin certificate step 5 destroyed and
 *      re-asserts the A records, SSL mode and HSTS.
 *   7. Clears the stale upload manifest, then re-runs 'upload'. deploy_site() is
 *      incremental against that manifest, which records the md5s uploaded to the WRONG
 *      box — leave it and every file is skipped and the target is left empty, reported
 *      as a success.
 *   8. Writes the new FTP credentials back into the batch's params.csv.
 * Finally, one web restart per box touched — never one per domain.
 *
 * ── REFUSALS ─────────────────────────────────────────────────────────────────────
 * Skips, loudly, rather than guessing: a domain that is live · a zone found in zero or
 * in several accounts · an account bound to no box · a target box whose docroot holds
 * more than the placeholder (that would be a real site, and this would delete it) · a
 * domain with no built output to re-upload.
 *
 * Usage:
 *   php tools/repair_zone_box_mismatch.php
 *   php tools/repair_zone_box_mismatch.php --domain=dexrestoration.com
 *   php tools/repair_zone_box_mismatch.php --apply
 *
 * Defaults to a DRY RUN. Nothing is written, deleted or uploaded without --apply.
 */

$base = dirname(__DIR__);
require_once $base . '/admin/infra/lib/store.php';
require_once $base . '/admin/infra/lib/state.php';
require_once $base . '/admin/infra/lib/http.php';
require_once $base . '/admin/infra/lib/cache.php';
require_once $base . '/admin/infra/lib/cloudflare.php';
require_once $base . '/admin/infra/lib/hestia.php';
require_once $base . '/admin/infra/lib/hestia_fleet.php';
require_once $base . '/admin/infra/lib/cf_alloc.php';
require_once $base . '/admin/infra/lib/pipeline.php';
require_once $base . '/admin/infra/lib/provision.php';

$opt = getopt('', ['domain::', 'apply', 'finish']);
$apply  = isset($opt['apply']);
$finish = isset($opt['finish']);
$only   = strtolower(trim((string) ($opt['domain'] ?? '')));

/** The placeholder a fresh vhost carries. Anything beyond this is a real site. */
const STUB_MAX_FILES = 3;
const STUB_MAX_BYTES = 20000;

function say(string $s = ''): void { echo $s, "\n"; }

/* ── --finish: the parts a previous run left undone. ────────────────────────────
 *
 * DERIVED, not a list typed from the first run's output: a domain with a zone id on
 * record whose Zone cell is not green is one the zone step never confirmed — which is
 * exactly what the stored-vs-derived prerequisite bug caused on 2026-10-21, and it is
 * the same condition whatever caused it. The zone step is idempotent (the zone already
 * exists, the account is preserved), so re-running it costs nothing where it is not
 * needed; what it does do is reinstall the origin certificate that recreating the
 * vhost destroyed, and re-assert SSL mode, HSTS and the A records. */
if ($finish) {
    $rows = infra_state_db()->query(
        "SELECT domain, batch, cf_account_id FROM domains WHERE cf_zone_id != '' ORDER BY domain")->fetchAll(PDO::FETCH_ASSOC);
    $knownAcct = [];
    foreach (infra_cf_accounts() as $a) $knownAcct[(string) ($a['id'] ?? '')] = true;
    $cells = infra_pipeline_stored(array_column($rows, 'domain'));

    $todo = []; $exUnknown = 0; $exNoHost = 0;
    foreach ($rows as $r) {
        if ($only && $r['domain'] !== $only) continue;
        if (($cells[$r['domain']]['zone']['state'] ?? '') === INFRA_STEP_OK) continue;
        // An account the console has lost cannot be used to talk to the zone at all,
        // so a zone step here would only fail. 31 domains are in that state (all
        // acct-01) and they are a separate decision, not part of this repair.
        if (empty($knownAcct[(string) ($r['cf_account_id'] ?? '')])) { $exUnknown++; continue; }
        // No host means the vhost was never recreated, so there is no certificate
        // missing and nothing here to re-assert.
        if (($cells[$r['domain']]['host']['state'] ?? '') !== INFRA_STEP_OK) { $exNoHost++; continue; }
        $todo[] = $r;
    }
    say(($apply ? 'APPLYING' : 'DRY RUN — nothing will be written') . ' --finish. '
        . count($todo) . ' domain(s) have a host and a zone on record that the zone step never confirmed.');
    if ($exUnknown || $exNoHost) {
        say('Excluded: ' . $exUnknown . ' whose Cloudflare account is not in the console, '
          . $exNoHost . ' with no host area. Neither is repaired by this pass.');
    }
    say(str_repeat('─', 78));

    $byBatch = [];
    $fdone = 0;
    foreach ($todo as $r) {
        $d = $r['domain'];
        if (!$apply) { say('  ' . $d . ' — would re-run zone (certificate, SSL, HSTS, A records)'); $fdone++; continue; }
        infra_pipeline_set($d, 'zone', INFRA_STEP_TODO, 'finish: re-asserting after the box move');
        $res = infra_pipeline_do('zone', $d, (string) $r['batch'], ['force' => true]);
        say('  ' . str_pad($d, 26) . ($res['ok'] ? '✓ ' : '✗ ') . $res['msg']);
        if ($res['ok']) { $fdone++; if ($r['batch']) $byBatch[$r['batch']][] = $d; }
    }
    if ($apply && $byBatch) { say(''); repair_sync_params_csv($byBatch, $base); }
    say('');
    say(str_repeat('─', 78));
    say(($apply ? 'DONE — ' : 'WOULD FINISH — ') . $fdone . ' of ' . count($todo) . '.');
    exit(0);
}

/* ── Who is broken. DERIVED from the fault's definition, never a hardcoded list:
 *    a zone on record with no account against it is exactly the condition. ────── */
$sql = "SELECT domain, server_id, cf_zone_id, status, batch FROM domains
         WHERE cf_zone_id != '' AND COALESCE(cf_account_id,'') = '' ORDER BY domain";
$broken = infra_state_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if ($only) $broken = array_values(array_filter($broken, fn($r) => $r['domain'] === $only));

if (!$broken) { say($only ? "{$only}: nothing to repair." : 'Nothing to repair.'); exit(0); }

say(($apply ? 'APPLYING' : 'DRY RUN — nothing will be written') . '. ' . count($broken) . ' domain(s).');
say(str_repeat('─', 78));

/* ── Where each zone actually lives. One listing per account, then a lookup. ──── */
$zoneOwner = [];           // zone id => [account, ...] — a list, so "several" is visible
foreach (infra_cf_accounts() as $a) {
    foreach (cf_list_zones($a) as $z) $zoneOwner[$z['id']][] = $a;
}

$touched = [];             // box id => server, for the single restart per box
$done = 0; $skipped = 0;

foreach ($broken as $rec) {
    $domain = $rec['domain'];
    say('');
    say($domain);

    $skip = function (string $why) use (&$skipped) { say('   ⏭  SKIPPED — ' . $why); $skipped++; };

    if (($rec['status'] ?? '') === 'live') { $skip('it is LIVE — infrastructure changes go through Correct & Regenerate'); continue; }

    $owners = $zoneOwner[$rec['cf_zone_id']] ?? [];
    if (count($owners) !== 1) {
        $skip(count($owners) === 0
            ? 'its zone id is in none of the configured accounts — it may have been deleted at Cloudflare'
            : 'its zone id appears in ' . count($owners) . ' accounts, which should be impossible — resolve by hand');
        continue;
    }
    $acct  = $owners[0];
    $bound = trim((string) ($acct['server_id'] ?? ''));
    if ($bound === '') { $skip('its zone is in ' . $acct['label'] . ', which is bound to no box — bind it on the Cloudflare tab first'); continue; }

    $target = infra_hestia_server($bound);
    $wrong  = infra_hestia_server((string) $rec['server_id']);
    if (!$target) { $skip('its account is bound to ' . $bound . ', which is not in the box registry'); continue; }

    say('   zone   ' . $acct['label']);
    say('   target ' . ($target['label'] ?? $bound) . '  (' . ($target['default_ip'] ?? $target['host']) . ')  ← the A records already point here');
    say('   wrong  ' . ($wrong['label'] ?? $rec['server_id'] ?: '—'));

    if ($wrong && $wrong['id'] === $target['id']) { $skip('already on the right box — only the account reference needs backfilling'); continue; }

    // The target stub must be a stub. If a real site is sitting there, something is
    // going on that this script did not predict, and deleting it would destroy it.
    $stub = hestia_docroot_files($target, hestia_fleet_user($target), $domain);
    $stubExists = ($stub['files'] ?? 0) > 0 || ($stub['dirs'] ?? 0) > 0;
    if ($stubExists && (($stub['files'] ?? 0) > STUB_MAX_FILES || ($stub['bytes'] ?? 0) > STUB_MAX_BYTES)) {
        $skip('the target box already holds a real site for this domain ('
            . (int) $stub['files'] . ' files, ' . (int) $stub['bytes'] . ' bytes) — look before deleting anything');
        continue;
    }

    $built = infra_pipeline_built_index()[infra_pipeline_slug($domain)] ?? null;
    if (!$built || empty($built['has_index'])) { $skip('nothing built for it — the re-upload would have no source'); continue; }
    // Counted here, not read off $built: infra_pipeline_built_index() returns
    // {dir, at, has_index} and nothing else, so `$built['files']` was quietly 0 — a
    // dry run that reported "re-upload 0 file(s)" for a 9 MB site. A reporting line
    // that is wrong in the reassuring direction is worse than no line.
    $builtFiles = iterator_count(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($built['dir'], FilesystemIterator::SKIP_DOTS)));

    $manifests = glob($base . '/sites/*/multisite/manifests/' . str_replace('.', '_', $domain) . '_*.json') ?: [];

    say('   plan   backfill account → reassign → delete vhost on ' . ($wrong['label'] ?? '?')
        . ' → recreate on ' . ($target['label'] ?? '?') . ' → re-run zone → clear '
        . count($manifests) . ' manifest(s) → re-upload ' . $builtFiles . ' file(s)');

    if (!$apply) { $done++; continue; }

    /* 1 — the account reference, which everything else keys off */
    infra_state_upsert_domain(['domain' => $domain, 'cf_account_id' => (string) $acct['id']]);

    /* 2 — assign. Reset first: a cell already green is a no-op by design. */
    infra_pipeline_set($domain, 'assign', INFRA_STEP_TODO, 'repair: re-deciding the box from the zone');
    $r = infra_pipeline_do('assign', $domain, (string) $rec['batch'], ['force' => true]);
    say('   assign  ' . ($r['ok'] ? '✓ ' : '✗ ') . $r['msg']);
    if (!$r['ok']) { $skipped++; continue; }

    /* 3 — the orphan on the wrong box, and the stub on the right one */
    if ($wrong) {
        $d = hestia_delete_site($wrong, $domain);
        say('   delete  ' . ($wrong['label'] ?? '?') . ': ' . ($d['ok'] ? '✓ removed' : '✗ ' . $d['message']));
        if ($d['ok']) $touched[$wrong['id']] = $wrong;
    }
    if ($stubExists) {
        $d = hestia_delete_site($target, $domain);
        say('   delete  ' . ($target['label'] ?? '?') . ' stub: ' . ($d['ok'] ? '✓ removed' : '✗ ' . $d['message']));
        if (!$d['ok']) { say('   ⏭  SKIPPED — the stub must go before the host can be recreated with a login'); $skipped++; continue; }
    }

    /* 4 — host, on the target this time, which mints the FTP login */
    infra_pipeline_set($domain, 'host', INFRA_STEP_TODO, 'repair: recreating on the box the zone belongs to');
    $r = infra_pipeline_do('host', $domain, (string) $rec['batch'], ['force' => true]);
    say('   host    ' . ($r['ok'] ? '✓ ' : '✗ ') . $r['msg']);
    if (!$r['ok']) { $skipped++; continue; }
    $touched[$target['id']] = $target;

    /* 5 — zone: reinstalls the certificate the delete took with it */
    infra_pipeline_set($domain, 'zone', INFRA_STEP_TODO, 'repair: re-asserting records and certificate');
    $r = infra_pipeline_do('zone', $domain, (string) $rec['batch'], ['force' => true]);
    say('   zone    ' . ($r['ok'] ? '✓ ' : '✗ ') . $r['msg']);

    /* 6 — the manifest, then the upload. In that order, or nothing transfers. */
    foreach ($manifests as $m) { @unlink($m); say('   manifest cleared ' . basename($m)); }
    infra_pipeline_set($domain, 'upload', INFRA_STEP_TODO, 'repair: re-uploading to the corrected box');
    $r = infra_pipeline_do('upload', $domain, (string) $rec['batch'], ['force' => true]);
    say('   upload  ' . ($r['ok'] ? '✓ ' : '✗ ') . $r['msg']);
    if (!$r['ok']) { $skipped++; continue; }

    $done++;
}

/* ── params.csv: the batch page reads the CSV, the pipeline reads fleet.db, and a
 *    repair that fixes one and not the other leaves the next upload going back to
 *    the box we just moved off. ─────────────────────────────────────────────────── */
if ($apply && $done) {
    say('');
    say(str_repeat('─', 78));
    $byBatch = [];
    foreach ($broken as $r) if ($r['batch']) $byBatch[$r['batch']][] = $r['domain'];
    repair_sync_params_csv($byBatch, $base);
}

function repair_sync_params_csv(array $byBatch, string $base): void
{
    foreach ($byBatch as $batchTag => $domains) {
        // The `batch` column reads "water-site/b5", but the path is
        // sites/water-site/BATCHES/b5/params.csv. Interpolating the tag straight into
        // the path silently produced a non-existent file, and "not found — skipped"
        // read like a batch without a CSV rather than a bug: the first run of this
        // script reported 11 repaired and left all 11 CSV rows pointing at the boxes
        // it had just moved them off.
        [$msMaster, $msBatch] = array_pad(explode('/', (string) $batchTag, 2), 2, '');
        $csv = $base . '/sites/' . $msMaster . '/batches/' . $msBatch . '/params.csv';
        if (!is_file($csv)) { say('params.csv for ' . $batchTag . ': not found at ' . $csv . ' — skipped'); continue; }
        $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $head = array_shift($rows);
        $col  = array_flip($head);
        $n = 0;
        foreach ($rows as &$row) {
            $d = strtolower(trim($row[$col['domain']] ?? ''));
            if (!in_array($d, $domains, true)) continue;
            $st = infra_state_get_domain($d);
            $bx = infra_hestia_server((string) ($st['server_id'] ?? ''));
            if (!$bx || ($st['ftp_user'] ?? '') === '') continue;
            $row[$col['ftp_host']] = (string) ($bx['host'] ?? '');
            $row[$col['ftp_user']] = (string) $st['ftp_user'];
            $row[$col['ftp_pass']] = (string) $st['ftp_pass'];
            $row[$col['ftp_path']] = '/home/' . $st['ftp_user'];
            $n++;
        }
        unset($row);
        $fh = fopen($csv, 'w');
        fputcsv($fh, $head);
        foreach ($rows as $row) fputcsv($fh, $row);
        fclose($fh);
        say('params.csv ' . $batchTag . ': ' . $n . ' row(s) repointed');
    }
}


/* ── One restart per box. Until nginx restarts it has not read the new vhost and
 *    serves its own default page — with a 200. See hestia_restart_web(). ───────── */
if ($apply && $touched) {
    say('');
    foreach ($touched as $srv) {
        $w = hestia_restart_web($srv);
        say('restart ' . ($srv['label'] ?? $srv['id']) . ': ' . ($w['ok'] ? '✓' : '✗ ' . $w['message']));
    }
    infra_cache_flush();
}

say('');
say(str_repeat('─', 78));
say(($apply ? 'DONE — ' : 'WOULD REPAIR — ') . $done . ' repaired, ' . $skipped . ' skipped.');
if (!$apply) say('Re-run with --apply to execute.');
