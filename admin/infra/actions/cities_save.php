<?php
/**
 * infra/actions/cities_save.php — Cities/Niche tab writes (CSRF, PRG).
 * action = seed | save | select | unselect | niche_add | niche_delete
 *
 * Everything here edits THE PLAN (which city a niche targets, and which domain
 * serves it). Nothing here spends money or touches a registrar.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/cities.php';
require_once __DIR__ . '/../lib/keywords.php';

/**
 * A fetch keeps going while there is time, then stops and says what is left.
 * curl waits do not count against max_execution_time, so a big sweep can run for
 * ages; the availability sweep learned this the expensive way by writing nothing
 * until the whole batch returned.
 */
const INFRA_KW_TIME_BUDGET = 100;

$niche = infra_niche_slug((string) ($_POST['niche'] ?? ''));
$back  = '../index.php?view=cities' . ($niche !== '' ? '&niche=' . urlencode($niche) : '');
if (($qs = trim((string) ($_POST['qs'] ?? ''))) !== '' && preg_match('/\A[A-Za-z0-9_=&%.+\- ]*\z/', $qs)) {
    $back .= '&' . ltrim($qs, '&');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

$action = (string) ($_POST['action'] ?? '');

/* ---- load the city list from data/us_cities.csv ----------------------- */
if ($action === 'seed') {
    $r = infra_cities_seed();
    if ($r['error'] !== '') infra_set_flash('err', 'Could not load cities: ' . $r['error']);
    else infra_set_flash('ok', 'City list loaded — ' . number_format($r['loaded']) . ' cities, ranked 1 (largest) down. Selections were not touched.');
    header('Location: ' . $back); exit;
}

/* ---- niche tabs ------------------------------------------------------- */
if ($action === 'niche_add') {
    $slug = infra_niche_save((string) ($_POST['new_niche'] ?? ''), (string) ($_POST['new_label'] ?? ''));
    if ($slug === '') infra_set_flash('err', 'That niche name has no usable letters or digits in it.');
    else {
        infra_set_flash('ok', 'Niche "' . $slug . '" is ready.');
        $back = '../index.php?view=cities&niche=' . urlencode($slug);
    }
    header('Location: ' . $back); exit;
}

if ($action === 'niche_delete') {
    $slug = infra_niche_slug((string) ($_POST['slug'] ?? ''));
    if ($slug === '' || !isset(infra_niches()[$slug])) {
        infra_set_flash('err', 'No such niche.');
    } else {
        $n = infra_niche_delete($slug);
        infra_set_flash('ok', 'Removed niche "' . $slug . '"'
            . ($n ? ' and its ' . $n . ' city selection' . ($n === 1 ? '' : 's') . '. The cities and domains themselves are untouched.' : '.'));
    }
    header('Location: ' . '../index.php?view=cities'); exit;
}

if ($niche === '' || !isset(infra_niches()[$niche])) {
    infra_set_flash('err', 'Pick a niche first.');
    header('Location: ../index.php?view=cities'); exit;
}

/* ---- add / drop a city ------------------------------------------------ */
if ($action === 'select') {
    $ids = (array) ($_POST['city_id'] ?? []);
    if (!is_array($ids)) $ids = [$ids];
    $added = $dupe = 0;
    foreach (array_filter(array_map('strval', $ids)) as $id) {
        infra_cn_select($niche, $id) ? $added++ : $dupe++;
    }
    // The browse table carries an Ahrefs figure per row. It is research, recorded
    // against any city whether or not it is picked — that is the point of having
    // it while browsing. Saving a figure never selects the city.
    $scored = 0;
    $known  = infra_cn_all($niche);
    foreach ((array) ($_POST['ahrefs'] ?? []) as $id => $v) {
        $id = (string) $id;
        $v  = trim((string) $v);
        if ($v === ($known[$id]['ahrefs'] ?? '')) continue;
        if (infra_cn_note_metric($niche, $id, ['ahrefs' => $v]) === '') $scored++;
    }

    infra_set_flash($added || $scored ? 'ok' : 'warn',
        $added . ' city selection' . ($added === 1 ? '' : 's') . ' added to ' . $niche
        . ($dupe ? ' — ' . $dupe . ' already selected, left alone' : '')
        . ($scored ? ' · ' . $scored . ' Ahrefs figure' . ($scored === 1 ? '' : 's') . ' recorded' : '') . '.');
    header('Location: ' . $back); exit;
}

if ($action === 'unselect') {
    $ids = array_filter(array_map('strval', (array) ($_POST['city_id'] ?? [])));
    foreach ($ids as $id) infra_cn_unselect($niche, $id);
    infra_set_flash('ok', count($ids) . ' city selection' . (count($ids) === 1 ? '' : 's')
        . ' removed from ' . $niche . '. Any domain is released; the Ahrefs figure is kept as research.');
    header('Location: ' . $back); exit;
}

/* ---- save the edited rows -------------------------------------------- */
if ($action === 'save') {
    $rows = (array) ($_POST['row'] ?? []);
    $saved = 0;
    $errs  = [];
    foreach ($rows as $cityId => $in) {
        if (!is_array($in)) continue;
        $err = infra_cn_update($niche, (string) $cityId, $in);
        if ($err === '') $saved++;
        else $errs[] = $cityId . ': ' . $err;
    }
    $msg = $saved . ' row' . ($saved === 1 ? '' : 's') . ' saved.';
    if ($errs) {
        infra_set_flash('err', $msg . ' ' . count($errs) . ' refused — ' . implode('; ', array_slice($errs, 0, 6))
            . (count($errs) > 6 ? ' …' : ''));
    } else {
        infra_set_flash('ok', $msg);
    }
    header('Location: ' . $back); exit;
}

/* ---- keyword template for this niche --------------------------------- */
if ($action === 'template') {
    infra_niche_set_template($niche, (string) ($_POST['template'] ?? ''));
    $t = infra_niches()[$niche]['template'] ?? '';
    infra_set_flash('ok', 'Keyword for ' . $niche . ' is now "' . $t . '".'
        . (strpos($t, '{city}') === false ? ' Warning: it has no {city} in it, so every city would be looked up as the same phrase.' : ''));
    header('Location: ' . $back); exit;
}

/* ---- which provider this niche scores from --------------------------- */
if ($action === 'source') {
    $src = (string) ($_POST['source'] ?? '');
    if (!isset(infra_kw_types()[$src])) {
        infra_set_flash('err', 'Unknown provider.');
    } else {
        $n = infra_niche_set_source($niche, $src);
        infra_set_flash('ok', $niche . ' now scores from ' . infra_kw_types()[$src]['label']
            . ' — ' . $n . ' row' . ($n === 1 ? '' : 's') . ' re-scored. '
            . 'Both providers\' numbers are kept; only which one the score comes from changed. '
            . 'Hand-set scores were left alone.');
    }
    header('Location: ' . $back); exit;
}

/* ---- store provider credentials -------------------------------------- */
if ($action === 'kw_save') {
    $type = (string) ($_POST['type'] ?? '');
    $meta = infra_kw_types()[$type] ?? null;
    if (!$meta) { infra_set_flash('err', 'Unknown provider.'); header('Location: ' . $back); exit; }

    $cfg = infra_load_json(infra_kw_config_path(), []);
    if (!is_array($cfg['providers'] ?? null)) $cfg['providers'] = [];
    $cur = $cfg['providers'][$type] ?? [];

    // Only declared fields are accepted, and a blank secret means "keep what is
    // stored" so the form can be saved without ever rendering the key back.
    foreach ($meta['fields'] as $f => $spec) {
        $v = trim((string) ($_POST['f'][$f] ?? ''));
        if (!empty($spec['secret']) && $v === '') continue;
        $cur[$f] = $v;
    }
    $cfg['providers'][$type] = $cur;
    infra_save_json(infra_kw_config_path(), $cfg);

    $q = infra_kw_quota($type);
    infra_set_flash($q['ok'] ? 'ok' : 'err', 'Saved. ' . $q['msg']);
    header('Location: ' . $back); exit;
}

if ($action === 'kw_test') {
    $type = (string) ($_POST['type'] ?? '');
    $q = infra_kw_quota($type);
    infra_set_flash($q['ok'] ? 'ok' : 'err', ucfirst($type) . ': ' . $q['msg']);
    header('Location: ' . $back); exit;
}

/* ---- fetch metrics ---------------------------------------------------- */
if ($action === 'fetch') {
    $type = (string) ($_POST['provider'] ?? 'ahrefs');
    if (!isset(infra_kw_configured()[$type])) {
        infra_set_flash('err', 'No API key stored for ' . $type . ' — add one below before fetching.');
        header('Location: ' . $back); exit;
    }
    $tpl = infra_niches()[$niche]['template'] ?? '';
    if (trim($tpl) === '') {
        infra_set_flash('err', 'This niche has no keyword template, so there is nothing to look up.');
        header('Location: ' . $back); exit;
    }

    $stale = (int) ($_POST['stale_days'] ?? 30);
    $scope = (string) ($_POST['scope'] ?? '');

    // Three scopes: everything matching the current filter, the rows ticked on
    // this page, or every selected city in the niche.
    if ($scope === 'filter') {
        $ids = infra_cities_filtered_ids([
            'q'       => (string) ($_POST['f_q'] ?? ''),
            'state'   => (string) ($_POST['f_state'] ?? ''),
            'min_pop' => (int) ($_POST['f_min_pop'] ?? 0),
        ]);
        $matched = count($ids);
    } else {
        $ids = array_filter(array_map('strval', (array) ($_POST['city_id'] ?? [])));
        $matched = count($ids);
    }
    $todo = infra_cn_needs_metrics($niche, $ids, $stale, $type);

    if (!$todo) {
        infra_set_flash('warn', ($matched ? $matched . ' cities matched, but none need fetching — ' : 'Nothing to fetch — ')
            . 'they all already have ' . infra_kw_types()[$type]['label'] . ' numbers newer than the cutoff. '
            . 'Choose "everything" under Re-fetch to pull them again.');
        header('Location: ' . $back); exit;
    }

    $size    = infra_kw_batch_size($type);
    $started = time();
    $done = $miss = 0;
    $err  = '';
    $left = count($todo);

    // Pace against the provider's published rate limit rather than discovering it
    // as a 429 halfway through a sweep. DataForSEO allows 12 requests a minute and
    // each batch costs two of them, so batches start no closer than 10s apart;
    // Ahrefs allows 60, so one second.
    $meta     = infra_kw_types()[$type];
    $perBatch = max(1, (int) ($meta['calls_per_batch'] ?? 1));
    $interval = (int) ceil(60 * $perBatch / max(1, (int) ($meta['rate_per_min'] ?? 60)));
    $lastAt   = 0;

    foreach (array_chunk($todo, $size) as $chunk) {
        if (time() - $started > INFRA_KW_TIME_BUDGET) break;
        if ($lastAt && ($wait = $interval - (time() - $lastAt)) > 0) {
            if (time() - $started + $wait > INFRA_KW_TIME_BUDGET) break;
            sleep($wait);
        }
        $lastAt = time();

        // Several cities can share one keyword — five Columbuses ask the same
        // question. Keying a city per phrase let the last one win and silently
        // dropped the rest, so Columbus IN got numbers while Columbus OH got
        // none. They share the answer; the shared-name badge is what warns whose
        // numbers they really are.
        $phrases = $byPhrase = [];
        foreach ($chunk as $c) {
            $p = infra_kw_phrase($tpl, $c);
            if ($p === '') continue;
            $k = strtolower($p);
            if (!isset($byPhrase[$k])) { $phrases[] = $p; $byPhrase[$k] = []; }
            $byPhrase[$k][] = $c['id'];
        }
        $r = infra_kw_fetch($type, $phrases);
        if (!$r['ok']) $err = $r['msg'];
        elseif (($r['msg'] ?? '') !== '' && !isset($warn)) $warn = $r['msg'];   // e.g. keywords the provider refused

        // Written per batch, and any rows that DID come back are kept even when the
        // call is reported as failed — a truncated response still cost units, and
        // throwing away what it paid for would be the expensive kind of tidy.
        foreach ($byPhrase as $phrase => $cityIds) {
            foreach ($cityIds as $cityId) {
                if (isset($r['rows'][$phrase])) { infra_cn_store_metrics($niche, $cityId, $r['rows'][$phrase], $type); $done++; }
                elseif ($err === '') { $miss++; }
            }
        }
        if ($err !== '') break;
        $left -= count($chunk);
    }

    $msg = $done . ' cit' . ($done === 1 ? 'y' : 'ies') . ' updated from ' . $meta['label']
         . ($miss ? ' · ' . $miss . ' returned no data (too small to register a search volume)' : '')
         . ($left > 0 ? ' · ' . number_format($left) . ' STILL TO GO — press Fetch again to continue' : ' · sweep complete')
         . '.';
    if ($err !== '') $msg .= ' Stopped early: ' . $err;
    if (isset($warn)) $msg .= ' ' . $warn;
    // What it cost, read back rather than estimated.
    $q = infra_kw_quota($type);
    if ($q['ok'] && $q['remaining'] !== null) {
        $msg .= ' ' . ($type === 'dataforseo' ? '$' . number_format($q['remaining']) : number_format($q['remaining']) . ' units')
              . ' left.';
    }
    infra_set_flash($err !== '' ? 'err' : 'ok', $msg);
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
