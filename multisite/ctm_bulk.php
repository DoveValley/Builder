<?php
/**
 * CTM bulk actions (Get/Release/Resync CTM Numbers, Set Call Routing) — Batch
 * Detailed's "Target list — edit rows directly" section — as ONE background job
 * instead of one synchronous request. The per-domain CTM logic below is a VERBATIM
 * move from admin/multisite_api.php's four case blocks — nothing about what each
 * action actually does to CTM or to params.csv has changed, only where it runs.
 *
 *   php multisite/ctm_bulk.php <master_id> --batch=bN --action=get_numbers|set_routing|release_numbers|resync_numbers
 *       --domains=a.com,b.com,... --account=ID [--destination="(555) 123-4567"]
 *
 * Deliberately SEQUENTIAL, same order as before, one domain at a time — NOT
 * parallelized. These are real-money CTM mutations (buy/release a number, change
 * routing) against a search endpoint this project has already found to be
 * non-deterministic (see ctm_area_code_for_city()'s docblock history); running
 * several at once risks two domains' searches colliding on the same number. The
 * only thing this adds over the old synchronous version is live, flushed progress
 * after each domain instead of one silent wait — and, as a side effect, immunity
 * to Apache's ~30s request timeout, since this now runs detached rather than
 * inside the HTTP request itself.
 *
 * Every per-domain operation already writes through ms_write_rows_by_domain_live()
 * after each row (unchanged from the original — see that function's own docblock
 * in includes/multisite/params.php), so a real CTM mutation is never lost even if
 * this process were killed mid-run; only the final versioned snapshot would be
 * skipped, exactly as before.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "ctm_bulk.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/calltrackingmetrics.php';
require __DIR__ . '/../admin/infra/lib/state.php';

// Same stdout-buffering fix as check_area_codes.php — without an explicit flush,
// PHP holds output until the whole script exits when stdout isn't a tty (exactly
// the case here, redirected to a .out file by ms_launch_job), which would make
// every domain's progress invisible until the entire batch finished.
function ctmb_say(string $line): void { echo $line; @flush(); }

$args     = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId = ''; $action = ''; $domainsArg = ''; $accountId = ''; $destination = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch='))       $batchId     = substr($a, 8);
    if (str_starts_with($a, '--action='))      $action      = substr($a, 9);
    if (str_starts_with($a, '--domains='))     $domainsArg  = substr($a, 10);
    if (str_starts_with($a, '--account='))     $accountId   = substr($a, 10);
    if (str_starts_with($a, '--destination=')) $destination = substr($a, 14);
}
$validActions = ['get_numbers', 'set_routing', 'release_numbers', 'resync_numbers'];
if (!ms_valid_master_id($masterId) || !ms_batch_exists($masterId, $batchId) || !in_array($action, $validActions, true)) {
    fwrite(STDERR, "usage: ctm_bulk.php <master_id> --batch=bN --action=" . implode('|', $validActions)
        . " --domains=a.com,b.com --account=ID [--destination=...]\n");
    exit(2);
}
$domainsIn = array_values(array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', $domainsArg))));
if (!$domainsIn) { fwrite(STDERR, "No domains given.\n"); exit(2); }
if ($accountId === '') { fwrite(STDERR, "A CTM sub-account ID is required.\n"); exit(2); }
if ($action === 'set_routing' && $destination === '') { fwrite(STDERR, "A destination phone number is required.\n"); exit(2); }
if (!ctm_configured()) { fwrite(STDERR, "CTM_ACCESS_KEY/CTM_SECRET_KEY are not configured in config.php.\n"); exit(2); }

$batchDir   = ms_batch_dir($masterId, $batchId);
$paramsPath = $batchDir . '/params.csv';
$byDomain   = ms_rows_by_domain($paramsPath);
$results    = [];

if ($action === 'get_numbers' || $action === 'resync_numbers') {
    $nicheCode = strtoupper(substr($masterId, 0, 3));
    $batchMeta = ms_batch_meta($masterId, $batchId);
    $batchSeq  = (int) ($batchMeta['seq'] ?? 0);
    $rowOrder  = array_keys($byDomain);
}

foreach ($domainsIn as $dom) {
    if (!isset($byDomain[$dom])) {
        $results[] = ['domain' => $dom, 'ok' => false, 'error' => "Not in this batch's target list."];
        ctmb_say("  {$dom}: not in this batch's target list — skipped\n");
        continue;
    }

    // ── Get CTM Phone Numbers — verbatim from admin/multisite_api.php's
    // ctm_get_numbers case. ─────────────────────────────────────────────────────
    if ($action === 'get_numbers') {
        $rec = infra_state_get_domain($dom);
        if ($rec && ($rec['status'] ?? '') === 'live') {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => 'LIVE — use Correct & Regenerate to change its phone number.'];
            ctmb_say("  {$dom}: LIVE — refused\n");
            continue;
        }
        $city = trim((string) ($byDomain[$dom]['city'] ?? ''));
        $ss   = trim((string) ($byDomain[$dom]['SS'] ?? ''));
        if ($city === '' || $ss === '') {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => 'No city/state saved on this row yet — fill those in first.'];
            ctmb_say("  {$dom}: no city/state on file — skipped\n");
            continue;
        }
        $rowNum = array_search($dom, $rowOrder, true);
        $rowNum = $rowNum === false ? 0 : $rowNum + 1;
        $label  = ctm_build_label($nicheCode, $batchSeq, $rowNum, $ss, $city, $dom);
        $areaCodeIn = trim((string) ($byDomain[$dom]['area_code'] ?? ''));
        $r = ctm_get_number_for_domain($accountId, $dom, $city, $ss, $label, $areaCodeIn);
        if (!$r['ok']) {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => $r['error']];
            ctmb_say("  {$dom} ({$city}, {$ss}): FAILED — {$r['error']}\n");
            continue;
        }
        $byDomain[$dom]['phone']     = $r['phone'];
        $byDomain[$dom]['area_code'] = $r['area_code'];
        $warning = $r['warning'];
        $pendingDest = trim((string) ($byDomain[$dom]['forward_to'] ?? ''));
        if ($pendingDest !== '' && trim((string) ($byDomain[$dom]['forward_rpn_id'] ?? '')) === '') {
            $routed = ctm_set_routing_for_domain($accountId, $r['phone'], $pendingDest, '');
            if ($routed['ok']) {
                $byDomain[$dom]['forward_to']     = $routed['destination'];
                $byDomain[$dom]['forward_rpn_id'] = $routed['receiving_id'];
                $warning .= ($warning !== '' ? ' ' : '') . "Pre-set routing to {$routed['destination']} applied.";
            } else {
                $warning .= ($warning !== '' ? ' ' : '') . "Could not apply its pre-set routing destination: {$routed['error']}";
            }
        }
        $results[] = ['domain' => $dom, 'ok' => true, 'phone' => $r['phone'], 'area_code' => $r['area_code'], 'warning' => $warning];
        ctmb_say("  {$dom} ({$city}, {$ss}): bought {$r['phone']} (area code {$r['area_code']})" . ($warning ? " — {$warning}" : '') . "\n");
        ms_write_rows_by_domain_live($paramsPath, $byDomain);
        continue;
    }

    // ── Set Call Routing — verbatim from admin/multisite_api.php's
    // ctm_set_routing case. ─────────────────────────────────────────────────────
    if ($action === 'set_routing') {
        $phone = trim((string) ($byDomain[$dom]['phone'] ?? ''));
        if ($phone === '') {
            $byDomain[$dom]['forward_to'] = $destination;
            $results[] = ['domain' => $dom, 'ok' => true, 'destination' => $destination, 'pending' => true];
            ctmb_say("  {$dom}: no tracking number yet — destination {$destination} staged, applies automatically once bought\n");
            ms_write_rows_by_domain_live($paramsPath, $byDomain);
            continue;
        }
        $oldId = trim((string) ($byDomain[$dom]['forward_rpn_id'] ?? ''));
        $r = ctm_set_routing_for_domain($accountId, $phone, $destination, $oldId);
        if (!$r['ok']) {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => $r['error']];
            ctmb_say("  {$dom}: FAILED — {$r['error']}\n");
            continue;
        }
        $byDomain[$dom]['forward_to']     = $r['destination'];
        $byDomain[$dom]['forward_rpn_id'] = $r['receiving_id'];
        $results[] = ['domain' => $dom, 'ok' => true, 'destination' => $r['destination']];
        ctmb_say("  {$dom}: now routes to {$r['destination']}\n");
        ms_write_rows_by_domain_live($paramsPath, $byDomain);
        continue;
    }

    // ── Release CTM Numbers — verbatim from admin/multisite_api.php's
    // ctm_release_numbers case. ─────────────────────────────────────────────────
    if ($action === 'release_numbers') {
        $rec = infra_state_get_domain($dom);
        if ($rec && ($rec['status'] ?? '') === 'live') {
            $results[] = ['domain' => $dom, 'ok' => false,
                'error' => 'LIVE — releasing would clear Phone with no rebuild to stop the site showing it. Use Correct & Regenerate first if this domain really should lose its number.'];
            ctmb_say("  {$dom}: LIVE — refused\n");
            continue;
        }
        $phone = trim((string) ($byDomain[$dom]['phone'] ?? ''));
        if ($phone === '') {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => 'No phone number on file for this row — nothing to release.'];
            ctmb_say("  {$dom}: no phone on file — nothing to release\n");
            continue;
        }
        $lookupPhone = ctm_combine_phone_area($phone, (string) ($byDomain[$dom]['area_code'] ?? ''));
        $lookup = ctm_number_by_phone($accountId, $lookupPhone);
        if (!$lookup['ok']) {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => $lookup['error']];
            ctmb_say("  {$dom}: FAILED — {$lookup['error']}\n");
            continue;
        }
        $numberId = (string) ($lookup['number']['id'] ?? '');
        if ($numberId === '') {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => 'CTM returned a match with no number id — cannot release.'];
            ctmb_say("  {$dom}: FAILED — CTM returned a match with no number id\n");
            continue;
        }
        $rel = ctm_release_number($accountId, $numberId);
        if (!$rel['ok']) {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => $rel['error']];
            ctmb_say("  {$dom}: FAILED to release — {$rel['error']}\n");
            continue;
        }
        $byDomain[$dom]['phone']          = '';
        $byDomain[$dom]['area_code']      = '';
        $byDomain[$dom]['forward_rpn_id'] = '';
        $results[] = ['domain' => $dom, 'ok' => true, 'released_phone' => $phone];
        ctmb_say("  {$dom}: released {$phone}\n");
        ms_write_rows_by_domain_live($paramsPath, $byDomain);
        continue;
    }

    // ── Resync from CTM — shared core is ctm_resync_domain(), also used by
    // row_correct's own post-save auto-resync (admin/multisite_api.php). ─────────
    if ($action === 'resync_numbers') {
        $rowNum = array_search($dom, $rowOrder, true);
        $rowNum = $rowNum === false ? 0 : $rowNum + 1;
        $r = ctm_resync_domain($accountId, $dom, $byDomain[$dom], $nicheCode, $batchSeq, $rowNum);
        if (!$r['ok']) {
            $results[] = ['domain' => $dom, 'ok' => false, 'error' => $r['error']];
            ctmb_say("  {$dom}: FAILED — {$r['error']}\n");
            continue;
        }
        $byDomain[$dom] = $r['row'];
        $results[] = [
            'domain'      => $dom,
            'ok'          => true,
            'phone'       => $byDomain[$dom]['phone'],
            'area_code'   => $byDomain[$dom]['area_code'],
            'relabeled'   => $r['relabeled'],
            'area_synced' => $r['area_synced'],
            'routing'     => $r['routing'],
            'warning'     => $r['warning'],
        ];
        $changes = [];
        if ($r['relabeled']) $changes[] = 'label fixed';
        if ($r['area_synced']) $changes[] = "area code → {$byDomain[$dom]['area_code']}";
        if ($r['routing'] === 'synced') $changes[] = 'routing re-applied';
        if ($r['routing'] === 'failed') $changes[] = 'routing FAILED';
        ctmb_say("  {$dom}: " . ($changes ? implode(', ', $changes) : 'already in sync') . "\n");
        ms_write_rows_by_domain_live($paramsPath, $byDomain);
        continue;
    }
}

// The one real versioned checkpoint for this whole action — same as every other
// write path in this file, and unchanged from the original synchronous version.
ms_write_rows_by_domain($batchDir, $paramsPath, $byDomain);

$ok = count(array_filter($results, fn($r) => !empty($r['ok'])));
ctmb_say("\n" . count($results) . " row(s) processed — {$ok} ok, " . (count($results) - $ok) . " failed/skipped.\n");

$report = [
    'checked_at' => gmdate('c'),
    'master_id'  => $masterId,
    'batch_id'   => $batchId,
    'action'     => $action,
    'results'    => $results,
];
file_put_contents($batchDir . '/ctm_bulk_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

exit(0);
