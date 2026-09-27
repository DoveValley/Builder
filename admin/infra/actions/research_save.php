<?php
/**
 * infra/actions/research_save.php — City Research writes (CSRF, PRG).
 * action = start | run | delete
 *
 * Standalone from the rest of the app on purpose: state lives in its own JSON
 * files (lib/research.php's state/research/ dir), never in fleet.db, and this
 * never writes to city_niche or params.csv. See lib/research.php's header.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/research.php';

$back = '../index.php?view=research';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$action = (string) ($_POST['action'] ?? '');

/* ---- save the form's current field values, no money spent, no run started --- */
if ($action === 'save_draft') {
    $niche = infra_niche_slug((string) ($_POST['niche'] ?? ''));
    if ($niche === '' || !isset(infra_niches()[$niche])) {
        infra_set_flash('err', 'Pick a niche first.');
        header('Location: ' . $back); exit;
    }
    $fields = [
        'patterns'       => (string) ($_POST['patterns'] ?? ''),
        'elocal_paste'   => (string) ($_POST['elocal_paste'] ?? ''),
        'pop_min'        => (string) ($_POST['pop_min'] ?? ''),
        'pop_max'        => (string) ($_POST['pop_max'] ?? ''),
        'min_buyers'     => (string) ($_POST['min_buyers'] ?? ''),
        'min_price'      => (string) ($_POST['min_price'] ?? ''),
        'min_volume'     => (string) ($_POST['min_volume'] ?? ''),
        'max_rivals'     => (string) ($_POST['max_rivals'] ?? ''),
        'sep_mi'         => (string) ($_POST['sep_mi'] ?? ''),
        'state_cap_pct'  => (string) ($_POST['state_cap_pct'] ?? ''),
        'provider'       => (string) ($_POST['provider'] ?? ''),
    ];
    // A chosen file is HELD until a different one replaces it - a save with no new
    // upload attached must carry the previously-held file's name forward rather than
    // silently dropping it (there's nothing to "keep the old file" about at the
    // filesystem level - infra_research_persist_elocal_upload() only ever touches the
    // fixed per-niche path when there's an actual new upload, so the old bytes are
    // already untouched; this is just keeping the UI's record of it in sync).
    $persistedName = infra_research_persist_elocal_upload($niche, $_FILES['elocal_csv'] ?? null);
    if ($persistedName !== null) {
        $fields['elocal_csv_name'] = $persistedName;
    } else {
        $existing = infra_research_load_draft($niche);
        if (!empty($existing['elocal_csv_name'])) $fields['elocal_csv_name'] = $existing['elocal_csv_name'];
    }
    infra_research_save_draft($niche, $fields);
    infra_set_flash('ok', 'Saved — nothing run, no money spent.');
    header('Location: ' . $back . '&niche=' . urlencode($niche)); exit;
}

/* ---- start a new run: parse eLocal, filter, match, create the run file --- */
if ($action === 'start') {
    $niche = infra_niche_slug((string) ($_POST['niche'] ?? ''));
    if ($niche === '' || !isset(infra_niches()[$niche])) {
        infra_set_flash('err', 'Pick a niche first.');
        header('Location: ' . $back); exit;
    }

    $patterns = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) ($_POST['patterns'] ?? '')) as $l) {
        $l = trim($l);
        if ($l !== '') $patterns[] = $l;
    }
    if (!$patterns) {
        infra_set_flash('err', 'Enter at least one keyword pattern (use {city} as a placeholder).');
        header('Location: ' . $back . '&niche=' . urlencode($niche)); exit;
    }

    $parsed = infra_research_parse_elocal((string) ($_POST['elocal_paste'] ?? ''), $_FILES['elocal_csv'] ?? null,
        infra_research_draft_elocal_path($niche));
    if ($parsed['errors']) {
        infra_set_flash('err', implode(' ', $parsed['errors']));
        header('Location: ' . $back . '&niche=' . urlencode($niche)); exit;
    }
    // Held file is read (not moved) by the parse above, so it's still there to persist
    // afterward — a new upload this run replaces whatever was held for next time,
    // same as Save does; running with no new upload leaves the held file untouched.
    $persistedName = infra_research_persist_elocal_upload($niche, $_FILES['elocal_csv'] ?? null);
    if ($persistedName !== null) {
        $draftNow = infra_research_load_draft($niche) ?? [];
        $draftNow['elocal_csv_name'] = $persistedName;
        infra_research_save_draft($niche, $draftNow);
    }

    $popMin   = (int) ($_POST['pop_min'] ?? 30000);
    $popMax   = (int) ($_POST['pop_max'] ?? 400000);
    $minBuy   = (float) ($_POST['min_buyers'] ?? 2);
    $minPrice = (float) ($_POST['min_price'] ?? 250);
    $minVol   = (float) ($_POST['min_volume'] ?? 100);
    $maxRivalsRaw = trim((string) ($_POST['max_rivals'] ?? ''));
    $maxRivals = $maxRivalsRaw === '' ? null : (float) $maxRivalsRaw;
    $sepMi    = (float) ($_POST['sep_mi'] ?? 10);
    $capPct   = (float) ($_POST['state_cap_pct'] ?? 8) / 100;
    $provider = (string) ($_POST['provider'] ?? 'ahrefs');

    $candidates = [];
    $rejectedMoney = 0; $unmatched = 0;
    foreach ($parsed['rows'] as $row) {
        if ($row['buyers'] < $minBuy || $row['price_avg'] < $minPrice) { $rejectedMoney++; continue; }
        $city = infra_research_match_city($row['city'], $row['state']);
        if (!$city) { $unmatched++; continue; }
        $pop = (int) $city['population'];
        if ($pop < $popMin || $pop > $popMax) { $rejectedMoney++; continue; }
        $id = $city['id'];
        $candidates[$id] = [
            '_id' => $id,
            'city' => $city['city'], 'state' => $city['state'], 'ss' => $city['ss'],
            'population' => $pop,
            'lat' => $city['lat'] !== '' ? (float) $city['lat'] : null,
            'lng' => $city['lng'] !== '' ? (float) $city['lng'] : null,
            'buyers' => $row['buyers'], 'price_avg' => $row['price_avg'], 'price_max' => $row['price_max'],
            'volume' => null,
            'serp_open_sum' => 0.0, 'serp_local_sum' => 0.0, 'serp_natl_sum' => 0.0, 'serp_patterns_done' => 0,
        ];
    }

    if (!$candidates) {
        infra_set_flash('err', 'Nothing survived the money filter — ' . $rejectedMoney . ' rejected on population/buyers/price, '
            . $unmatched . ' could not be matched to a known city (check city/state spelling).');
        header('Location: ' . $back . '&niche=' . urlencode($niche)); exit;
    }

    $run = [
        'id' => infra_research_new_run_id($niche),
        'niche' => $niche,
        'patterns' => $patterns,
        'provider' => $provider,
        'filters' => [
            'pop_min' => $popMin, 'pop_max' => $popMax, 'min_buyers' => $minBuy, 'min_price' => $minPrice,
            'min_volume' => $minVol, 'max_rivals' => $maxRivals, 'sep_mi' => $sepMi, 'state_cap_pct' => $capPct,
        ],
        'phase' => 'volume',
        'created_at' => date('c'),
        'candidates' => $candidates,
        'result_file' => null,
        'stats' => ['from_elocal' => count($parsed['rows']), 'rejected_money' => $rejectedMoney, 'unmatched' => $unmatched],
    ];
    infra_research_save_run($run);
    infra_set_flash('ok', count($candidates) . ' cities cleared the money filter (' . $rejectedMoney . ' rejected, '
        . $unmatched . ' not matched to a known city). Press Continue to fetch volume.');
    header('Location: ' . $back . '&niche=' . urlencode($niche) . '&run=' . urlencode($run['id'])); exit;
}

/* ---- one time-boxed tick: volume, then SERP, then score+diversify+write -- */
/* Shared with cron/research_tick.php via infra_research_tick() (lib/research.php) -
 * a run's progress must not depend on this specific web action; see that
 * function's docblock for why. */
if ($action === 'run') {
    $runId = (string) ($_POST['run_id'] ?? '');
    $run = infra_research_load_run($runId);
    if (!$run) { infra_set_flash('err', 'Run not found — it may have been deleted.'); header('Location: ' . $back); exit; }
    $niche = $run['niche'];
    $backRun = $back . '&niche=' . urlencode($niche) . '&run=' . urlencode($runId);

    $result = infra_research_tick($run);
    infra_research_save_run($run);
    infra_set_flash($result['level'], $result['msg']);
    header('Location: ' . $backRun); exit;
}

if ($action === 'delete') {
    $runId = (string) ($_POST['run_id'] ?? '');
    @unlink(infra_research_run_path($runId));
    infra_set_flash('ok', 'Run deleted.');
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
