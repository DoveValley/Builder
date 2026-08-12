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

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
