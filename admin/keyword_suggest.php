<?php
// Suggest secondary SEO keywords for a page's primary keyword.
// POST only. Requires admin auth + CSRF. Returns JSON: {keywords:"a, b, c"} or {error:"..."}.
// One-shot call straight to the Anthropic Messages API (Haiku — cheap/fast); no generate.py.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Invalid request token.']); exit; }

$primary = trim($_POST['primary_keyword'] ?? '');
if ($primary === '' || mb_strlen($primary) > 120) { echo json_encode(['error' => 'Enter a primary keyword first.']); exit; }

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
if ($apiKey === '') { echo json_encode(['error' => 'ANTHROPIC_API_KEY is not configured (Admin → AI, or config.php).']); exit; }
if (!function_exists('curl_init')) { echo json_encode(['error' => 'PHP cURL extension is not available.']); exit; }

// Business context from the active site (keywords stay location-free).
$business = '';
try { $d = load_data(); $business = trim($d['site_vars']['business'] ?? ''); } catch (Throwable $e) {}
$ctx = $business !== '' ? " for the business \"{$business}\"" : '';

$prompt = "List 6-10 secondary SEO keywords closely related to the primary keyword \"{$primary}\"{$ctx}. "
        . "These are related search topics or services the page should also cover. "
        . "Keep them LOCATION-FREE — do not include any city, state, or region name. "
        . "Return ONLY a single comma-separated line, lowercase, no numbering, no quotes, no other text.";

$payload = json_encode([
    'model'      => 'claude-haiku-4-5',
    'max_tokens' => 200,
    'messages'   => [['role' => 'user', 'content' => $prompt]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT    => 30,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($resp === false) { echo json_encode(['error' => 'Request failed: ' . $curlErr]); exit; }
$j = json_decode($resp, true);
if ($httpCode !== 200 || !is_array($j)) {
    echo json_encode(['error' => $j['error']['message'] ?? ('API error (' . $httpCode . ').')]); exit;
}

$text = '';
foreach (($j['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $text .= $block['text']; }

// Normalize model output → clean, deduped comma list.
$out = []; $seen = [];
foreach (preg_split('/[,\n]+/', $text) as $p) {
    $p = trim($p);
    $p = preg_replace('/^\s*(?:\d+[.)]\s*|[-•–—*]\s*)/u', '', $p);  // strip list markers "1. " / "- " (u: multibyte-safe)
    $p = trim($p, " \t\r\n\"'.");
    if ($p === '') continue;
    $key = mb_strtolower($p);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $p;
    if (count($out) >= 12) break;
}

echo json_encode(['keywords' => implode(', ', $out)]);
