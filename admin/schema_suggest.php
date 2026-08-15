<?php
// AI schema generator. POST only. Requires admin auth + CSRF.
// Returns JSON: {schema:"<json-ld string>"} or {error:"..."}.
// One-shot call to the Anthropic Messages API, same pattern as keyword_suggest.php.
//
// Inputs (POST):
//   scope     homepage | page | template | post   (the SEO editor $context)
//   core_type core_contact|core_about|core_service|core_collection|core_general (page scope only)
//   prompt    the (possibly edited) instruction text to use for this generation
//   ctx       JSON of page-specific hints scraped client-side (title, slug, service, keyword, excerpt…)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/schema_prompts.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Invalid request token.']); exit; }

$scope    = trim($_POST['scope'] ?? '');
$coreType = trim($_POST['core_type'] ?? '');
$prompt   = trim($_POST['prompt'] ?? '');
$ctx      = json_decode($_POST['ctx'] ?? '{}', true);
if (!is_array($ctx)) $ctx = [];

$validScopes = ['homepage', 'page', 'template', 'post'];
if (!in_array($scope, $validScopes, true)) { echo json_encode(['error' => 'Unknown schema area.']); exit; }
if ($prompt === '') { $prompt = schema_prompt_get(schema_scope_key($scope, $coreType)); }
if ($prompt === '' || mb_strlen($prompt) > 8000) { echo json_encode(['error' => 'Prompt is empty or too long.']); exit; }

require_once __DIR__ . '/../includes/anthropic.php';
$ready = anthropic_ready();
if (!$ready['ok']) { echo json_encode(['error' => $ready['error']]); exit; }

// ── Server-side context: business identity is passed as GUIDANCE only, so the
// model keeps using {shortcodes} in its output rather than hard-coding values. ──
$business = ''; $hasAddress = false; $svc = '';
try {
    $d = load_data();
    $sv = $d['site_vars'] ?? [];
    $business   = trim($sv['business'] ?? '');
    $hasAddress = trim($sv['address'] ?? '') !== '';
} catch (Throwable $e) {}

$lines = [];
$lines[] = '## Context for this page';
if ($business !== '') $lines[] = "- Business (for your reference only — still output the {business} shortcode): {$business}";
$lines[] = '- Site has a real street address: ' . ($hasAddress ? 'YES — you may include a postalAddress using {address}.' : 'NO — omit address, geo, and any physical-location property.');

// Scope-specific page hints (concrete, non-identity values are safe to inline).
$titleHint = trim((string)($ctx['title'] ?? ''));
$slugHint  = trim((string)($ctx['slug'] ?? ''));
if ($scope === 'template') {
    $svc = trim((string)($ctx['service'] ?? ''));
    $kw  = trim((string)($ctx['keyword'] ?? ''));
    if ($svc !== '') $lines[] = "- Service (this landing page's offering): {$svc}";
    if ($kw  !== '') $lines[] = "- Primary keyword: {$kw}";
    if ($slugHint !== '') $lines[] = "- Slug pattern (contains {city_slug}): {$slugHint}";
    $lines[] = '- Remember: city values MUST stay as shortcodes ({city}, {SS}, {city_state}, {city_slug}).';
} elseif ($scope === 'post') {
    if ($titleHint !== '') $lines[] = "- Post title: {$titleHint}";
    $ex = trim((string)($ctx['excerpt'] ?? '')); if ($ex !== '') $lines[] = "- Excerpt: {$ex}";
    $img = trim((string)($ctx['image'] ?? '')); if ($img !== '') $lines[] = "- Featured image URL: {$img}";
    $dt = trim((string)($ctx['date'] ?? '')); if ($dt !== '') $lines[] = "- Published date: {$dt}";
    if ($slugHint !== '') $lines[] = "- Slug: {$slugHint}";
} elseif ($scope === 'page') {
    $lines[] = '- Page type selected: ' . (schema_core_types()[schema_scope_key($scope, $coreType)] ?? 'General');
    if ($titleHint !== '') $lines[] = "- Page title: {$titleHint}";
    if ($slugHint !== '') $lines[] = "- Page slug: {$slugHint}";
}

$fullPrompt = $prompt . "\n\n" . implode("\n", $lines) . schema_prompt_shared_rules();

// SMART tier: schema output is long and has to be structurally right, unlike the
// short list work elsewhere. The tier is named rather than pinned to a version
// string, so a model change happens in includes/anthropic.php.
$r = anthropic_message($fullPrompt, ['model' => ANTHROPIC_SMART, 'max_tokens' => 2000, 'timeout' => 60]);
if (!$r['ok']) { echo json_encode(['error' => $r['error']]); exit; }
$text = $r['text'];
$text = trim($text);

// Strip accidental ``` / ```json fences.
if (strpos($text, '```') !== false) {
    $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
    $text = preg_replace('/\s*```\s*$/', '', $text);
    $text = trim($text);
}
// Trim any prose before the first { or after the last }.
$first = strpos($text, '{');
$last  = strrpos($text, '}');
if ($first !== false && $last !== false && $last > $first) {
    $text = substr($text, $first, $last - $first + 1);
}

// Validate: it must parse as JSON before we hand it back.
$decoded = json_decode($text, true);
if (!is_array($decoded)) {
    echo json_encode(['error' => 'The model did not return valid JSON. Try again or edit the prompt.']); exit;
}

// Pretty-print so it drops cleanly into the editor.
$pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo json_encode(['schema' => $pretty]);
