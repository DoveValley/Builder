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
 * The third column — what actually executed — needs build_one.php to tag its progress
 * lines with a step key. Not built yet; these keys are the vocabulary for it.
 */

require_once __DIR__ . '/params.php';
require_once __DIR__ . '/batch.php';

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
         'does' => 'Upload the finished site to its host.',
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
 * $items is an optional per-field breakdown: [['label','drives','filled','total','required'], ...]
 */
function ms_step_cell(string $state, string $fact, string $note = '', array $items = []): array {
    return ['state' => $state, 'fact' => $fact, 'note' => $note, 'items' => $items];
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
        ['col' => 'email',    'drives' => 'Email address',                'required' => true],
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
    if ($pages === 0) {
        $out['clone'] = ms_step_cell(MS_STEP_WARN, 'Master has only a home page', 'Every clone will be a single-page site.');
    } else {
        $breakdown = $genPages > 0
            ? $corePages . ' core, ' . $genPages . ' generated'
            : $corePages . ' core';
        $out['clone'] = ms_step_cell(
            MS_STEP_OK,
            'Master has a home page + ' . $pages . ' page' . ($pages === 1 ? '' : 's'),
            $breakdown
        );
    }

    // ── Identity ──────────────────────────────────────────────────────────────
    // Reported field by field: "5 rows, all columns filled" hides which of the ten
    // pieces of a site's identity is actually missing on which rows.
    $idItems = [];
    foreach (ms_identity_fields() as $f) {
        $filled = $countWhere(fn($r) => trim((string) ($r[$f['col']] ?? '')) !== '');
        $idItems[] = [
            'label'    => $f['col'],
            'drives'   => $f['drives'],
            'filled'   => $filled,
            'total'    => $n,
            'required' => $f['required'],
        ];
    }
    if (!is_file($csv)) {
        $out['identity'] = ms_step_cell(MS_STEP_WARN, 'No target list uploaded yet', 'Upload a CSV below to fill this in.');
    } elseif ($rowErrors > 0) {
        $out['identity'] = ms_step_cell(MS_STEP_WARN, $n . ' rows · ' . $rowErrors . ' with errors', '', $idItems);
    } else {
        $missingOptional = count(array_filter($idItems, fn($i) => !$i['required'] && $i['filled'] < $n));
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
    if ($n === 0) {
        $out['landing'] = ms_step_cell(MS_STEP_OFF, 'No target list yet');
    } elseif ($withCities === 0) {
        $out['landing'] = ms_step_cell(MS_STEP_OFF, 'No row asks for landing pages', 'This step is skipped — home + core pages only.');
    } elseif ($templates === 0) {
        $out['landing'] = ms_step_cell(MS_STEP_WARN, $withCities . ' of ' . $n . ' rows ask for landing pages, but the master has no templates');
    } else {
        $out['landing'] = ms_step_cell(MS_STEP_OK, $withCities . ' of ' . $n . ' rows ask for landing pages · ' . $templates . ' templates on the master');
    }

    // ── Differentiate ─────────────────────────────────────────────────────────
    // Always runs (schema + geo). Analytics and Search Console are opt-in per row.
    $withGa  = $countWhere(fn($r) => trim((string) ($r['analytics_id'] ?? '')) !== '');
    $withGsc = $countWhere(fn($r) => trim((string) ($r['gsc_verification'] ?? '')) !== '');
    $out['differentiate'] = ms_step_cell(
        MS_STEP_OK,
        'Schema + map coordinates run for every row',
        $n > 0 ? ('Analytics id on ' . $withGa . ' of ' . $n . ' · Search Console token on ' . $withGsc . ' of ' . $n) : ''
    );

    // ── Visual identity ───────────────────────────────────────────────────────
    $presets   = $readJson($masterDir . '/theme_presets.json')['presets'] ?? [];
    $inRotation = count(array_filter($presets, fn($p) => ($p['in_rotation'] ?? true) !== false));
    if (!$presets) {
        $out['visual'] = ms_step_cell(MS_STEP_WARN, 'No presets on the master', 'Every site gets the master\'s own colours.');
    } else {
        // A theme_preset value that does not match anything on the master is ignored silently.
        $keys = ms_master_preset_keys($masterId);
        $bad  = $countWhere(function ($r) use ($keys) {
            $v = strtolower(trim((string) ($r['theme_preset'] ?? '')));
            return $v !== '' && !in_array($v, $keys, true);
        });
        $fact = count($presets) . ' preset' . (count($presets) === 1 ? '' : 's') . ', ' . $inRotation . ' in rotation';
        $out['visual'] = $bad > 0
            ? ms_step_cell(MS_STEP_WARN, $fact, $bad . ' row(s) name a preset the master does not have — those are ignored.')
            : ms_step_cell(MS_STEP_OK, $fact);
    }

    // ── AI content ────────────────────────────────────────────────────────────
    $hasKey     = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
    $brief      = $readJson($masterDir . '/niche_brief.json');
    $blockTypes = count($readJson($siteDir . '/data/ai_block_types.json'));
    if (!$hasKey) {
        $out['ai'] = ms_step_cell(MS_STEP_OFF, 'No API key configured', 'AI content is skipped; everything else still runs.');
    } elseif (!$brief) {
        $out['ai'] = ms_step_cell(MS_STEP_WARN, 'No niche brief on the master', 'The AI has no vocabulary or guardrails to work from.');
    } elseif ($blockTypes === 0) {
        $out['ai'] = ms_step_cell(MS_STEP_WARN, 'Niche brief set, but no block registry compiled', 'Compile it on the Niche Brief tab.');
    } else {
        $out['ai'] = ms_step_cell(MS_STEP_OK, 'Brief set · ' . $blockTypes . ' AI block type' . ($blockTypes === 1 ? '' : 's') . ' · key configured');
    }

    // ── Images ────────────────────────────────────────────────────────────────
    $media = glob($siteDir . '/uploads/media/*') ?: [];
    $media = array_filter($media, 'is_file');
    $out['images'] = $media
        ? ms_step_cell(MS_STEP_OK, count($media) . ' images in the master\'s media library')
        : ms_step_cell(MS_STEP_WARN, 'No images in the master\'s media library', 'Sites will share whatever images the master already uses.');

    // ── Build ─────────────────────────────────────────────────────────────────
    $out['build'] = ms_step_cell(MS_STEP_OK, 'Always runs');

    // ── Deploy ────────────────────────────────────────────────────────────────
    $withFtp = $countWhere(fn($r) => trim((string) ($r['ftp_host'] ?? '')) !== ''
                                  && trim((string) ($r['ftp_user'] ?? '')) !== ''
                                  && trim((string) ($r['ftp_pass'] ?? '')) !== '');
    if ($n === 0) {
        $out['deploy'] = ms_step_cell(MS_STEP_OFF, 'No target list yet');
    } elseif ($withFtp === 0) {
        $out['deploy'] = ms_step_cell(MS_STEP_OFF, 'No row has FTP credentials', 'Sites will be built for review but not uploaded.');
    } elseif ($withFtp < $n) {
        $out['deploy'] = ms_step_cell(MS_STEP_WARN, $withFtp . ' of ' . $n . ' rows have FTP credentials', 'The other ' . ($n - $withFtp) . ' build but do not upload.');
    } else {
        $out['deploy'] = ms_step_cell(MS_STEP_OK, 'All ' . $n . ' rows have FTP credentials');
    }

    return $out;
}
