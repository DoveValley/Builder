<?php
/**
 * The steps a batch run goes through, and whether each one is set up.
 *
 * Two separate questions live here, and keeping them apart is the whole point:
 *
 *   1. WHAT ARE THE STEPS  — ms_run_steps(): a fixed, documented list, in the order
 *      build_one.php actually performs them, each saying where you'd change it.
 *   2. IS IT SET UP        — ms_step_readiness(): facts read from the master and the
 *      batch's target list, computed BEFORE anything runs.
 *
 * Deliberately NOT a gate. Nothing here blocks a run — building without deploying is
 * a real thing you do on purpose. Cells report facts ("4 presets, 3 in rotation")
 * rather than verdicts ("ready ✓"), because every check here can only see that a
 * thing EXISTS. It cannot see whether the thing is any good.
 *
 * The third column — what actually executed — reads build_one.php's ms_step_begin()
 * tags via ms_step_execution() below. Every key here must match a real ms_step_begin()
 * call in build_one.php exactly: ms_step_begin() silently drops any key not listed in
 * ms_run_steps(), so a step added to one without the other doesn't error — it just
 * misattributes that step's failures to whichever real step ran last before it.
 */

require_once __DIR__ . '/params.php';
require_once __DIR__ . '/batch.php';
require_once __DIR__ . '/landing.php';
require_once __DIR__ . '/image_overlay.php';   // ms_image_settings_locate()

/** Cell states. 'off' = will be skipped, which is often deliberate, so it is not a warning. */
const MS_STEP_OK   = 'ok';
const MS_STEP_WARN = 'warn';
const MS_STEP_OFF  = 'off';

/**
 * The canonical steps, in run order.
 * @return array<int,array{key:string,label:string,does:string,where:string}>
 */
function ms_run_steps(): array {
    return [
        ['key' => 'clone',         'label' => 'Clone',
         'does' => 'Copy the master site for this row.',
         'where' => 'The master site itself'],

        ['key' => 'identity',      'label' => 'Identity',
         'does' => 'Swap in this site\'s name, phone, email and domain.',
         'where' => 'Your target list — business, phone, email, domain'],

        ['key' => 'landing',       'label' => 'Landing pages',
         'does' => 'Build extra city pages for this site.',
         'where' => 'The landing_cities column + Landing Templates on the master'],

        ['key' => 'differentiate', 'label' => 'Differentiate',
         'does' => 'Schema, map coordinates, analytics and Search Console tags.',
         'where' => 'The analytics_id / gsc_verification / lat / lng columns'],

        ['key' => 'visual',        'label' => 'Visual identity',
         'does' => 'Give the site its own colours, logo and favicon.',
         'where' => 'Theme tab on the master + the theme_preset column'],

        ['key' => 'ai',            'label' => 'AI content',
         'does' => 'Write this city\'s copy into the AI blocks.',
         'where' => 'Niche Brief and Block Registry on the master'],

        ['key' => 'images',        'label' => 'Images',
         'does' => 'Vary the photos so no two sites look alike.',
         'where' => 'Media Library on the master'],

        ['key' => 'build',         'label' => 'Build',
         'does' => 'Render the actual pages, sitemap and robots.txt.',
         'where' => 'Rarely — this is the renderer'],

        ['key' => 'deploy',        'label' => 'Deploy',
         'does' => 'Upload the built site to its host over FTP/SFTP.',
         'where' => 'The ftp_host / ftp_user / ftp_pass columns'],

];
}

/** Step keys, in order. */
function ms_step_keys(): array {
    return array_column(ms_run_steps(), 'key');
}

/** The label for a step key ('' when unknown). */
function ms_step_label(string $key): string {
    foreach (ms_run_steps() as $s) if ($s['key'] === $key) return $s['label'];
    return '';
}

/**
 * Announce that a step is starting. Called by build_one.php as it works, so a running
 * row can say which step it is on and a failed row can say which step it died in.
 *
 * Emitted as a distinct event type rather than by matching the English of the ordinary
 * log lines — a progress bar that depends on message wording silently stops working the
 * day someone rephrases a message.
 */
function ms_step_begin(string $key): void {
    if (!in_array($key, ms_step_keys(), true)) return;   // never invent a step
    if (function_exists('progress_emit')) {
        progress_emit(['type' => 'step', 'step' => $key, 'msg' => ms_step_label($key)]);
    }
}

/**
 * What actually executed, per step, in a given run.
 *
 * Reads the per-row `steps` / `step` fields written by run_campaign.php. A row that
 * never reached a step simply is not counted for it — so a step legitimately skipped
 * (no landing cities, no FTP) reads as 0, the same as the readiness column predicted.
 *
 * @return array<string,array{ran:int,failed:int,total:int}> keyed by step key
 */
function ms_step_execution(?array $run): array {
    $out = [];
    foreach (ms_step_keys() as $k) $out[$k] = ['ran' => 0, 'failed' => 0, 'total' => 0];
    if (!$run || !is_array($run['results'] ?? null)) return $out;

    $total = count($run['results']);
    foreach ($out as $k => $_) $out[$k]['total'] = $total;

    foreach ($run['results'] as $r) {
        foreach ((array) ($r['steps'] ?? []) as $k) {
            if (isset($out[$k])) $out[$k]['ran']++;
        }
        // Where a failed row stopped — this is the column that tells you what to fix.
        if (($r['status'] ?? '') === 'failed') {
            $k = (string) ($r['step'] ?? '');
            if ($k !== '' && isset($out[$k])) $out[$k]['failed']++;
        }
    }
    return $out;
}

/**
 * Small helper — one readiness cell.
 * $items is an optional breakdown of the things this step works on.
 */
function ms_step_cell(string $state, string $fact, string $note = '', array $items = []): array {
    return ['state' => $state, 'fact' => $fact, 'note' => $note, 'items' => $items];
}

/** One line inside a step: what it is, what it does, and where it stands. */
function ms_item(string $label, string $drives, string $value, string $state = MS_STEP_OK): array {
    return ['label' => $label, 'drives' => $drives, 'value' => $value, 'state' => $state];
}

/**
 * An item measured across the target list. Required and empty is a problem; optional
 * and empty is not — it just means every site keeps whatever the master already had.
 */
function ms_item_rows(string $label, string $drives, int $filled, int $total, bool $required = true): array {
    // With no target list yet the fields still list themselves — that is when knowing
    // which columns your sheet needs is most useful, so the table doubles as the spec.
    if ($total === 0) {
        return ms_item($label, $drives, $required ? 'needed' : 'optional', MS_STEP_OFF);
    }
    if ($filled === $total)  $state = MS_STEP_OK;
    elseif ($filled === 0)   $state = $required ? MS_STEP_WARN : MS_STEP_OFF;
    else                     $state = MS_STEP_WARN;
    return ms_item($label, $drives, $filled . ' of ' . $total, $state);
}

/**
 * The fields Identity actually swaps into each clone, in the order inject.php uses them.
 * See inject_params_into_working_dir(): nine go straight into site_vars, and two more
 * are derived (website from domain, city_slug from city). Anything not listed here is
 * not part of a site's identity, however useful it is elsewhere.
 */
function ms_identity_fields(): array {
    return [
        ['col' => 'business', 'drives' => 'Business name + the header',   'required' => true],
        ['col' => 'domain',   'drives' => 'The website address',          'required' => true],
        ['col' => 'phone',    'drives' => 'Phone number',                 'required' => true],
        ['col' => 'email',    'drives' => 'Email address &mdash; blank is fine unless the master shows one', 'required' => false],
        ['col' => 'city',     'drives' => 'City + its url slug',          'required' => true],
        ['col' => 'state',    'drives' => 'State name',                   'required' => true],
        ['col' => 'SS',       'drives' => 'State abbreviation',           'required' => true],
        ['col' => 'tel',      'drives' => 'tel: links — derived from phone when blank', 'required' => false],
        ['col' => 'address',  'drives' => 'Street address',               'required' => false],
        ['col' => 'zip',      'drives' => 'Postcode',                     'required' => false],
    ];
}

/**
 * Is each step set up? Reads the master and this batch's target list.
 * @return array<string,array{state:string,fact:string,note:string}> keyed by step key
 */
function ms_step_readiness(string $masterId, string $batchId): array {
    $siteDir   = BASE_DIR . '/sites/' . $masterId;
    $masterDir = ms_master_dir($masterId);
    $batchDir  = ms_batch_dir($masterId, $batchId);

    $readJson = function (string $f) {
        $d = @json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : [];
    };

    // ── The target list, parsed once and shared by several checks ──────────────
    $csv   = $batchDir . '/params.csv';
    $rows  = [];
    $rowErrors = 0;
    if (is_file($csv)) {
        $p = ms_parse_csv($csv);
        if (empty($p['error'])) {
            $rows = $p['rows'];
            $v = ms_validate_rows($rows, $p['header']);
            $rowErrors = (int) $v['error'];
        }
    }
    $n = count($rows);
    $countWhere = function (callable $f) use ($rows) {
        return count(array_filter($rows, $f));
    };

    $out = [];

    // ── Clone ─────────────────────────────────────────────────────────────────
    // Pages live in two disjoint stores and both are real: site.json['pages'] holds the
    // hand-built core pages, while generated landing pages are one file each in
    // data/pages/ indexed by page-index.json. page.php checks the index first and falls
    // back to the core pages, so counting only one of them badly understates the master.
    $site      = $readJson($siteDir . '/data/site.json');
    $corePages = count($site['pages'] ?? []);
    $genPages  = count($readJson($siteDir . '/data/page-index.json'));
    $pages     = $corePages + $genPages;
    $posts   = count($site['posts'] ?? []);
    $uploads = count(array_filter(glob($siteDir . '/uploads/*') ?: [], 'is_file'))
             + count(array_filter(glob($siteDir . '/uploads/media/*') ?: [], 'is_file'));
    $cloneItems = [
        ms_item('home page',     'The front page — always copied', '1'),
        ms_item('core pages',    'Privacy, terms, contact and the like', (string) $corePages, $corePages > 0 ? MS_STEP_OK : MS_STEP_OFF),
        ms_item('landing pages', 'Generated city pages', (string) $genPages, $genPages > 0 ? MS_STEP_OK : MS_STEP_OFF),
        ms_item('blog posts',    'Carried over as-is', (string) $posts, $posts > 0 ? MS_STEP_OK : MS_STEP_OFF),
        ms_item('uploads',       'Shared by symlink, not copied', $uploads . ' files', $uploads > 0 ? MS_STEP_OK : MS_STEP_WARN),
        ms_item('deploy config', 'Stripped — each site gets its own', 'removed'),
    ];
    $out['clone'] = $pages === 0
        ? ms_step_cell(MS_STEP_WARN, 'Master has only a home page', 'Every clone will be a single-page site.', $cloneItems)
        : ms_step_cell(MS_STEP_OK, 'Master has a home page + ' . $pages . ' page' . ($pages === 1 ? '' : 's'), '', $cloneItems);

    // ── Identity ──────────────────────────────────────────────────────────────
    // Reported field by field: "5 rows, all columns filled" hides which of the ten
    // pieces of a site's identity is actually missing on which rows.
    $idItems = [];
    foreach (ms_identity_fields() as $f) {
        $filled = $countWhere(fn($r) => trim((string) ($r[$f['col']] ?? '')) !== '');
        $idItems[] = ms_item_rows($f['col'], $f['drives'], $filled, $n, $f['required']);
    }
    if (!is_file($csv)) {
        $out['identity'] = ms_step_cell(MS_STEP_WARN, 'No target list uploaded yet', 'These are the columns your CSV needs.', $idItems);
    } elseif ($rowErrors > 0) {
        $out['identity'] = ms_step_cell(MS_STEP_WARN, $n . ' rows · ' . $rowErrors . ' with errors', '', $idItems);
    } else {
        $missingOptional = 0;
        foreach (ms_identity_fields() as $f) {
            if ($f['required']) continue;
            if ($countWhere(fn($r) => trim((string) ($r[$f['col']] ?? '')) !== '') < $n) $missingOptional++;
        }
        $out['identity'] = ms_step_cell(
            MS_STEP_OK,
            $n . ' row' . ($n === 1 ? '' : 's') . ', every required field filled',
            $missingOptional > 0 ? $missingOptional . ' optional field(s) are blank on some rows — those keep the master\'s value.' : '',
            $idItems
        );
    }

    // ── Landing pages ─────────────────────────────────────────────────────────
    $withCities = $countWhere(fn($r) => trim((string) ($r['landing_cities'] ?? '')) !== '');
    $templates  = count($readJson($siteDir . '/data/templates.json'));
    // Total city-pages this batch would generate: cities requested × templates.
    // Goes through the real ms_parse_landing_cities() (splits on newlines too, not
    // just ';', validates "City, ST" shape, and de-dupes) rather than a naive
    // explode(';') — a malformed or duplicate-heavy value used to over/undercount
    // here relative to what the build step actually produces.
    $cityCount = 0;
    foreach ($rows as $r) {
        $v = trim((string) ($r['landing_cities'] ?? ''));
        if ($v !== '') $cityCount += count(ms_parse_landing_cities($v));
    }
    $landItems = [
        ms_item_rows('landing_cities', 'Which cities each site gets its own page for', $withCities, $n, false),
        ms_item('templates',  'The page patterns each city is built from', (string) $templates, $templates > 0 ? MS_STEP_OK : MS_STEP_WARN),
        ms_item('cities asked for', 'Total across every row', (string) $cityCount, $cityCount > 0 ? MS_STEP_OK : MS_STEP_OFF),
        ms_item('pages this makes', 'Cities × templates, added to every site that asks',
                $cityCount && $templates ? (string) ($cityCount * $templates) : '0',
                $cityCount && $templates ? MS_STEP_OK : MS_STEP_OFF),
    ];
    // Landing pages are OPT-IN and default to off: a row gets them only if it names
    // cities. So a batch where some or no rows ask is normal, not a shortfall — it
    // reads grey, and only turns green when every row has actually opted in. The one
    // genuine fault is a row asking for pages the master has no template to build.
    if ($n === 0) {
        $out['landing'] = ms_step_cell(MS_STEP_OFF, 'Off — no target list yet', 'Landing pages are opt-in: a site gets them only if its row names cities.', $landItems);
    } elseif ($withCities === 0) {
        $out['landing'] = ms_step_cell(MS_STEP_OFF, 'Off — no row asks for landing pages', 'Every site gets its home + core pages only.', $landItems);
    } elseif ($templates === 0) {
        $out['landing'] = ms_step_cell(MS_STEP_WARN, $withCities . ' of ' . $n . ' rows ask for landing pages, but the master has no templates', '', $landItems);
    } elseif ($withCities < $n) {
        $out['landing'] = ms_step_cell(MS_STEP_OFF, 'On for ' . $withCities . ' of ' . $n . ' rows', 'The other ' . ($n - $withCities) . ' get home + core pages only — normal unless you meant them all to have city pages.', $landItems);
    } else {
        $out['landing'] = ms_step_cell(MS_STEP_OK, 'On for all ' . $n . ' rows · ' . $templates . ' templates on the master', '', $landItems);
    }

    // ── Differentiate ─────────────────────────────────────────────────────────
    // Always runs (schema + geo). Analytics and Search Console are opt-in per row.
    // The five things ms_differentiate_working_dir() actually does, in its own order.
    $withGa   = $countWhere(fn($r) => trim((string) ($r['analytics_id'] ?? '')) !== '');
    $withGsc  = $countWhere(fn($r) => trim((string) ($r['gsc_verification'] ?? '')) !== '');
    $withGeo  = $countWhere(fn($r) => trim((string) ($r['lat'] ?? '')) !== '' && trim((string) ($r['lng'] ?? '')) !== '');
    $layoutOn = !empty($site['layout_enabled']) && !empty($site['layout_variants']);
    $variants = count($site['layout_variants'] ?? []);
    $diffItems = [
        ms_item('identity scrub', 'The master\'s own name and domain removed everywhere', 'every row'),
        ms_item('fake ratings',   'Invented review scores stripped from schema', 'every row'),
        ms_item_rows('lat / lng', 'Map coordinates on the LocalBusiness schema', $withGeo, $n, false),
        ms_item_rows('analytics_id', 'A per-site tag — never shared between sites', $withGa, $n, false),
        ms_item_rows('gsc_verification', 'Per-site Search Console tag', $withGsc, $n, false),
        ms_item('layout variation', 'Block order differs per domain',
                $layoutOn ? ($variants + 1) . ' orderings' : 'off',
                $layoutOn ? MS_STEP_OK : MS_STEP_OFF),
    ];
    $out['differentiate'] = ms_step_cell(
        MS_STEP_OK,
        'Schema + identity scrub run for every row',
        $withGeo === 0 && $n > 0 ? 'No row supplies lat/lng — coordinates come from the master\'s researched cities instead.' : '',
        $diffItems
    );

    // ── Visual identity ───────────────────────────────────────────────────────
    $presets   = $readJson($masterDir . '/theme_presets.json')['presets'] ?? [];
    $inRotation = count(array_filter($presets, fn($p) => ($p['in_rotation'] ?? true) !== false));
    $keys    = ms_master_preset_keys($masterId);
    $pinned  = $countWhere(fn($r) => trim((string) ($r['theme_preset'] ?? '')) !== '');
    $bad     = $countWhere(function ($r) use ($keys) {
        $v = strtolower(trim((string) ($r['theme_preset'] ?? '')));
        return $v !== '' && !in_array($v, $keys, true);
    });
    $withIcon = count(array_filter($presets, fn($p) => !empty($p['icon'])));
    $visItems = [
        ms_item('presets',      'Colour + font sets the build rotates through', (string) count($presets), $presets ? MS_STEP_OK : MS_STEP_WARN),
        ms_item('in rotation',  'Only these are handed out automatically', (string) $inRotation, $inRotation > 0 ? MS_STEP_OK : MS_STEP_WARN),
        ms_item_rows('theme_preset', 'Pins one site to one preset instead of rotating', $pinned, $n, false),
        ms_item('logo',         'A two-tone wordmark drawn per site', 'every row'),
        ms_item('favicon',      'Bug mark, taken from the preset\'s icon',
                $withIcon . ' of ' . count($presets) . ' presets', $withIcon > 0 ? MS_STEP_OK : MS_STEP_OFF),
    ];
    if (!$presets) {
        $out['visual'] = ms_step_cell(MS_STEP_WARN, 'No presets on the master', 'Every site gets the master\'s own colours.', $visItems);
    } else {
        $fact = count($presets) . ' preset' . (count($presets) === 1 ? '' : 's') . ', ' . $inRotation . ' in rotation';
        $out['visual'] = $bad > 0
            ? ms_step_cell(MS_STEP_WARN, $fact, $bad . ' row(s) name a preset the master does not have — those are ignored.', $visItems)
            : ms_step_cell(MS_STEP_OK, $fact, '', $visItems);
    }

    // ── AI content ────────────────────────────────────────────────────────────
    $hasKey     = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
    $brief      = $readJson($masterDir . '/niche_brief.json');
    $blockTypes = count($readJson($siteDir . '/data/ai_block_types.json'));
    $cached     = count(glob($masterDir . '/cache/*.json') ?: []);
    $research   = !empty($brief['uses_research_fields']);
    $cities     = $readJson($siteDir . '/data/cities.json');
    $researched = count(array_filter($cities, fn($c) => !empty($c['neighborhoods']) || !empty($c['population'])));
    $aiItems = [
        ms_item('API key',    'Without it this whole step is skipped', $hasKey ? 'configured' : 'missing', $hasKey ? MS_STEP_OK : MS_STEP_OFF),
        ms_item('niche brief','Vocabulary, tone and guardrails', $brief ? 'set' : 'missing', $brief ? MS_STEP_OK : MS_STEP_WARN),
        ms_item('block registry', 'Which blocks get AI copy written into them',
                $blockTypes . ' types', $blockTypes > 0 ? MS_STEP_OK : MS_STEP_WARN),
        ms_item('city research', 'Real local facts, looked up once and reused free',
                $research ? ($researched . ' of ' . count($cities) . ' cities') : 'not used by this niche',
                $research ? ($researched > 0 ? MS_STEP_OK : MS_STEP_WARN) : MS_STEP_OFF),
        ms_item('cache',      'Copy already written, reused instead of re-billed',
                $cached . ' site' . ($cached === 1 ? '' : 's'), $cached > 0 ? MS_STEP_OK : MS_STEP_OFF),
    ];
    if (!$hasKey) {
        $out['ai'] = ms_step_cell(MS_STEP_OFF, 'No API key configured', 'AI content is skipped; everything else still runs.', $aiItems);
    } elseif (!$brief) {
        $out['ai'] = ms_step_cell(MS_STEP_WARN, 'No niche brief on the master', 'The AI has no vocabulary or guardrails to work from.', $aiItems);
    } elseif ($blockTypes === 0) {
        $out['ai'] = ms_step_cell(MS_STEP_WARN, 'Niche brief set, but no block registry compiled', 'Compile it on the Niche Brief tab.', $aiItems);
    } else {
        $out['ai'] = ms_step_cell(MS_STEP_OK, 'Brief set · ' . $blockTypes . ' AI block type' . ($blockTypes === 1 ? '' : 's') . ' · key configured', '', $aiItems);
    }

    // ── Images ────────────────────────────────────────────────────────────────
    $media = array_filter(glob($siteDir . '/uploads/media/*') ?: [], 'is_file');
    // Resolve the hero style the same way the build does — per-master first, then
    // the shared global. This used to check only the per-master path, so it read
    // "default" even when a style was really in effect. ms_image_settings_locate()
    // is the single place that order lives (includes/multisite/image_overlay.php).
    $heroStyleScope = ms_image_settings_locate($siteDir, 'hero_style.json')['scope'];
    $withCityImg   = count(array_filter($cities, fn($c) => !empty($c['city_image'])));
    $imgItems = [
        ms_item('media library', 'The pool photos are varied from', (string) count($media), $media ? MS_STEP_OK : MS_STEP_WARN),
        ms_item('hero text',     'City name stamped onto the hero image',
                $heroStyleScope === 'master' ? 'styled' : ($heroStyleScope === 'global' ? 'styled (shared)' : 'default'), MS_STEP_OK),
        ms_item('city photos',   'A real photo of the city, where one was fetched',
                $cities ? ($withCityImg . ' of ' . count($cities) . ' cities') : 'no cities yet',
                $withCityImg > 0 ? MS_STEP_OK : MS_STEP_OFF),
        ms_item('prune',         'Images no page references are dropped from the build', 'every row'),
    ];
    $out['images'] = $media
        ? ms_step_cell(MS_STEP_OK, count($media) . ' images in the master\'s media library', '', $imgItems)
        : ms_step_cell(MS_STEP_WARN, 'No images in the master\'s media library', 'Sites will share whatever images the master already uses.', $imgItems);

    // ── Build ─────────────────────────────────────────────────────────────────
    $out['build'] = ms_step_cell(MS_STEP_OK, 'Always runs', '', [
        ms_item('pages',    'Every page rendered to static HTML', 'every row'),
        ms_item('sitemap',  'sitemap.xml listing what was built', 'every row'),
        ms_item('robots',   'robots.txt pointing at the sitemap', 'every row'),
    ]);

    // Deploy's readiness is computed by the deploy section, not here — this table
    // stopped listing it, and leaving the calculation behind would be work done for a
    // row nobody renders.

    return $out;
}
