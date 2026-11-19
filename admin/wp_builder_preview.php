<?php
/**
 * Proxies a WP Builder site through the admin server itself, so it can be
 * viewed without the browser needing to resolve the demo box's domain (no
 * hosts-file edit, no public DNS, no Secure DNS interference) — the admin
 * server reaches the box directly by IP with a Host header, same as every
 * other lpc_demo_box_ssh()/curl check in this codebase already does.
 *
 * Only ever proxies domains this tool itself created (checked against the
 * registry) — never an open proxy for an arbitrary host.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wp_builder.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:WP+Builder+is+managed+by+the+platform+only');
    exit;
}

$domain = strtolower(trim($_GET['domain'] ?? ''));
$registry = wpb_registry();
if ($domain === '' || !isset($registry[$domain])) {
    http_response_code(404);
    echo 'Unknown WP Builder site.';
    exit;
}

$path = (string) ($_GET['path'] ?? '/');
$path = str_replace(['://', '..'], '', $path); // no protocol smuggling, no traversal tricks
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://' . LPC_DEMO_BOX_HOST . $path,
    CURLOPT_HTTPHEADER => ['Host: ' . $domain],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => false,
]);
$raw = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'text/html';
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
    http_response_code(502);
    echo 'Could not reach the demo box.';
    exit;
}

$body = substr($raw, $headerSize);

// Rewrite every absolute link/asset URL on the site back through this same
// proxy, so clicking Home/About/Services etc. (and loading images) keeps
// working entirely inside the preview, not just the one page you landed on.
if (stripos($contentType, 'text/html') !== false) {
    $prefix = 'wp_builder_preview.php?domain=' . rawurlencode($domain) . '&path=';
    $body = str_replace(
        ['http://' . $domain, 'https://' . $domain],
        [$prefix, $prefix],
        $body
    );
    // The proxy's own URLs now look like ".../path=/about/" — fine as-is since
    // the browser resolves "path=" as a literal query value, not a new path.
}

http_response_code($httpCode ?: 200);
header('Content-Type: ' . $contentType);
echo $body;
