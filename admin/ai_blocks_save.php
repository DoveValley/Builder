<?php
// Save / add / delete entries in ai_block_types.json.
// POST only. CSRF protected.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=ai_blocks'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=ai_blocks&msg=error:Invalid+request+token');
    exit;
}

if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$action = $_POST['action'] ?? '';

function _abt_load(): array {
    if (!file_exists(AI_REGISTRY_FILE)) return [];
    $raw = json_decode(file_get_contents(AI_REGISTRY_FILE), true);
    return is_array($raw) ? $raw : [];
}

function _abt_save(array $types): bool {
    $dir = dirname(AI_REGISTRY_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode($types, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = AI_REGISTRY_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, AI_REGISTRY_FILE);
}

function _abt_parse_post(): array|string {
    $mode       = $_POST['ai_mode'] ?? 'standalone';
    $schemaJson = trim($_POST['ai_output_schema_json'] ?? '{}');
    $defaultJson = trim($_POST['default_fields_json']  ?? '{}');

    $schema   = json_decode($schemaJson, true);
    $defaults = json_decode($defaultJson, true);

    if (!is_array($schema))   return 'Output schema is not valid JSON.';
    if (!is_array($defaults)) return 'Default fields is not valid JSON.';

    $label  = trim($_POST['label']      ?? '');
    $prompt = trim($_POST['ai_prompt']  ?? '');
    if ($label === '')  return 'Label is required.';
    if ($prompt === '') return 'Prompt is required.';

    $isInject = ($mode === 'inject');

    if ($isInject) {
        if (trim($_POST['ai_inject_target'] ?? '') === '') return 'Inject target is required for inject mode.';
        if (trim($_POST['ai_inject_field']  ?? '') === '') return 'Inject field is required for inject mode.';
    } else {
        if (trim($_POST['ai_render_as'] ?? '') === '') return 'Render as (block type) is required for standalone mode.';
    }

    return [
        'label'            => $label,
        'description'      => trim($_POST['description'] ?? ''),
        'ai_mode'          => $isInject ? 'inject' : 'standalone',
        'ai_render_as'     => $isInject ? null : trim($_POST['ai_render_as'] ?? ''),
        'ai_model'         => in_array($_POST['ai_model'] ?? '', ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6'], true)
                              ? $_POST['ai_model']
                              : 'claude-haiku-4-5-20251001',
        'ai_inject_target' => $isInject ? (trim($_POST['ai_inject_target'] ?? '') ?: null) : null,
        'ai_inject_field'  => $isInject ? (trim($_POST['ai_inject_field']  ?? '') ?: null) : null,
        'ai_inject_mode'   => $isInject ? (in_array($_POST['ai_inject_mode'] ?? '', ['replace', 'append', 'prepend'], true) ? $_POST['ai_inject_mode'] : 'replace') : null,
        'ai_prompt'        => $prompt,
        'ai_output_schema' => $schema,
        'default_fields'   => $defaults,
    ];
}

// ── add ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $id = trim($_POST['block_type_id'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $id)) {
        header('Location: index.php?tab=ai_blocks&new=1&msg=error:' . urlencode('ID must be lowercase letters, digits, underscores (2–50 chars, start with a letter).'));
        exit;
    }

    $types = _abt_load();
    if (isset($types[$id])) {
        header('Location: index.php?tab=ai_blocks&new=1&msg=error:' . urlencode("ID \"$id\" already exists."));
        exit;
    }

    $fields = _abt_parse_post();
    if (is_string($fields)) {
        header('Location: index.php?tab=ai_blocks&new=1&msg=error:' . urlencode($fields));
        exit;
    }

    $types[$id] = $fields;

    if (!_abt_save($types)) {
        header('Location: index.php?tab=ai_blocks&new=1&msg=error:Could+not+save+registry');
        exit;
    }
    header('Location: index.php?tab=ai_blocks&edit=' . urlencode($id) . '&msg=success:Block+type+added');
    exit;
}

// ── save (edit existing) ──────────────────────────────────────────────────────
if ($action === 'save') {
    $id    = trim($_POST['block_type_id'] ?? '');
    $types = _abt_load();

    if (!isset($types[$id])) {
        header('Location: index.php?tab=ai_blocks&msg=error:Block+type+not+found');
        exit;
    }

    $fields = _abt_parse_post();
    if (is_string($fields)) {
        header('Location: index.php?tab=ai_blocks&edit=' . urlencode($id) . '&msg=error:' . urlencode($fields));
        exit;
    }

    $types[$id] = $fields;

    if (!_abt_save($types)) {
        header('Location: index.php?tab=ai_blocks&edit=' . urlencode($id) . '&msg=error:Could+not+save+registry');
        exit;
    }
    header('Location: index.php?tab=ai_blocks&edit=' . urlencode($id) . '&msg=success:Saved');
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id    = trim($_POST['block_type_id'] ?? '');
    $types = _abt_load();

    if (!isset($types[$id])) {
        header('Location: index.php?tab=ai_blocks&msg=error:Block+type+not+found');
        exit;
    }

    unset($types[$id]);

    if (!_abt_save($types)) {
        header('Location: index.php?tab=ai_blocks&msg=error:Could+not+delete+block+type');
        exit;
    }
    header('Location: index.php?tab=ai_blocks&msg=success:Block+type+deleted');
    exit;
}

header('Location: index.php?tab=ai_blocks');
exit;
