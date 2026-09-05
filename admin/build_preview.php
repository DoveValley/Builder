<?php
/**
 * Preview any built site, straight from the batch's output/ folder.
 *
 * WHY THIS IS NEEDED: a generated site uses ROOT-RELATIVE paths (/assets/css/style.css,
 * /privacy-policy/), which is correct once it is deployed on its own domain. But it means the
 * site cannot be browsed from a subdirectory of this host — /assets/... resolves against the
 * panel's own docroot and 404s. Linking straight at sites/.../output/<slug>/ looks like it
 * works, because the HTML itself returns 200, and is broken for everything the HTML then asks
 * for. So this serves the files and rewrites only the leading slash of internal href/src back
 * through itself.
 *
 * Read-only. Auth required. Writes nothing, deploys nothing.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$master = trim((string) ($_GET['master'] ?? ''));
$batch  = trim((string) ($_GET['batch'] ?? ''));
$slug   = trim((string) ($_GET['slug'] ?? ''));
$path   = (string) ($_GET['path'] ?? '');

// No master/batch given: list what there is to look at, so this is usable on its own.
if ($master === '' || $batch === '' || $slug === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<style>body{font:15px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px;background:#f8fafc;color:#1e293b}'
       . 'a{color:#1e3a5f}h1{font-size:1.2rem;color:#1e3a5f}li{margin:4px 0}</style>';
    echo '<h1>Built sites you can preview</h1>';
    $any = false;
    foreach (glob(BASE_DIR . '/sites/*/batches/*/output/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file($dir . '/index.html')) continue;
        $parts = explode('/', str_replace(BASE_DIR . '/sites/', '', $dir));
        // parts = [master, 'batches', batchId, 'output', slug]
        if (count($parts) < 5) continue;
        [$m, , $b, , $s] = $parts;
        $pages = count(glob($dir . '/*/index.html') ?: []) + 1;
        $url = 'build_preview.php?master=' . rawurlencode($m) . '&batch=' . rawurlencode($b) . '&slug=' . rawurlencode($s);
        printf('<li><a href="%s" target="_blank">%s</a> <span style="color:#64748b">— %s / %s · %d pages · built %s</span></li>',
            htmlspecialchars($url, ENT_QUOTES), htmlspecialchars($s, ENT_QUOTES),
            htmlspecialchars($m, ENT_QUOTES), htmlspecialchars($b, ENT_QUOTES), $pages,
            date('j M H:i', filemtime($dir . '/index.html')));
        $any = true;
    }
    if (!$any) echo '<p style="color:#64748b">Nothing built yet. Run <strong>Generate sites</strong> on a batch.</p>';
    exit;
}

if (!ms_valid_master_id($master) || !ms_valid_batch_id($batch) || !preg_match('/^[a-z0-9_]+$/', $slug)) {
    http_response_code(400); header('Content-Type: text/plain'); echo 'Bad master, batch or slug.'; exit;
}

$root = realpath(ms_batch_dir($master, $batch) . '/output/' . $slug);
if ($root === false) { http_response_code(404); header('Content-Type: text/plain'); echo 'That site has not been built.'; exit; }

// A directory (or empty path) means the index document, the way a web server resolves it —
// the generated links are all directory-style ("/privacy-policy/").
$rel = ltrim($path, '/');
if ($rel === '' || substr($rel, -1) === '/') $rel .= 'index.html';
$target = realpath($root . '/' . $rel);

// The realpath containment check is the actual guard: ../ in the query cannot escape.
if ($target === false || strpos($target, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($target)) {
    http_response_code(404); header('Content-Type: text/plain');
    echo 'Not found in this site: /' . htmlspecialchars($rel, ENT_QUOTES); exit;
}

$types = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript',
    'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8',
];
$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Robots-Tag: noindex, nofollow');       // a preview must never be indexed

if ($ext !== 'html') { readfile($target); exit; }

$prefix = 'build_preview.php?master=' . rawurlencode($master) . '&batch=' . rawurlencode($batch)
        . '&slug=' . rawurlencode($slug) . '&path=';

$html = (string) file_get_contents($target);
// Only a SINGLE leading slash — "//cdn…" is protocol-relative and "https://" absolute, and
// both must be left exactly as they are.
$html = preg_replace_callback(
    '~\b(href|src)="/(?!/)([^"]*)"~i',
    fn($m) => $m[1] . '="' . htmlspecialchars($prefix . rawurlencode($m[2]), ENT_QUOTES) . '"',
    $html
);

$bar = '<div style="position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#1e3a5f;color:#fff;'
     . 'font:600 12px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;padding:6px 12px;text-align:center;">'
     . 'Preview &middot; ' . htmlspecialchars("$master / $batch / $slug", ENT_QUOTES) . ' &middot; not deployed</div>';
$html = preg_replace('~</body>~i', $bar . '</body>', $html, 1);

echo $html;
