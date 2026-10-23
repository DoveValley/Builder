<?php
/**
 * Serves /favicon.ico — browsers and some crawlers request this exact path by
 * convention regardless of what the page's own <link rel="icon"> tag says, and
 * nothing else in this app maps it to anything, so it always 404'd even on a
 * domain with a real favicon configured and rendering fine via the <link> tag.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data    = load_data();
$favicon = (string) ($data['header']['favicon'] ?? '');

if ($favicon === '') {
    http_response_code(404);
    exit;
}

header('Location: /' . ltrim($favicon, '/'), true, 302);
