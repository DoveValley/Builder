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
            'min_volume' => $minVol, 'sep_mi' => $sepMi, 'state_cap_pct' => $capPct,
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
if ($action === 'run') {
    $runId = (string) ($_POST['run_id'] ?? '');
    $run = infra_research_load_run($runId);
    if (!$run) { infra_set_flash('err', 'Run not found — it may have been deleted.'); header('Location: ' . $back); exit; }
    $niche = $run['niche'];
    $backRun = $back . '&niche=' . urlencode($niche) . '&run=' . urlencode($runId);

    if ($run['phase'] === 'volume') {
        if (!infra_kw_has_creds($run['provider'])) {
            infra_set_flash('err', 'No API key stored for ' . $run['provider'] . ' — add one on the Cities/Niche tab first.');
            header('Location: ' . $backRun); exit;
        }
        $todo = array_filter($run['candidates'], fn($c) => $c['volume'] === null);
        $numPatterns = count($run['patterns']);
        $chunkCities = max(1, (int) floor(infra_kw_batch_size($run['provider']) / max(1, $numPatterns)));

        $started = time(); $done = 0;
        $ids = array_keys($todo);
        foreach (array_chunk($ids, $chunkCities) as $chunk) {
            if (time() - $started > INFRA_RESEARCH_TIME_BUDGET) break;
            $phrases = []; $byPhrase = [];
            foreach ($chunk as $id) {
                $c = $run['candidates'][$id];
                foreach ($run['patterns'] as $pat) {
                    $p = infra_kw_phrase($pat, ['city' => $c['city'], 'state' => $c['state'], 'ss' => $c['ss']]);
                    if ($p === '') continue;
                    $k = strtolower($p);
                    if (!isset($byPhrase[$k])) { $phrases[] = $p; $byPhrase[$k] = []; }
                    $byPhrase[$k][] = $id;
                }
            }
            if (!$phrases) continue;
            $r = infra_kw_fetch($run['provider'], $phrases);
            if (!$r['ok']) { infra_set_flash('err', 'Stopped: ' . $r['msg']); infra_research_save_run($run); header('Location: ' . $backRun); exit; }
            $sums = [];
            foreach ($byPhrase as $phrase => $ids2) {
                $vol = (float) ($r['rows'][$phrase]['volume'] ?? 0);
                foreach ($ids2 as $id) $sums[$id] = ($sums[$id] ?? 0) + $vol;
            }
            foreach ($chunk as $id) {
                $run['candidates'][$id]['volume'] = $sums[$id] ?? 0.0;
                $done++;
            }
        }
        $left = count(array_filter($run['candidates'], fn($c) => $c['volume'] === null));
        if ($left === 0) {
            $before = count($run['candidates']);
            $run['candidates'] = array_filter($run['candidates'], fn($c) => $c['volume'] >= $run['filters']['min_volume']);
            $dropped = $before - count($run['candidates']);
            $run['phase'] = 'serp';
            infra_set_flash('ok', "Volume done — {$dropped} cities dropped under {$run['filters']['min_volume']}/mo, "
                . count($run['candidates']) . ' remain. Press Continue to run real SERP checks (costs money — DataForSEO, ~$0.002/keyword).');
        } else {
            infra_set_flash('ok', "{$done} fetched this pass, {$left} still to go — press Continue.");
        }
        infra_research_save_run($run);
        header('Location: ' . $backRun); exit;
    }

    if ($run['phase'] === 'serp') {
        if (!infra_kw_has_creds('dataforseo')) {
            infra_set_flash('err', 'The SERP check needs DataForSEO credentials — add them on the Cities/Niche tab first.');
            header('Location: ' . $backRun); exit;
        }
        $cfg = infra_kw_provider('dataforseo');
        $numPatterns = count($run['patterns']);
        $started = time(); $done = 0;
        foreach ($run['candidates'] as $id => &$c) {
            if (time() - $started > INFRA_RESEARCH_TIME_BUDGET) break;
            if ($c['serp_patterns_done'] >= $numPatterns) continue;
            $pat = $run['patterns'][$c['serp_patterns_done']];
            $phrase = infra_kw_phrase($pat, ['city' => $c['city'], 'state' => $c['state'], 'ss' => $c['ss']]);
            if ($phrase === '') { $c['serp_patterns_done']++; continue; }
            $r = infra_research_serp_fetch($cfg, $phrase);
            if (!$r['ok']) { infra_set_flash('err', 'Stopped: ' . $r['msg']); infra_research_save_run($run); header('Location: ' . $backRun); exit; }
            $c['serp_open_sum']  += $r['data']['open_slots'];
            $c['serp_local_sum'] += $r['data']['local_competitors'];
            $c['serp_natl_sum']  += $r['data']['national_brands'];
            $c['serp_patterns_done']++;
            $done++;
        }
        unset($c);
        $left = 0;
        foreach ($run['candidates'] as $c) $left += max(0, $numPatterns - $c['serp_patterns_done']);
        if ($left === 0) {
            foreach ($run['candidates'] as $id => &$c) {
                $n = max(1, $c['serp_patterns_done']);
                $c['open_slots']         = $c['serp_open_sum'] / $n;
                $c['local_competitors']  = $c['serp_local_sum'] / $n;
                $c['national_brands']    = $c['serp_natl_sum'] / $n;
            }
            unset($c);
            infra_research_score_and_grade($run['candidates']);
            $picked = infra_research_diversify($run['candidates'], $run['filters']['sep_mi'], $run['filters']['state_cap_pct']);
            $resultFile = infra_research_write_xlsx($niche, $picked);
            if ($resultFile === null) {
                // Never mark 'done' on a failed write - the view links result_file as a
                // plain download URL with no existence check, so a null/missing file
                // here would otherwise 404 (or worse, look "done" with no way to retry
                // the write itself). Leaving phase alone keeps the Continue button up.
                infra_set_flash('error', count($picked) . ' cities scored, but writing the xlsx failed '
                    . '(disk full or uploads/downloads not writable?) - press Continue to retry the write.');
            } else {
                $run['result_file'] = $resultFile;
                $run['result_count'] = count($picked);
                $run['phase'] = 'done';
                infra_set_flash('ok', count($picked) . ' cities in the final list. Saved to Downloads (Test Lab) as ' . $resultFile . '.');
            }
        } else {
            infra_set_flash('ok', "SERP checks: {$done} this pass, {$left} keyword-checks still to go — press Continue.");
        }
        infra_research_save_run($run);
        header('Location: ' . $backRun); exit;
    }

    infra_set_flash('warn', 'This run is already done.');
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
