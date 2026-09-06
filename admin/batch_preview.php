<?php
/**
 * Batch preview — serves card 4's ("Generate sites") static output straight off
 * disk, admin-session gated, so a build can be eyeballed before Upload (card 5)
 * ever runs. READ-ONLY: never writes into a site or a batch.
 *
 * The exported HTML/CSS assumes it owns the domain root (href="/...", url(/...)),
 * because that's true once deployed. Served from a subpath here, those would 404,
 * so every root-absolute reference gets rewritten to route back through this same
 * script. Known gap: srcset and absolute paths inside inline JSON-LD are left
 * alone — fine for eyeballing, not a full mirror of the live site.
 *
 * GET params: master, batch, domain (all required, validated) · path (optional,
 * relative to that domain's output folder; defaults to index.html).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.');
}

$masterId = (string) ($_GET['master'] ?? '');
$batchId  = (string) ($_GET['batch']  ?? '');
$domain   = (string) ($_GET['domain'] ?? '');
$path     = (string) ($_GET['path']   ?? '');

if (!ms_valid_master_id($masterId) || !ms_valid_batch_id($batchId) || $domain === '') {
    http_response_code(400); header('Content-Type: text/plain'); exit('Invalid preview request.');
}

$root = realpath(ms_batch_output_dir($masterId, $batchId, $domain));
if ($root === false || !is_dir($root)) {
    http_response_code(404); header('Content-Type: text/plain');
    exit('Nothing generated yet for this domain — run "4. Generate sites" first.');
}

// Strip any accidental ?query/#fragment a rewritten link might carry, then confine
// the resolved file to $root exactly like admin/hero_overlay.php does for images.
$rel = ltrim(preg_split('/[?#]/', $path, 2)[0], '/');
if ($rel === '') $rel = 'index.html';

$full = realpath($root . '/' . $rel);
if ($full === false || strncmp($full, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
    http_response_code(404); header('Content-Type: text/plain'); exit('Not found.');
}
if (is_dir($full)) {
    $withIndex = realpath($full . '/index.html');
    if ($withIndex === false) { http_response_code(404); header('Content-Type: text/plain'); exit('Not found.'); }
    $full = $withIndex;
}

$ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript',
    'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
][$ext] ?? 'application/octet-stream';

/** Keeps '/' literal (query strings don't need it escaped) while encoding each segment. */
function bp_encode_path(string $p): string {
    return implode('/', array_map('rawurlencode', explode('/', $p)));
}

if ($ext === 'html' || $ext === 'css') {
    $body   = (string) file_get_contents($full);
    $prefix = 'batch_preview.php?master=' . rawurlencode($masterId) . '&batch=' . rawurlencode($batchId)
            . '&domain=' . rawurlencode($domain) . '&path=';

    // href="/x" and src="/x" — leave protocol-relative (//host/...) and scheme'd
    // (https://...) URLs untouched; those are external or intentionally off-preview.
    $body = preg_replace_callback(
        '/\b(href|src)="\/(?!\/)([^"]*)"/i',
        function ($m) use ($prefix) {
            $clean = preg_split('/[?#]/', $m[2], 2)[0];
            return $m[1] . '="' . $prefix . bp_encode_path($clean) . '"';
        },
        $body
    );
    // url(/x) — catches both real .css files and inline <style>/style="" in the HTML.
    $body = preg_replace_callback(
        '/url\((["\']?)\/(?!\/)([^"\')]*)\1\)/i',
        function ($m) use ($prefix) {
            $clean = preg_split('/[?#]/', $m[2], 2)[0];
            return 'url(' . $m[1] . $prefix . bp_encode_path($clean) . $m[1] . ')';
        },
        $body
    );

    header('Content-Type: ' . $mime);
    echo $body;
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
readfile($full);
