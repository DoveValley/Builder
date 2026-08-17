<?php
/**
 * infra/actions/dfinder_ai.php — D.Finder's model calls, with the key kept server-side.
 *
 * In the artifact the workbench POSTed straight to api.anthropic.com with NO
 * x-api-key: the artifact host injected credentials into the request. Nothing
 * outside that host does, and the key cannot move into the browser — anything in
 * client code is readable by anyone who opens devtools.
 *
 * So the two calls come here instead. This is a thin route, not a client: the
 * talking to Anthropic is includes/anthropic.php's job, per the standing rule that
 * one module owns each outside service. That module already exists because three
 * callers hand-rolled this same request and had drifted to different models and
 * different ways of reading the reply. A fourth hand-rolled copy here would be the
 * same mistake with a new name.
 *
 * Asks for a TIER, not a model string. The .jsx used to pin claude-sonnet-4-6;
 * now when a model is superseded, includes/anthropic.php changes and this moves
 * with it.
 *
 * Answers in the Messages API's own shape — {"content":[{"type":"text",...}]} —
 * so the workbench parses replies with exactly the code it always had: strip
 * markdown fences, extract the JSON between brackets. That handling is
 * load-bearing (the model sometimes wraps its JSON in prose) and reshaping the
 * response here would have meant rewriting it.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/anthropic.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request (bad CSRF token).']);
    exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();

$prompt = trim((string) ($_POST['prompt'] ?? ''));
$system = trim((string) ($_POST['system'] ?? ''));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nothing to ask.']);
    exit;
}

// The workbench asks for 1000; clamp rather than trust, since the field crosses
// the wire. Both prompts return a short JSON array.
$maxTokens = (int) ($_POST['max_tokens'] ?? 1000);
$maxTokens = max(256, min(4000, $maxTokens));

$r = anthropic_message($prompt, [
    // Naming a candidate list against six ranked rules is judgement, not lookup.
    'model'      => ANTHROPIC_SMART,
    'system'     => $system,
    'max_tokens' => $maxTokens,
    // Generating twelve names takes longer than the module's default.
    'timeout'    => 90,
]);

if (!$r['ok']) {
    // Rate limiting is a different instruction to the operator than failure: it
    // means press the button again shortly, not that something is broken. 503
    // rather than 500 so it reads as temporary on the wire too.
    http_response_code($r['rate_limited'] ? 503 : 502);
    echo json_encode(['error' => $r['error'] ?: 'The model call failed.', 'rate_limited' => $r['rate_limited']]);
    exit;
}

echo json_encode(['content' => [['type' => 'text', 'text' => $r['text']]]]);
