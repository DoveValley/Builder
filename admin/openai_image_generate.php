<?php
// Test Lab AI-image-generator sample. POST only. Requires admin auth + CSRF.
// Generates one image via the OpenAI Images module and saves it under
// admin/_labshots/ (Test Lab's own sandbox — not any site's uploads/).
// Returns JSON: {success, url, revised_prompt} or {success:false, error}.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/openai_images.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid request token.']); exit; }

$ready = openai_images_ready();
if (!$ready['ok']) { echo json_encode(['success' => false, 'error' => $ready['error']]); exit; }

$prompt = trim($_POST['prompt'] ?? '');
if ($prompt === '' || mb_strlen($prompt) > 2000) { echo json_encode(['success' => false, 'error' => 'Enter a prompt (1-2000 chars).']); exit; }

$size = trim($_POST['size'] ?? '');
$allowedSizes = ['auto', '1024x1024', '1536x1024', '1024x1536'];
if (!in_array($size, $allowedSizes, true)) $size = 'auto';

$quality = trim($_POST['quality'] ?? '');
$allowedQuality = ['auto', 'low', 'medium', 'high'];
if (!in_array($quality, $allowedQuality, true)) $quality = 'auto';

$r = openai_images_generate($prompt, [
    'size'           => $size,
    'quality'        => $quality,
    'output_format'  => 'webp',
    'n'              => 1,
]);

if (!$r['ok']) { echo json_encode(['success' => false, 'error' => $r['error']]); exit; }

$dir = __DIR__ . '/_labshots';
if (!is_dir($dir)) mkdir($dir, 0775, true);

$filename = 'ai_generated_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.webp';
$path = $dir . '/' . $filename;
if (file_put_contents($path, $r['bytes']) === false) {
    echo json_encode(['success' => false, 'error' => 'Generated OK but could not save the file.']);
    exit;
}

echo json_encode([
    'success'        => true,
    'url'            => '_labshots/' . $filename,
    'revised_prompt' => $r['revised_prompt'],
]);
