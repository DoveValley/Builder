<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=cities'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=cities&msg=error:Invalid+request+token');
    exit;
}

if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$action = $_POST['action'] ?? '';

function _city_load(): array {
    if (!file_exists(CITIES_FILE)) return [];
    $raw = json_decode(file_get_contents(CITIES_FILE), true);
    return is_array($raw) ? $raw : [];
}

function _city_save(array $cities): bool {
    $dir = dirname(CITIES_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode(array_values($cities), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = CITIES_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, CITIES_FILE);
}

function _city_cleanup_pages(string $cityId): void {
    if (!defined('PAGES_DIR') || !defined('PAGE_INDEX_FILE')) return;
    $suffix  = '_' . $cityId . '.json';
    $deleted = [];
    foreach (glob(PAGES_DIR . '*.json') ?: [] as $f) {
        if (str_ends_with(basename($f), $suffix)) {
            @unlink($f);
            if (file_exists($f . '.bak')) @unlink($f . '.bak');
            $deleted[basename($f)] = true;
        }
    }
    if (!empty($deleted) && file_exists(PAGE_INDEX_FILE)) {
        $raw = json_decode(file_get_contents(PAGE_INDEX_FILE), true);
        if (is_array($raw)) {
            $raw = array_filter($raw, fn($fn) => !isset($deleted[$fn]));
            $tmp = PAGE_INDEX_FILE . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, PAGE_INDEX_FILE);
        }
    }
}

function _city_make_id(string $city, string $ss, array $cities): string {
    $base = strtolower(trim($city)) . '-' . strtolower(trim($ss));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-') ?: 'city-' . substr(uniqid(), -6);
    $id   = $base;
    $n    = 2;
    $existing = array_column($cities, 'id');
    while (in_array($id, $existing, true)) { $id = $base . '-' . $n++; }
    return $id;
}

function _parse_lines(string $raw): array {
    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $raw)))));
}

function _city_parse_post(string $prefix = ''): array {
    $tags = array_values(array_filter(array_map(
        'trim',
        preg_split('/[\s,]+/', $_POST[$prefix . 'tags'] ?? '')
    )));
    return [
        'city'          => trim($_POST[$prefix . 'city']          ?? ''),
        'state'         => trim($_POST[$prefix . 'state']         ?? ''),
        'SS'            => strtoupper(trim($_POST[$prefix . 'SS'] ?? '')),
        'city_slug'     => trim($_POST[$prefix . 'city_slug']     ?? ''),
        'phone'         => trim($_POST[$prefix . 'phone']         ?? ''),
        'tel'           => trim($_POST[$prefix . 'tel']           ?? ''),
        'zip'           => trim($_POST[$prefix . 'zip']           ?? ''),
        'address'       => trim($_POST[$prefix . 'address']       ?? ''),
        'lat'           => trim($_POST[$prefix . 'lat']           ?? ''),
        'lng'           => trim($_POST[$prefix . 'lng']           ?? ''),
        'website'       => sanitize_url($_POST[$prefix . 'website'] ?? ''),
        'tags'          => $tags,
        'industries'    => _parse_lines($_POST[$prefix . 'industries']    ?? ''),
        'top_employers' => _parse_lines($_POST[$prefix . 'top_employers'] ?? ''),
        'salary_note'   => trim($_POST[$prefix . 'salary_note']   ?? ''),
        'market_blurb'  => trim($_POST[$prefix . 'market_blurb']  ?? ''),
    ];
}

// ── add ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $fields = _city_parse_post();

    if ($fields['city'] === '' || $fields['SS'] === '') {
        header('Location: index.php?tab=cities&msg=error:City+name+and+state+abbreviation+are+required');
        exit;
    }

    $cities = _city_load();

    // Auto-generate city_slug if blank
    if ($fields['city_slug'] === '') {
        $fields['city_slug'] = preg_replace('/[^a-z0-9]+/', '-', strtolower($fields['city'] . '-' . $fields['SS']));
        $fields['city_slug'] = trim($fields['city_slug'], '-');
    }

    $id = _city_make_id($fields['city'], $fields['SS'], $cities);
    $cities[] = array_merge(['id' => $id], $fields);

    if (!_city_save($cities)) {
        header('Location: index.php?tab=cities&msg=error:Could+not+save+city');
        exit;
    }
    header('Location: index.php?tab=cities&msg=success:City+added');
    exit;
}

// ── save (edit existing) ──────────────────────────────────────────────────────
if ($action === 'save') {
    $id     = trim($_POST['city_id'] ?? '');
    $cities = _city_load();
    $idx    = null;
    foreach ($cities as $k => $c) { if ($c['id'] === $id) { $idx = $k; break; } }

    if ($idx === null) {
        header('Location: index.php?tab=cities&msg=error:City+not+found');
        exit;
    }

    $fields = _city_parse_post();
    if ($fields['city'] === '' || $fields['SS'] === '') {
        header('Location: index.php?tab=cities&city=' . urlencode($id) . '&msg=error:City+name+and+state+abbreviation+are+required');
        exit;
    }
    if ($fields['city_slug'] === '') {
        $fields['city_slug'] = preg_replace('/[^a-z0-9]+/', '-', strtolower($fields['city'] . '-' . $fields['SS']));
        $fields['city_slug'] = trim($fields['city_slug'], '-');
    }

    $cities[$idx] = array_merge($cities[$idx], $fields);

    if (!_city_save($cities)) {
        header('Location: index.php?tab=cities&city=' . urlencode($id) . '&msg=error:Could+not+save+city');
        exit;
    }
    header('Location: index.php?tab=cities&city=' . urlencode($id) . '&msg=success:City+saved');
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id     = trim($_POST['city_id'] ?? '');
    $cities = _city_load();
    $cities = array_values(array_filter($cities, fn($c) => $c['id'] !== $id));

    if (!_city_save($cities)) {
        header('Location: index.php?tab=cities&msg=error:Could+not+delete+city');
        exit;
    }
    _city_cleanup_pages($id);
    header('Location: index.php?tab=cities&msg=success:City+deleted');
    exit;
}

// ── CSV import ────────────────────────────────────────────────────────────────
// Expected columns (header row required):
// city, state, SS, city_slug, phone, tel, zip, address, lat, lng, website, tags
if ($action === 'import_csv') {
    $upload = $_FILES['csv_file'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
        header('Location: index.php?tab=cities&msg=error:CSV+upload+failed');
        exit;
    }

    $mime = mime_content_type($upload['tmp_name']);
    $ext  = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true) || !in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'], true)) {
        header('Location: index.php?tab=cities&msg=error:Please+upload+a+CSV+file');
        exit;
    }

    $handle = fopen($upload['tmp_name'], 'r');
    if (!$handle) {
        header('Location: index.php?tab=cities&msg=error:Could+not+read+CSV+file');
        exit;
    }

    $headers = array_map('trim', fgetcsv($handle) ?: []);
    $required = ['city', 'SS'];
    foreach ($required as $r) {
        if (!in_array($r, $headers, true)) {
            fclose($handle);
            header('Location: index.php?tab=cities&msg=error:CSV+must+have+city+and+SS+columns');
            exit;
        }
    }

    $cities  = _city_load();
    $existing = array_column($cities, 'id');
    $added   = 0;
    $skipped = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 2) continue;
        $data = [];
        foreach ($headers as $i => $h) {
            $data[$h] = trim($row[$i] ?? '');
        }

        $city = $data['city'] ?? '';
        $SS   = strtoupper($data['SS'] ?? '');
        if ($city === '' || $SS === '') { $skipped++; continue; }

        $citySlug = $data['city_slug'] ?? '';
        if ($citySlug === '') {
            $citySlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($city . '-' . $SS));
            $citySlug = trim($citySlug, '-');
        }

        $tags = array_values(array_filter(array_map('trim', preg_split('/[\s,|]+/', $data['tags'] ?? ''))));

        // Skip rows whose base ID already existed before this import started
        $baseId = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($city . '-' . $SS)), '-');
        if ($baseId !== '' && in_array($baseId, $existing, true)) { $skipped++; continue; }

        $id = _city_make_id($city, $SS, $cities);

        $cities[] = [
            'id'        => $id,
            'city'      => $city,
            'state'     => $data['state']   ?? '',
            'SS'        => $SS,
            'city_slug' => $citySlug,
            'phone'     => $data['phone']   ?? '',
            'tel'       => $data['tel']     ?? '',
            'zip'       => $data['zip']     ?? '',
            'address'   => $data['address'] ?? '',
            'lat'       => $data['lat']     ?? '',
            'lng'       => $data['lng']     ?? '',
            'website'   => sanitize_url($data['website'] ?? ''),
            'tags'      => $tags,
        ];
        $added++;
    }
    fclose($handle);

    if (!_city_save($cities)) {
        header('Location: index.php?tab=cities&msg=error:Could+not+save+imported+cities');
        exit;
    }
    header('Location: index.php?tab=cities&msg=success:' . urlencode("Imported $added cities" . ($skipped ? ", skipped $skipped" : '')));
    exit;
}

header('Location: index.php?tab=cities');
exit;
