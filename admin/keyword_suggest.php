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

require_once __DIR__ . '/../includes/anthropic.php';
$ready = anthropic_ready();
if (!$ready['ok']) { echo json_encode(['error' => $ready['error']]); exit; }

// Business context from the active site (keywords stay location-free).
$business = '';
try { $d = load_data(); $business = trim($d['site_vars']['business'] ?? ''); } catch (Throwable $e) {}
$ctx = $business !== '' ? " for the business \"{$business}\"" : '';

$prompt = "List 6-10 secondary SEO keywords closely related to the primary keyword \"{$primary}\"{$ctx}. "
        . "These are related search topics or services the page should also cover. "
        . "Keep them LOCATION-FREE — do not include any city, state, or region name. "
        . "Return ONLY a single comma-separated line, lowercase, no numbering, no quotes, no other text.";

$r = anthropic_message($prompt, ['model' => ANTHROPIC_FAST, 'max_tokens' => 200, 'timeout' => 30]);
if (!$r['ok']) { echo json_encode(['error' => $r['error']]); exit; }
$text = $r['text'];

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
