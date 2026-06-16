<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Auth
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Export streams a file — handle before JSON header
if ($action === 'export') {
    $siteId = trim($_GET['site_id'] ?? '');
    site_export($siteId);
    exit;
}

header('Content-Type: application/json');

// CSRF required for all state-changing POST actions
$mutating = ['create', 'clone', 'delete', 'rename', 'select', 'deselect', 'import'];
if (in_array($action, $mutating, true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request token']);
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sites_dir(): string {
    return BASE_DIR . '/sites/';
}

function valid_site_id(string $id): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $id)
        && is_dir(sites_dir() . $id);
}

function make_site_id(string $name): string {
    $id = slugify($name);
    if ($id === '') $id = 'site';
    $dir = sites_dir();
    if (!is_dir($dir . $id)) return $id;
    $i = 2;
    while (is_dir($dir . $id . '-' . $i)) $i++;
    return $id . '-' . $i;
}

function site_meta(string $id): array {
    $f = sites_dir() . $id . '/meta.json';
    if (!file_exists($f)) return ['name' => $id, 'created_at' => '', 'updated_at' => ''];
    return json_decode(file_get_contents($f), true) ?? ['name' => $id];
}

function save_site_meta(string $id, array $meta): void {
    $meta['updated_at'] = date('c');
    file_put_contents(sites_dir() . $id . '/meta.json',
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function list_sites(): array {
    $dir = sites_dir();
    if (!is_dir($dir)) return [];
    $sites = [];
    foreach (scandir($dir) as $entry) {
        if ($entry[0] === '.') continue;
        if (!is_dir($dir . $entry)) continue;
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $entry)) continue;
        $meta      = site_meta($entry);
        $dataFile  = $dir . $entry . '/data/site.json';
        $siteData  = [];
        if (file_exists($dataFile)) {
            $siteData = json_decode(file_get_contents($dataFile), true) ?? [];
        }
        $updated = $meta['updated_at'] ?? '';
        if (!$updated && file_exists($dataFile)) {
            $updated = date('c', filemtime($dataFile));
        }
        $sites[] = [
            'id'         => $entry,
            'name'       => $meta['name'] ?? $entry,
            'created_at' => $meta['created_at'] ?? '',
            'updated_at' => $updated,
            'page_count' => count($siteData['pages'] ?? []),
            'post_count' => count($siteData['posts'] ?? []),
        ];
    }
    usort($sites, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    return $sites;
}

function scaffold_site_dir(string $id): void {
    $base = sites_dir() . $id . '/';
    @mkdir($base . 'data',          0775, true);
    @mkdir($base . 'uploads/media', 0775, true);
    // Protect data/ from direct web access
    file_put_contents($base . 'data/.htaccess', "Require all denied\n");
}

function copy_dir(string $src, string $dst): void {
    @mkdir($dst, 0775, true);
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function delete_dir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function site_export(string $siteId): void {
    if (!$siteId || !valid_site_id($siteId)) {
        http_response_code(400);
        echo 'Invalid site';
        return;
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZipArchive extension not available on this server';
        return;
    }

    $siteDir   = sites_dir() . $siteId . '/';
    $meta      = site_meta($siteId);
    $zipName   = slugify($meta['name'] ?: $siteId) . '-export.zip';
    $tmpZip    = sys_get_temp_dir() . '/' . $zipName . '.' . uniqid() . '.zip';

    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // site.json — rewrite upload paths from sites/{id}/uploads/ → uploads/
    $dataFile = $siteDir . 'data/site.json';
    if (file_exists($dataFile)) {
        $json = file_get_contents($dataFile);
        $json = str_replace('sites/' . $siteId . '/uploads/', 'uploads/', $json);
        $zip->addFromString('data/site.json', $json);
    }

    // uploads — add all files preserving subdir structure
    $uploadsDir = $siteDir . 'uploads/';
    if (is_dir($uploadsDir)) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), strlen($uploadsDir));
                $zip->addFile($file->getPathname(), 'uploads/' . $rel);
            }
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Pragma: no-cache');
    readfile($tmpZip);
    unlink($tmpZip);
}

// ── Action handlers ───────────────────────────────────────────────────────────

switch ($action) {

    case 'list':
        echo json_encode(['sites' => list_sites()]);
        break;

    case 'create':
        $name     = trim($_POST['name'] ?? '');
        $cloneFrom = trim($_POST['clone_from'] ?? '');
        if ($name === '') { echo json_encode(['error' => 'Site name is required']); exit; }
        $id = make_site_id($name);
        scaffold_site_dir($id);
        if ($cloneFrom && valid_site_id($cloneFrom)) {
            // Clone: copy data + uploads from source
            $src = sites_dir() . $cloneFrom . '/';
            copy_dir($src . 'data',    sites_dir() . $id . '/data');
            copy_dir($src . 'uploads', sites_dir() . $id . '/uploads');
            // Rewrite upload paths in cloned site.json
            $dataFile = sites_dir() . $id . '/data/site.json';
            if (file_exists($dataFile)) {
                $json = file_get_contents($dataFile);
                $json = str_replace('sites/' . $cloneFrom . '/uploads/', 'sites/' . $id . '/uploads/', $json);
                file_put_contents($dataFile, $json);
            }
            // Re-protect data dir .htaccess (may have been overwritten)
            file_put_contents(sites_dir() . $id . '/data/.htaccess', "Require all denied\n");
        }
        save_site_meta($id, ['name' => $name, 'created_at' => date('c')]);
        echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
        break;

    case 'rename':
        $id   = trim($_POST['site_id'] ?? '');
        $name = trim($_POST['name']    ?? '');
        if (!valid_site_id($id)) { echo json_encode(['error' => 'Invalid site']); exit; }
        if ($name === '')         { echo json_encode(['error' => 'Name required']); exit; }
        $meta         = site_meta($id);
        $meta['name'] = $name;
        save_site_meta($id, $meta);
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $id = trim($_POST['site_id'] ?? '');
        if (!valid_site_id($id)) { echo json_encode(['error' => 'Invalid site']); exit; }
        if ($id === ACTIVE_SITE_ID) {
            unset($_SESSION['active_site']);
        }
        delete_dir(sites_dir() . $id);
        echo json_encode(['success' => true]);
        break;

    case 'select':
        $id = trim($_POST['site_id'] ?? '');
        if (!valid_site_id($id)) { echo json_encode(['error' => 'Invalid site']); exit; }
        $_SESSION['active_site'] = $id;
        // Update last-accessed timestamp
        $meta = site_meta($id);
        save_site_meta($id, $meta);
        echo json_encode(['success' => true, 'redirect' => 'index.php']);
        break;

    case 'deselect':
        unset($_SESSION['active_site']);
        echo json_encode(['success' => true]);
        break;

    case 'import':
        // Import the legacy single-site data/site.json into a new site
        $name = trim($_POST['name'] ?? 'My Site');
        $legacyData    = BASE_DIR . '/data/site.json';
        $legacyUploads = BASE_DIR . '/uploads/';
        if (!file_exists($legacyData)) {
            echo json_encode(['error' => 'No legacy site.json found']); exit;
        }
        $id = make_site_id($name);
        scaffold_site_dir($id);
        copy($legacyData, sites_dir() . $id . '/data/site.json');
        if (is_dir($legacyUploads)) {
            copy_dir($legacyUploads, sites_dir() . $id . '/uploads');
        }
        save_site_meta($id, ['name' => $name, 'created_at' => date('c')]);
        echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
