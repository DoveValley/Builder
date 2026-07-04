<?php
/**
 * Save per-master AI archetype prompt overrides (Niche Brief tab → Prompt
 * templates). Stores only the fields that differ from the shared seed
 * (multisite/ai/archetypes.json) into sites/{master}/multisite/archetypes.json,
 * then recompiles the master's ai_block_types.json. Isolated endpoint, same CSRF
 * + redirect pattern as niche_brief_save.php.
 *
 * POST arrays keyed by archetype id: label[id], description[id], ai_model[id],
 * prompt_skeleton[id]  (+ csrf_token)
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in']))          { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')        { header('Location: index.php?tab=niche_brief'); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: index.php?tab=niche_brief&msg=error:Invalid+request+token'); exit;
}
if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

function _arch_redirect(string $type, string $text): void {
    header('Location: index.php?tab=niche_brief&msg=' . $type . ':' . rawurlencode($text) . '#ms-archetypes');
    exit;
}

$shared = json_decode((string)@file_get_contents(BASE_DIR . '/multisite/ai/archetypes.json'), true);
if (!is_array($shared)) _arch_redirect('error', 'Cannot read the shared archetypes.');

$EDITABLE = ['label', 'description', 'ai_model', 'prompt_skeleton'];
$override  = ['_about' => 'Per-master AI archetype overrides. Only the fields set here override multisite/ai/archetypes.json; everything else falls back to the shared seed. Written by the Niche Brief tab → Prompt templates.'];

foreach ($shared as $id => $seed) {
    if ($id === '_about' || $id === '_shared' || !is_array($seed)) continue;   // real archetypes only
    $diff = [];
    foreach ($EDITABLE as $k) {
        if (!isset($_POST[$k][$id])) continue;
        $val = (string)$_POST[$k][$id];
        if ($k === 'ai_model') {
            $val = trim($val);
            if (!preg_match('/^claude-[a-z0-9.\-]+$/i', $val)) continue;        // ignore a malformed model
        } else {
            $val = ($k === 'prompt_skeleton') ? rtrim($val) : trim($val);       // keep skeleton's internal newlines
        }
        if ($val !== (string)($seed[$k] ?? '')) $diff[$k] = $val;               // store only genuine overrides
    }
    if ($diff) $override[$id] = $diff;
}

$ovFile = ACTIVE_SITE_DIR . '/multisite/archetypes.json';
if (count($override) <= 1) {                        // nothing overridden → remove the file (all defaults)
    @unlink($ovFile);
} else {
    if (!is_dir(dirname($ovFile))) @mkdir(dirname($ovFile), 0775, true);
    $tmp = $ovFile . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode($override, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $ovFile)) {
        @unlink($tmp);
        _arch_redirect('error', 'Could not write archetype overrides (check file permissions).');
    }
}

// Recompile the master's registry so the edits take effect.
require_once BASE_DIR . '/multisite/ai/compile.php';
$res = ms_ai_compile_master(BASE_DIR, ACTIVE_SITE_ID);
if (empty($res['ok'])) _arch_redirect('error', 'Saved, but compile failed: ' . implode('; ', $res['errors'] ?: ['unknown']));

$n = count($override) - 1;
_arch_redirect('success', $n === 0 ? 'Prompts reset to defaults; recompiled.' : "Saved {$n} prompt override(s); recompiled " . count($res['written']) . ' block(s).');
