<?php
// Suggest the PRIMARY service list for the niche, rough-ranked by tier.
// POST only. Auth + CSRF. Returns JSON: {services:[{service,tier}]} or {error:"..."}.
// One-shot Anthropic Messages API call (Haiku). Directional ranking — validate demand
// with a real keyword tool. Same call pattern as keyword_suggest.php.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Invalid request token.']); exit; }

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
if ($apiKey === '') { echo json_encode(['error' => 'ANTHROPIC_API_KEY is not configured (Admin → AI, or config.php).']); exit; }
if (!function_exists('curl_init')) { echo json_encode(['error' => 'PHP cURL extension is not available.']); exit; }

// Niche context: business name + a few existing services (from templates) so the model
// infers the niche without us hard-coding "pest control".
$business = ''; $examples = [];
try {
    $d = load_data();
    $business = trim($d['site_vars']['business'] ?? '');
} catch (Throwable $e) {}
if (defined('TEMPLATES_FILE') && file_exists(TEMPLATES_FILE)) {
    $tpls = json_decode(file_get_contents(TEMPLATES_FILE), true) ?: [];
    foreach ($tpls as $t) {
        $n = trim($t['seo']['service_name'] ?? '') ?: trim(preg_replace('/\s*\|.*$/', '', $t['title'] ?? ''));
        if ($n !== '') $examples[] = $n;
    }
    $examples = array_slice(array_values(array_unique($examples)), 0, 8);
}
// Explicit niche (entered at the top of the Keywords tab) is authoritative.
$niche = trim($_POST['niche'] ?? '');
if (mb_strlen($niche) > 120) $niche = mb_substr($niche, 0, 120);

$ctxLines = [];
if ($niche !== '')     $ctxLines[] = "Niche / vertical: \"{$niche}\" (this is the site's service category).";
if ($business !== '')  $ctxLines[] = "Business: \"{$business}\".";
if ($examples)         $ctxLines[] = 'Existing service pages: ' . implode(', ', $examples) . '.';
$ctx = $ctxLines ? (implode(' ', $ctxLines) . ' ') : '';

// Optional real keyword data (pasted from Ahrefs) — grounds the ranking.
$ahrefs = (string)($_POST['ahrefs_data'] ?? '');
if (strlen($ahrefs) > 60000) $ahrefs = substr($ahrefs, 0, 60000);   // token guard
$ahrefs   = trim($ahrefs);
$grounded = $ahrefs !== '';

if ($grounded) {
    $prompt =
    "You are planning the landing-page service list for a LOCAL lead-generation website. {$ctx}".
    "Model: ONE site per city, organic ranking (no Google Business Profile), long-tail \"[service] [city]\" in mid-size, low-competition cities.\n\n".
    "Below is REAL keyword data (pasted from Ahrefs; columns are roughly Keyword, Volume, KD [difficulty 0-100], CPC). Treat it as the source of truth:\n".
    "1. Cluster the keywords into distinct SERVICES (consolidate variants of the same service — control/treatment/exterminator = ONE).\n".
    "2. Per service judge: demand = representative Volume; winnability = KD (LOWER is better — the site has little authority); lead value = CPC (higher = more valuable lead).\n".
    "3. Tier: high = solid volume + low/moderate KD + good CPC; medium = mixed; low = tiny volume or low value (cut/fold candidate).\n".
    "Report the representative Volume and KD you used per service (integers; KD 0-100).\n\n".
    "KEYWORD DATA:\n{$ahrefs}\n\n".
    "Return ONLY JSON, no prose: {\"services\":[{\"service\":\"Service Name\",\"tier\":\"high\",\"volume\":1200,\"kd\":9}, ...]} — up to 20 services, most valuable first.";
    $model = 'claude-sonnet-5'; $maxTok = 4000; $timeout = 90;
} else {
    $prompt =
    "You are planning the landing-page service list for a LOCAL lead-generation website. {$ctx}".
    "The model: ONE site per city, ranking organically (no Google Business Profile), targeting long-tail \"[service] [city]\" queries in mid-size, low-competition cities.\n\n".
    "List the core services that each deserve their OWN landing page, and rank each into a tier:\n".
    "- high  = strong search demand AND high lead value AND winnable long-tail\n".
    "- medium= decent on one or two of those\n".
    "- low   = niche / low search volume / low lead value (candidate to cut or fold)\n\n".
    "Rules: 15-20 services max. Consolidate keyword variants of the SAME service into ONE (e.g. control/treatment/exterminator = one service). Favor high-lead-value services. Be realistic about demand.\n".
    "Return ONLY JSON, no prose: {\"services\":[{\"service\":\"Service Name\",\"tier\":\"high\"}, ...]}";
    $model = 'claude-haiku-4-5-20251001'; $maxTok = 1500; $timeout = 45;
}

$payload = json_encode([
    'model'      => $model,
    'max_tokens' => $maxTok,
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
    CURLOPT_TIMEOUT    => $timeout,
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

// Extract the JSON object from the response (tolerate stray prose/code fences).
$out = [];
if (preg_match('/\{.*\}/s', $text, $m)) {
    $parsed = json_decode($m[0], true);
    $validTier = ['high', 'medium', 'low'];
    foreach (($parsed['services'] ?? []) as $s) {
        $name = trim((string)($s['service'] ?? ''));
        if ($name === '') continue;
        $tier = in_array($s['tier'] ?? '', $validTier, true) ? $s['tier'] : 'medium';
        $row = ['service' => $name, 'tier' => $tier];
        if (isset($s['volume']) && $s['volume'] !== '') $row['volume'] = preg_replace('/[^0-9]/', '', (string)$s['volume']);
        if (isset($s['kd'])     && $s['kd']     !== '') $row['kd']     = preg_replace('/[^0-9]/', '', (string)$s['kd']);
        $out[] = $row;
        if (count($out) >= 25) break;
    }
}
if (!$out) { echo json_encode(['error' => 'Could not parse suggestions from the model. Try again.']); exit; }

echo json_encode(['services' => $out, 'grounded' => $grounded]);
