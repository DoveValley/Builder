<?php
/**
 * Multisite per-row driver = process_row() — Phase 0d.
 *
 * Runs in NORMAL config mode (BASE_DIR available) and performs one row end-to-end:
 *   snapshot (once) → clone working dir → inject identity → spawn render child
 *   (build, worker-mode config) → deploy over FTP → clean up temp dirs.
 *
 * The build runs in a child process (render_site.php) because config's path
 * constants are immutable and must be worker-rooted from process start (§3). Deploy
 * runs here in the parent since deploy_site() takes explicit params, not constants.
 *
 * This unit is self-contained so the Phase 3 orchestrator can call it per row
 * (passing a shared --snapshot so the master is frozen once for the whole run).
 *
 * Usage:
 *   php multisite/build_one.php <row.json> [--snapshot=DIR] [--keep] [--force]
 *
 * row.json fields: master_id, domain (required); business, phone, tel, email,
 *   address, city, state, SS, zip, lat, lng, analytics_id, logo, web3forms_key,
 *   ftp_host, ftp_port, ftp_user, ftp_pass, ftp_path, ftp_passive.
 *
 * Progress: JSON-lines on stdout.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "build_one.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/clone.php';
require __DIR__ . '/../includes/multisite/inject.php';
require __DIR__ . '/../includes/multisite/deploy.php';
require __DIR__ . '/../includes/multisite/ai_cache.php';
require __DIR__ . '/../includes/multisite/differentiate.php';
require __DIR__ . '/../includes/multisite/visual.php';
require __DIR__ . '/../includes/multisite/landing.php';
require __DIR__ . '/../includes/multisite/geocode.php';
require_once __DIR__ . '/../includes/multisite/image_overlay.php';
require_once __DIR__ . '/../includes/multisite/seo_gate.php';
require_once __DIR__ . '/../includes/multisite/class_vocab.php';
require_once __DIR__ . '/../includes/multisite/schema_shape.php';
require_once __DIR__ . '/../includes/multisite/steps.php';

progress_set_sink(progress_jsonlines_sink());

// ── Parse args ──────────────────────────────────────────────────────────────
$rowFile = null; $snapshotArg = null; $keep = false; $force = false; $noAi = false; $skip = [];
$noDeploy = false; $outDirArg = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--keep')                       $keep = true;
    elseif ($a === '--force')                  $force = true;
    elseif ($a === '--no-ai')                  $noAi = true;
    elseif ($a === '--no-deploy')              $noDeploy = true;
    elseif (str_starts_with($a, '--out-dir='))  $outDirArg = substr($a, 10);
    elseif (str_starts_with($a, '--skip='))    $skip = array_filter(array_map('trim', explode(',', substr($a, 7))));
    elseif (str_starts_with($a, '--snapshot=')) $snapshotArg = substr($a, 11);
    elseif ($rowFile === null)                 $rowFile = $a;
}
/**
 * Steps the caller turned off for this run.
 *
 * ONLY the optional ones are honoured. Clone, identity and build are structural — a
 * run without them does not make a lesser site, it makes no site (or fifty copies of
 * the master). And the identity SCRUB inside differentiate is a safety net, not a
 * feature: it rewrites the master's own domain, email, phone and business name out of
 * every clone, so it has no switch. What can be turned off there is the tagging.
 */
$skippable = ['landing', 'visual', 'ai', 'images', 'tags', 'structure'];
// Two shapes: a whole step ("images"), or one piece inside it ("images.metadata"). The parent
// must be a real step either way. This filter is the SECOND whitelist — multisite_api.php has
// one too — and it silently dropped every dotted key until this was fixed, which made the
// card's sub-switches look wired while doing nothing. Keep the two in step.
$skip = array_values(array_filter($skip, function ($k) use ($skippable) {
    return in_array(explode('.', $k, 2)[0], $skippable, true)
        && preg_match('/^[a-z]+(\.[a-z_]+)?$/', $k);
}));
$skipped   = fn(string $k) => in_array($k, $skip, true);
if ($skipped('ai')) $noAi = true;   // one mechanism, not two
if (!$rowFile || !is_file($rowFile)) {
    fwrite(STDERR, "usage: build_one.php <row.json> [--snapshot=DIR] [--keep] [--force]\n");
    exit(2);
}
$params = json_decode(file_get_contents($rowFile), true);
if (!is_array($params)) { fwrite(STDERR, "row.json is not valid JSON\n"); exit(2); }

$masterId = $params['master_id'] ?? '';
$domain   = $params['domain'] ?? '';
if ($masterId === '' || $domain === '') { fwrite(STDERR, "master_id and domain are required\n"); exit(2); }

// Blank lat/lng in the CSV? Pull the geocoded city-center coords from the master's
// cities.json (filled by the Research cities step) so the schema still gets geo.
$params = ms_fill_coords_from_cities($params, $masterId);

// Slugify per the documented convention: keep hyphens, other unsafe chars → '_'
// (pmtraining-dallas.com → pmtraining-dallas_com).
$domainSlug = ms_domain_slug($domain);
$tmp = sys_get_temp_dir();
progress_log("Row: {$domain} (master {$masterId})");

// ── Snapshot (once). Reuse a shared --snapshot if given; otherwise own one. ──
$ownSnapshot = false;
if ($snapshotArg) {
    $snapshotDir = $snapshotArg;
    if (!is_dir($snapshotDir . '/data')) { progress_log("Snapshot invalid: {$snapshotDir}", 'fatal'); exit(1); }
} else {
    $snapshotDir = $tmp . '/ms_snapshot_' . $masterId;
    progress_log('Snapshotting master…');
    try { snapshot_master($masterId, $snapshotDir); }
    catch (Throwable $e) { progress_log('Snapshot failed: ' . $e->getMessage(), 'fatal'); exit(1); }
    $ownSnapshot = true;
}

$workingDir = $tmp . '/ms_work_' . $domainSlug;

/* Where the built site lands.
 *
 * Given --out-dir, the build is KEPT there — that is what lets generating and
 * uploading be two separate acts rather than one. Without it the old behaviour
 * stands: build to a temp dir, upload, delete. The working dir is temporary either
 * way; only the rendered output is worth keeping. */
$persistOut = $outDirArg !== null && $outDirArg !== '';
$outputDir  = $persistOut ? rtrim($outDirArg, '/') : $tmp . '/ms_out_' . $domainSlug;
if ($persistOut && !is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    progress_log('Could not create the output directory: ' . $outputDir, 'fatal');
    exit(1);
}

// Best-effort cleanup helper.
$cleanup = function () use ($workingDir, $outputDir, $snapshotDir, $ownSnapshot, $keep, $persistOut) {
    if ($keep) { progress_log("--keep: working={$workingDir} output={$outputDir}"); return; }
    ms_delete_dir($workingDir);
    // A persisted build is the product of this step; deleting it would leave the
    // upload step with nothing to send.
    if (!$persistOut) ms_delete_dir($outputDir);
    if ($ownSnapshot) ms_delete_dir($snapshotDir);
    progress_log('Cleaned up temp dirs.');
};

// ── Clone + inject ────────────────────────────────────────────────────────────
ms_step_begin('clone');
progress_log('Cloning working dir…');
clone_to_working_dir($snapshotDir, $workingDir, $masterId);

// Clear the master's pre-generated city-landing pages. They are regenerated below
// (after identity injection) scoped to THIS deploy's `landing_cities` — so each site
// gets landing pages only for the cities that deploy actually serves (blank = none).
if (is_dir($workingDir . '/data/pages')) {
    foreach (glob($workingDir . '/data/pages/*.json') ?: [] as $pf) @unlink($pf);
    foreach (glob($workingDir . '/data/pages/*.bak') ?: [] as $pf) @unlink($pf);
} else {
    @mkdir($workingDir . '/data/pages', 0775, true);
}
file_put_contents($workingDir . '/data/page-index.json', "{}\n");

// Capture the master's own identity (business/website/tel/phone/email) BEFORE
// injection — it's the "from" side of the per-site schema/identity rewrite.
$masterIdentity = [];
$mSite = json_decode(@file_get_contents($workingDir . '/data/site.json'), true);
if (is_array($mSite)) {
    $sv = $mSite['site_vars'] ?? [];
    foreach (['business', 'website', 'tel', 'phone', 'email'] as $k) $masterIdentity[$k] = $sv[$k] ?? '';
}

ms_step_begin('identity');
progress_log('Injecting identity…');
inject_params_into_working_dir($workingDir, $params);

// ── Landing pages: regenerate for THIS deploy's `landing_cities` (blank = none) ──
// Runs after injection so slugs/canonicals/schema resolve against the deploy identity.
// generate_city_pages() reads config path-constants, so it runs in a worker process
// rooted at the working dir (same reason render_site.php is a separate worker).
$landingCities = ms_parse_landing_cities((string)($params['landing_cities'] ?? ''));
if ($landingCities && $skipped('landing')) {
    progress_log('Landing pages: skipped — turned off for this run.', 'warn');
} elseif ($landingCities) {
    $label = implode(', ', array_map(fn($c) => $c['city'] . ', ' . $c['SS'], $landingCities));
    ms_step_begin('landing');
    progress_log('Generating landing pages for ' . count($landingCities) . ' city(ies): ' . $label . '…');
    // Scope the working-dir city list to just this deploy's landing cities — but keep
    // the research the master already gathered for them (neighborhoods, population,
    // industries, employers, …). The working cities.json here is still the cloned master
    // list, so merge its research onto the scoped rows; otherwise generate.py would see
    // bare rows and all research-grounded copy (incl. gated neighborhoods) degrades to
    // generic. Cities the master never researched pass through as bare rows.
    $masterCities = json_decode((string)@file_get_contents($workingDir . '/data/cities.json'), true);
    $scopedCities = is_array($masterCities)
        ? ms_merge_research_into_landing($landingCities, $masterCities)
        : $landingCities;
    file_put_contents(
        $workingDir . '/data/cities.json',
        json_encode($scopedCities, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    $lgEnv = getenv();
    $lgEnv['MULTISITE_SITE_BASE'] = $workingDir;
    $lgCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate_landing.php') . ' 2>&1';
    $lp = proc_open($lgCmd, [1 => ['pipe', 'w']], $lgpipes, null, $lgEnv);
    if (is_resource($lp)) {
        $lgOut = stream_get_contents($lgpipes[1]); fclose($lgpipes[1]);
        $lgCode = proc_close($lp);
        $res = json_decode(trim(strtok($lgOut, "\n")) ?: '', true);
        if (is_array($res) && isset($res['pages_written'])) {
            progress_log('  Landing pages written: ' . (int)$res['pages_written']
                . (!empty($res['errors']) ? ' (with ' . count($res['errors']) . ' error(s))' : ''));
        } elseif ($lgCode !== 0) {
            progress_log('  Landing generation failed (code ' . $lgCode . '): ' . trim($lgOut), 'warn');
        }
    } else {
        progress_log('  Could not launch generate_landing.php — deploy will have no landing pages.', 'warn');
    }
}

ms_step_begin('differentiate');
progress_log($skipped('tags')
    ? 'Differentiating (schema / geo) — site tags skipped, turned off for this run…'
    : 'Differentiating (schema / geo / analytics)…');
// The scrub always runs; only the analytics + Search Console tags are optional.
// Section-order switches from the batch card. Unticking the whole step turns off all three.
$structureSkip = [
    'home'    => $skipped('structure') || $skipped('structure.home'),
    'legal'   => $skipped('structure') || $skipped('structure.legal'),
    'landing' => $skipped('structure') || $skipped('structure.landing'),
];
ms_differentiate_working_dir($workingDir, $params, $masterIdentity, $skipped('tags'), $structureSkip);

// Coordinated visual identity — Theme Preset (+ logo/favicon next). Runs before the
// image prune so any generated assets exist and are referenced.
if ($skipped('visual')) {
    progress_log('Visual identity: skipped — turned off for this run.', 'warn');
} else {
    ms_step_begin('visual');
    // Palette and font are separate axes and separately switchable, so a run can vary one
    // without the other. Passed down rather than tested inside, because the picker is shared
    // with the panel preview and must stay free of build state.
    $visRes = ms_apply_visual_identity($workingDir, $params, $masterId, [
        'palette' => !$skipped('visual.palette'),
        'font'    => !$skipped('visual.font'),
    ]);
    if ($visRes['applied']) progress_log("Visual identity: Theme Preset '{$visRes['preset']}'" . (!empty($visRes['logo']) ? ", logo generated" : "") . ".");
}

// ── AI content: fill this city's ai_blocks (home + core + landing) via generate.py ──
if ($noAi) {
    progress_log('AI generation skipped (--no-ai).', 'warn');
} elseif (!ANTHROPIC_API_KEY) {
    progress_log('AI generation skipped — ANTHROPIC_API_KEY not configured.', 'warn');
} else {
    // Cache (§6a): re-inject known copy so generate.py only fills misses/stale blocks.
    $cacheFile = BASE_DIR . '/sites/' . $masterId . '/multisite/cache/' . $domainSlug . '.json';
    $registry  = json_decode(@file_get_contents($workingDir . '/data/ai_block_types.json'), true) ?: [];
    $c = ms_ai_inject_from_cache($workingDir, $cacheFile, $registry);
    if ($c['candidates'] > 0) progress_log("AI cache: offered {$c['candidates']} cached block(s) for reuse (generate.py validates each by resolved-prompt hash).");

    ms_step_begin('ai');
    progress_log('Generating AI content for city…');
    $genEnv = getenv();
    $genEnv['ANTHROPIC_API_KEY'] = ANTHROPIC_API_KEY;
    $genEnv['PYTHONUNBUFFERED']  = '1';
    $genCmd = 'python3 ' . escapeshellarg(BASE_DIR . '/generate.py')
            . ' --site-dir ' . escapeshellarg($workingDir) . ' --all'
            . ($force ? ' --refresh' : '') . ' 2>&1';
    $gp = proc_open($genCmd, [1 => ['pipe', 'w']], $gpipes, BASE_DIR, $genEnv);
    if (is_resource($gp)) {
        while (($l = fgets($gpipes[1])) !== false) {
            $l = rtrim(preg_replace('/\033\[[0-9;]*m/', '', $l));
            if ($l !== '') progress_log('  ' . $l);
        }
        fclose($gpipes[1]);
        $genCode = proc_close($gp);
        if ($genCode !== 0) { progress_log("AI generation exited with code {$genCode}", 'warn'); }
    } else {
        progress_log('Could not launch generate.py — skipping AI content.', 'warn');
    }

    // Persist all generated copy to the per-domain cache for future rebuilds.
    $cached = ms_ai_extract_to_cache($workingDir, $cacheFile, $registry);
    if ($cached > 0) progress_log("AI cache: {$cached} block(s) cached → " . basename($cacheFile));
}

// ── Per-site image differentiation (4c hero overlay + image pass) ─────────────
// After AI (so nothing overwrites the repointed fields) and before the build.
// Breaks the shared-uploads symlink first so per-site files never touch the snapshot.
//   (1) bake keyword + "City, ST" onto each hero — style locked on the Gen-Image
//       tab (this master's own hero_style.json, else the shared global one);
//   (2) perturb bytes + city-rename every other content photo so no image is byte-
//       or name-identical across sites (and the master city is stripped from names).
// Both resolve through ms_image_settings_read() (includes/multisite/image_overlay.php),
// the single place the per-master-then-global order is written down — the admin tab
// reads through the same helper, so the two can never resolve to different files.
// Neither file present = the original hardcoded defaults (see
// ms_image_variation_defaults()), so a master that has never touched the Gen-Image
// tab builds exactly as it always has.
$masterSiteDir  = BASE_DIR . '/sites/' . $masterId;
$heroStyle      = ms_image_settings_read($masterSiteDir, 'hero_style.json');
$imageVariation = ms_image_settings_read($masterSiteDir, 'image_variation.json');

$masterVars = (json_decode((string)@file_get_contents(BASE_DIR . '/sites/' . $masterId . '/data/site.json'), true) ?: [])['site_vars'] ?? [];
$masterCitySlug = $masterVars['city_slug'] ?? '';
if ($masterCitySlug === '' && !empty($masterVars['city'])) $masterCitySlug = slugify(($masterVars['city'] ?? '') . ' ' . ($masterVars['SS'] ?? ''));

if ($skipped('images')) {
    progress_log('Images: skipped — turned off for this run.', 'warn');
    $imgRes = ['stamped' => 0, 'varied' => 0, 'pruned' => 0];
} else {
    ms_step_begin('images');
    $imgRes = ms_differentiate_site_images($workingDir, $params, $masterCitySlug, $heroStyle, $imageVariation);
    if ($imgRes['stamped'] > 0 || $imgRes['varied'] > 0 || ($imgRes['pruned'] ?? 0) > 0) {
        progress_log("Images: stamped {$imgRes['stamped']} hero(s), differentiated {$imgRes['varied']} photo(s), pruned " . ($imgRes['pruned'] ?? 0) . " unreferenced.");
    }

    // Metadata scrub — its own switch under Images on the batch card, so it has its own skip
    // key. Inside the Images step because that is where the card shows it: untick Images and
    // everything beneath it stops, this included.
    // Byte-level, not re-encoding, so photos are unchanged. Only files that actually carry
    // metadata get rewritten.
    $metaRes = ['scanned' => 0, 'stripped' => 0, 'failed' => 0, 'remaining' => 0];
    if ($skipped('images.metadata')) {
        progress_log('Images: metadata strip skipped — turned off for this run.', 'warn');
    } else {
        $metaRes = ms_strip_uploads_metadata($workingDir);
    }
    if ($metaRes['stripped'] > 0 || $metaRes['failed'] > 0) {
        progress_log("Images: stripped metadata from {$metaRes['stripped']} of {$metaRes['scanned']} image(s)."
            . ($metaRes['failed'] ? " {$metaRes['failed']} could not be written." : ''),
            $metaRes['failed'] ? 'warn' : 'info');
    }
    // Verify rather than assume: every stripped file is re-read and re-checked. This should
    // be impossible, so say it loudly rather than letting it pass as a normal run.
    if ($metaRes['remaining'] > 0) {
        progress_log("Images: {$metaRes['remaining']} image(s) STILL carry metadata after stripping — investigate before deploying.", 'warn');
    }
}

// ── Build in a worker-mode child process ──────────────────────────────────────
$canonical = 'https://' . preg_replace('#^https?://#i', '', rtrim($domain, '/'));
$env = getenv();                       // inherit, then add worker vars
$env['MULTISITE_SITE_BASE']   = $workingDir;
$env['MULTISITE_OUTPUT_BASE'] = $outputDir;
$env['MULTISITE_CANONICAL']   = $canonical;
$env['MULTISITE_WEB3FORMS']   = $params['web3forms_key'] ?? '';

$cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render_site.php');
ms_step_begin('build');
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
if (!is_resource($proc)) { progress_log('Failed to spawn render worker.', 'fatal'); $cleanup(); exit(1); }

// Relay the child's JSON-lines progress straight through.
while (($line = fgets($pipes[1])) !== false) { echo $line; @flush(); }
fclose($pipes[1]);
$childErr  = stream_get_contents($pipes[2]); fclose($pipes[2]);
$buildCode = proc_close($proc);
if ($buildCode !== 0) {
    progress_log("Build worker failed (code {$buildCode}): " . trim($childErr), 'fatal');
    $cleanup();
    exit(1);
}

// ── Class vocabulary — same layout, same rules, different class names ────────
// Runs on the built output, before the SEO gate, so the gate sees what would actually ship.
// Classes any script touches are detected per build and left alone; renaming those leaves a
// page that looks right and does nothing.
if ($skipped('structure') || $skipped('structure.classvocab')) {
    progress_log('Class vocabulary: skipped — turned off for this run.', 'warn');
} else {
    $cv = ms_class_vocab_apply($outputDir, $domain);
    if ($cv['renamed'] > 0) {
        progress_log("Class vocabulary: renamed {$cv['renamed']} class(es) across {$cv['files']} file(s); "
                   . "left {$cv['skipped_js']} alone (referenced by script).");
    }
    // Should be impossible. A surviving old name means its rule moved out from under it, so
    // that element is now unstyled — worse than not renaming at all.
    if (!empty($cv['orphans'])) {
        progress_log('Class vocabulary: ' . count($cv['orphans']) . ' class(es) left half-renamed — '
                   . implode(', ', array_slice($cv['orphans'], 0, 5)) . '. Do not deploy this row.', 'warn');
    }
}

// ── Schema shape — same JSON-LD facts, arranged differently per site ─────────
// Anti-fingerprint only: Google discards key order, node order and whitespace, so this cannot
// help or hurt rankings. What it breaks is ~10KB of byte-identical structured data being the
// same on every site in the batch. Runs before the gate so the gate checks what would ship.
if ($skipped('structure') || $skipped('structure.schemashape')) {
    progress_log('Schema shape: skipped — turned off for this run.', 'warn');
} else {
    $ss = ms_schema_shape_apply($outputDir, $domain);
    if ($ss['files'] > 0) {
        progress_log("Schema shape: rearranged {$ss['blocks']} JSON-LD block(s) across {$ss['files']} file(s).");
    }
    // Only fires if a block's VALUES changed, which must never happen — the block is left
    // untouched in that case rather than shipping altered facts.
    foreach ($ss['errors'] as $e) progress_log('Schema shape: ' . $e, 'warn');
}

// ── SEO gate — objective 1, checked on the pages that were just built ────────
// Runs after the build and BEFORE deploy, so a breach is visible while the files are still
// only on this box. Reports rather than blocks for now; see ms_seo_gate_blocks().
$gate = ms_seo_gate($workingDir, $outputDir);
if ($gate['failures']) {
    progress_log('SEO gate: ' . count($gate['failures']) . ' problem(s) on ' . $gate['checked'] . ' page(s):', 'warn');
    foreach (array_slice($gate['failures'], 0, 12) as $g) progress_log('  · ' . $g, 'warn');
    if (count($gate['failures']) > 12) {
        progress_log('  · …and ' . (count($gate['failures']) - 12) . ' more.', 'warn');
    }
    if (ms_seo_gate_blocks()) {
        progress_log('SEO gate failed — not deploying this row.', 'fatal');
        $cleanup();
        exit(1);
    }
} else {
    progress_log("SEO gate: {$gate['checked']} page(s) checked, all clear.");
}
foreach (array_slice($gate['warnings'], 0, 6) as $g) progress_log('SEO gate: ' . $g, 'warn');

// ── Deploy (parent — deploy_site needs only params) ──────────────────────────
if ($noDeploy) {
    progress_log('Upload skipped — generating only. Built to: ' . $outputDir);
} elseif (!empty($params['ftp_host']) && !empty($params['ftp_user'])) {
    $ftp = [
        'ftp_protocol' => (($params['ftp_protocol'] ?? 'ftp') === 'sftp') ? 'sftp' : 'ftp',
        'ftp_host'    => $params['ftp_host'] ?? '',
        'ftp_port'    => $params['ftp_port'] ?? '',
        'ftp_user'    => $params['ftp_user'] ?? '',
        'ftp_pass'    => $params['ftp_pass'] ?? '',
        // Left unset deliberately: deploy_site() detects it after login. The
        // right answer differs per panel — shared hosting puts you above the
        // docroot, HestiaCP puts you in it — and a wrong guess uploads every
        // file successfully to a directory nothing serves. Override per row
        // with the ftp_path column when a host needs something specific.
        'ftp_path'    => $params['ftp_path'] ?? '',
        'ftp_passive' => $params['ftp_passive'] ?? true,
    ];
    // Manifest persists per-domain OUTSIDE the ephemeral build (which is deleted).
    $manifestFile = BASE_DIR . '/sites/' . $masterId . '/multisite/manifests/' . $domainSlug . '.json';
    ms_step_begin('deploy');
    $dep = deploy_site($ftp, rtrim($outputDir, '/') . '/', $manifestFile, $force);
    // A connect/login failure is fatal; so is a partial upload — some files failing
    // means the deployed site is incomplete, so the row must not be reported ok.
    if (($dep['status'] ?? '') === 'fatal' || (int)($dep['failed'] ?? 0) > 0) {
        if ((int)($dep['failed'] ?? 0) > 0) progress_log("Deploy incomplete — {$dep['failed']} file(s) failed to upload.", 'fatal');
        $cleanup();
        exit(1);
    }
} else {
    progress_log('No FTP creds in row — skipping deploy (build only).', 'warn');
}

// ── Cleanup ──────────────────────────────────────────────────────────────────
$cleanup();
progress_log("Row complete: {$domain}", 'done');
exit(0);
