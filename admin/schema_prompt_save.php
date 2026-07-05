<?php
// Save (or reset) a schema-generator prompt override for the active site.
// POST only. Requires admin auth + CSRF. Returns JSON: {ok:true, overridden:bool} or {error}.
//   key     one of schema_prompt_keys()
//   prompt  the prompt text; blank OR identical-to-default resets to the built-in default

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/schema_prompts.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Invalid request token.']); exit; }

$key    = trim($_POST['key'] ?? '');
$prompt = (string)($_POST['prompt'] ?? '');
if (!in_array($key, schema_prompt_keys(), true)) { echo json_encode(['error' => 'Unknown prompt key.']); exit; }
if (mb_strlen($prompt) > 8000) { echo json_encode(['error' => 'Prompt is too long.']); exit; }

if (!schema_prompt_save_override($key, $prompt)) {
    echo json_encode(['error' => 'Could not save the prompt (check that data/ is writable).']); exit;
}

$overrides = schema_prompts_overrides();
echo json_encode(['ok' => true, 'overridden' => isset($overrides[$key])]);
