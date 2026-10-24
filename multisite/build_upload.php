<?php
/**
 * build_upload.php — "Run selected" for a few domains, as ONE background job.
 *
 *   php multisite/build_upload.php <master> --batch=bN --only=a.com,b.com [--do=host,build,upload]
 *
 * An orchestrator only — it runs the existing pieces in order and adds no logic of its own:
 *   1. multisite/create_hosts.php  for any of the selected domains with no box/FTP
 *      credentials yet — safe to run on domains that already have one (it skips them),
 *      so this step costs nothing extra for a domain that's already hosted;
 *   2. multisite/run_campaign.php  (Generate, --no-deploy) with the batch's SAVED Generate
 *      settings (batch_options.json / section_rotation.json — what the detailed page's
 *      checkboxes were last left at), same flags the detailed page's Generate button sends;
 *   3. multisite/upload_sites.php  for the domains that built OK (or, when Build was not
 *      selected, every domain in --only — assumed already built from an earlier run);
 *   4. infra_pipeline_refresh('built'/'upload') — the same check the Refresh buttons run —
 *      so the fleet's Built/Uploaded cells (and therefore the go-live gate) see the result.
 *      Always runs, same cost either way, since it's a read-only status recheck, not an
 *      action — there's nothing to "choose" here the way there is for steps 1-3.
 * Launched by multisite_api.php's rollout_build action through ms_launch_job().
 *
 * --do= exists because step 2 (Generate) is the one step here with a real cost — real AI
 * calls, real dollars per domain. Without a way to run Upload alone, fixing a bad FTP
 * password after a failed Upload meant re-running (and re-billing) Generate just to get
 * back to the Upload step. Each of the three STEP headings below always prints, even when
 * skipped, so "I didn't ask for this" (not selected) and "this ran and failed" never look
 * the same in the log — the exact ambiguity this flag has to avoid.
 *
 * A step NOT selected does not fail the job on its own. The exit code at the bottom only
 * checks the steps that were actually asked for — running Create host alone and having it
 * succeed is success, even though nothing got built or uploaded this time.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/multisite/batch_options.php';

$args = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId = ''; $only = ''; $doArg = ''; $forcePhotos = false; $logoOnly = false; $wipe = false; $imageQuality = 'medium'; $skipAiPhotos = false;
foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))  $only    = strtolower(substr($a, 7));
    if (str_starts_with($a, '--do='))    $doArg   = strtolower(substr($a, 5));
    if ($a === '--force-photos')         $forcePhotos = true;
    if ($a === '--logo-only')            $logoOnly = true;
    if ($a === '--wipe')                 $wipe = true;
    if ($a === '--skip-ai-photos')       $skipAiPhotos = true;
    if (str_starts_with($a, '--image-quality=')) $imageQuality = strtolower(substr($a, 16));
}
if (!in_array($imageQuality, ['low', 'medium', 'high'], true)) $imageQuality = 'medium';
// Opposites — refused here too, not just by the panel/API caller, same "never trust the
// layer above" reasoning as every other contradictory-flag guard in this file.
if ($forcePhotos && $skipAiPhotos) {
    echo "Refusing: --force-photos and --skip-ai-photos are opposites — pick one.\n"; exit(2);
}
// The four categories a normal run would otherwise generate AI photos for — see
// build_one.php's own $skipped('images.ai_photos_*') checks, which this list feeds via
// --skip= below. Deliberately NOT the bare 'images' step: that would also turn off the
// free, non-AI per-domain uniqueness pass (crop/tone jitter + EXIF strip) that every
// photo — AI-eligible or not — goes through regardless of this flag, which is what keeps
// a domain built with --skip-ai-photos from shipping an exact duplicate of the master's
// photo. See includes/multisite/image_overlay.php.
$aiPhotoSkip = ['images.ai_photos_hero_home', 'images.ai_photos_hero_landing', 'images.ai_photos_other_home', 'images.ai_photos_other_landing'];

// Palette/font/jitter/logo/icon/favicon all live under the one 'visual' step (colors and
// the logo move together on purpose — the logo is painted FROM the current theme colors)
// — skip everything else so a domain's AI text, photos, page-pool selection, and landing
// pages are never touched just to push a Theme Preset/Logo Pool/icon-library change out.
// A ONE-OFF override, not a change to the batch's own saved Generate settings — those
// stay exactly as they were for every other run.
$logoOnlySkip = ['ai', 'images', 'tags', 'structure', 'pagepool', 'landing'];
$domains = array_values(array_filter(array_map('trim', explode(',', $only))));
if (!ms_valid_master_id($masterId) || !ms_batch_exists($masterId, $batchId) || !$domains) {
    echo "Usage: build_upload.php <master> --batch=bN --only=a.com,b.com [--do=host,build,upload]\n"; exit(2);
}
// Omitted --do= means all three — the one-click "do everything" default every caller
// got before this flag existed.
$sel = $doArg !== '' ? array_fill_keys(array_filter(array_map('trim', explode(',', $doArg))), true)
                     : ['host' => true, 'build' => true, 'upload' => true];
$do = ['host' => isset($sel['host']), 'build' => isset($sel['build']), 'upload' => isset($sel['upload'])];
if (!$do['host'] && !$do['build'] && !$do['upload']) {
    echo "Nothing selected to run.\n"; exit(2);
}
// Wipe deletes every file already on a domain's remote host before uploading fresh.
// Safe ONLY when this same run is about to regenerate everything the site needs —
// --logo-only explicitly skips landing/pagepool (see $logoOnlySkip above), so the
// local output it produces is missing most of the site's real pages. Wipe + a
// logo-only build would delete a live domain's actual content and replace it with
// just the handful of pages logo-only touches. Checked here too, not just by the
// panel/API caller — same reasoning as upload_sites.php's own --wipe guard: a
// destructive flag must refuse itself at every layer that can reach it, not trust
// the layer above to have gotten it right.
if ($wipe && (!$do['build'] || !$do['upload'] || $logoOnly)) {
    echo "Refusing --wipe: it requires a full Build AND Upload in the same run (not --logo-only) so nothing "
       . "is deleted without being replaced. Run a normal full build with Upload (and --wipe) together.\n";
    exit(2);
}
$php = ms_php_cli();

// 1. Create host — only actually touches domains in --only that have no FTP
// credentials yet; create_hosts.php itself skips any row that's already hosted, so
// this is a no-op (fast, visible as "0 needing a host") for a domain re-run through
// this button a second time.
$hostOk = true;
if ($do['host']) {
    echo "== 1/4 Create host for " . count($domains) . " domain(s) (skips any that already have one)\n";
    passthru(implode(' ', array_map('escapeshellarg', [$php, BASE_DIR . '/multisite/create_hosts.php', $masterId,
        '--batch=' . $batchId, '--only=' . implode(',', $domains)])), $rcHost);
    $hostOk = ($rcHost === 0);
    if (!$hostOk) {
        echo "\n   Create host reported a problem (exit {$rcHost}) — continuing anyway; Upload will fail below for any domain still missing a host.\n";
    }
} else {
    echo "== 1/4 Create host — skipped (not selected)\n";
}

// 2. Generate — the batch's saved settings, never deploying. When not selected, every
// requested domain is assumed already built from an earlier run, so Upload (below) still
// has something to send.
$buildOk = true;
$built = $domains;
if ($do['build']) {
    $saved = ms_batch_saved_run_settings($masterId, $batchId);
    // Ticking both narrows to "visual identity + photos, skip the rest" — 'images' stays
    // OUT of the skip list so --force-photos below has something to actually act on.
    $skipList = $logoOnly
        ? ($forcePhotos ? array_values(array_diff($logoOnlySkip, ['images'])) : $logoOnlySkip)
        : $saved['skip'];
    // One-off addition, not a change to the batch's saved Generate settings — same spirit
    // as --logo-only's own skip list above. Harmless (and redundant, not double-applied)
    // if $skipList already contains the bare 'images' step from $logoOnly above; the whole
    // step not running already covers these four.
    if ($skipAiPhotos) $skipList = array_values(array_unique(array_merge($skipList, $aiPhotoSkip)));
    $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $cmd = [$php, BASE_DIR . '/multisite/run_campaign.php', $masterId, '--batch=' . $batchId, '--run-id=' . $runId,
            '--no-preflight', '--jobs=1', '--retries=1', '--no-deploy', '--only=' . implode(',', $domains)];
    if ($skipList) $cmd[] = '--skip=' . implode(',', $skipList);
    // Section-order rotation is itself skipped (part of $logoOnlySkip's 'structure'), so
    // the saved pin counts would do nothing anyway — left out entirely for a logo-only run.
    if (!$logoOnly) foreach ($saved['rotation'] as $flag => $n) $cmd[] = '--' . $flag . '=' . (int) $n;
    if ($forcePhotos) $cmd[] = '--force-photos';
    if ($imageQuality !== 'medium') $cmd[] = '--image-quality=' . $imageQuality;
    // Printed before the build starts (not after, when run_campaign.php itself logs it) —
    // rollout_build_status reads this line to find the live run.json and show per-domain
    // step progress while step 2 is still in flight.
    echo "\nRUN_ID={$runId}\n";
    echo "== 2/4 Build " . count($domains) . " domain(s)" . ($skipList ? ' (skipping: ' . implode(', ', $skipList) . ')' : '')
       . ($logoOnly ? ' [--logo-only: this batch\'s saved Generate settings are not used for this run]' : '')
       . ($forcePhotos ? ' [--force-photos: every AI photo slot regenerated from the master]' : '')
       . ($skipAiPhotos ? ' [--skip-ai-photos: no OpenAI photo call this run, free uniqueness pass only]' : '')
       . ($imageQuality !== 'medium' ? " [--image-quality={$imageQuality}]" : '')
       . ($logoOnly && $forcePhotos ? ' [combined: visual identity + photos only, AI text/pages skipped]' : '') . "\n";
    passthru(implode(' ', array_map('escapeshellarg', $cmd)), $rc);

    $run = json_decode((string) @file_get_contents(ms_batch_dir($masterId, $batchId) . '/runs/' . $runId . '.json'), true) ?: [];
    $built = [];
    foreach (($run['results'] ?? []) as $r) if (($r['status'] ?? '') === 'ok') $built[] = strtolower((string) $r['domain']);
    $buildOk = (count($built) === count($domains));
    echo "\n   built OK: " . count($built) . " of " . count($domains) . "\n";
} else {
    echo "\n== 2/4 Build — skipped (not selected)\n";
}

// 3. Upload what's built — either what step 2 just built, or (step 2 not selected) the
// full requested list, assumed already built.
$uploadOk = true;
if ($do['upload']) {
    if ($built) {
        echo "\n== 3/4 Upload " . count($built) . " domain(s)" . ($wipe ? ' [--wipe: existing remote files deleted first]' : '') . "\n";
        $upCmd = [$php, BASE_DIR . '/multisite/upload_sites.php', $masterId,
            '--batch=' . $batchId, '--only=' . implode(',', $built)];
        if ($wipe) $upCmd[] = '--wipe';
        passthru(implode(' ', array_map('escapeshellarg', $upCmd)), $rcUp);
        $uploadOk = ($rcUp === 0);
    } else {
        echo "\n== 3/4 Upload skipped — nothing built\n";
        $uploadOk = false;
    }
} else {
    echo "\n== 3/4 Upload — skipped (not selected)\n";
}

// 4. Re-check the fleet cells the go-live gate reads — scoped to just the selected
// domain(s), not the whole batch. Re-verifying every other domain in the batch for a
// 1-or-few-domain run was wasted work (and wall-clock) that had nothing to do with
// what was just touched. Always runs — read-only, so there's no cost to skip.
echo "\n== 4/4 Check Built and Uploaded\n";
require_once BASE_DIR . '/admin/infra/lib/pipeline.php';
foreach (['built', 'upload'] as $step) {
    $r = infra_pipeline_refresh($step, '', implode(',', $domains));
    echo "   {$step}: {$r['ok']} ok, {$r['todo']} not yet, {$r['fail']} failed (of {$r['checked']} checked)\n";
}
$st = infra_pipeline_stored($domains);
$up = 0;
foreach ($domains as $d) if (($st[$d]['upload']['state'] ?? '') === 'ok') $up++;
// This is each domain's STORED upload status, not a report of what step 3 just did —
// true of a domain's upload from months ago just as much as one from 30 seconds ago.
// Said plainly only when Upload actually ran this time; otherwise worded so "Upload —
// skipped (not selected)" two lines up isn't contradicted by a line that reads like an
// upload just happened. Hit for real 2026-10-23: a Build-only run (new favicon.ico
// generated locally, Upload left unticked) still ended in "DONE: 1 of 1 uploaded and
// ready to schedule," which read as confirmation the new file had reached the live
// server when it was still sitting in the local output dir, untouched.
if ($do['upload']) {
    echo "\nDONE: {$up} of " . count($domains) . " uploaded and ready to schedule.\n";
} else {
    echo "\nUpload was not ticked this run, so nothing was sent to any live server — {$up} of "
       . count($domains) . " show as uploaded from an EARLIER run.\n";
}
// Only the steps actually asked for count toward success — a Create-host-only run that
// succeeds is a success, even though nothing got built or uploaded this time.
$ok = (!$do['host'] || $hostOk) && (!$do['build'] || $buildOk) && (!$do['upload'] || $uploadOk);
exit($ok ? 0 : 1);
