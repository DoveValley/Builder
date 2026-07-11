<?php
/**
 * Block-library parity check (dev/CI).
 *
 * Verifies every content block type stays in sync across the four layers:
 *   1. includes/blocks.php        — render_content_block() `case`   (+ allowed_block_types registry)
 *   2. includes/editor.php        — `block-fields-<type>` panel
 *   3. includes/scripts.php       — `_createBlock` `block-fields-<type>` panel (creatable types only)
 *   4. includes/blocks_from_post.php — parse_blocks_from_post() `case`
 *
 * Also diffs the set of `name="field[]"` inputs between each type's editor panel and
 * scripts panel — a mismatch silently corrupts the positional POST arrays on save.
 *
 * Usage:  php tools/block_parity_check.php            (exit 0 = clean, 1 = gaps found)
 *         php tools/block_parity_check.php --quiet     (only print on failure)
 */

define('BASE', dirname(__DIR__));
require BASE . '/config.php';
require BASE . '/includes/functions.php';

$quiet = in_array('--quiet', $argv, true);
$blocksPhp  = file_get_contents(BASE . '/includes/blocks.php');
$editorPhp  = file_get_contents(BASE . '/includes/editor.php');
$scriptsPhp = file_get_contents(BASE . '/includes/scripts.php');
$savePhp    = file_get_contents(BASE . '/includes/blocks_from_post.php');

$allowed = array_keys(allowed_block_types());
// creatable types = everything offered in the block picker
$picker = [];
foreach (grouped_block_types() as $grp) $picker = array_merge($picker, array_keys($grp));
$picker = array_values(array_unique($picker));

// pseudo-blocks generated only by blog.php — intentionally excluded from the registry
$pseudo = ['post_meta', 'blog_list'];

$errors   = [];  // coverage gaps — hard failures (data loss / uneditable blocks)
$warnings = [];  // field-set diffs — advisory (often repeater fields added by separate JS)

// helper: does a render/save `case 'type':` exist?
$hasCase = fn(string $hay, string $t) => (bool) preg_match("/case\s+'" . preg_quote($t, '/') . "'\s*:/", $hay);
// helper: does a `block-fields-<type>` panel exist? (word-boundary so faq != faq_two_col)
$hasPanel = fn(string $hay, string $t) => (bool) preg_match('/block-fields-' . preg_quote($t, '/') . '(?![a-z0-9_])/', $hay);

// ── 1. Layer coverage ────────────────────────────────────────────────────────
echo "── Layer coverage ──\n";
foreach ($allowed as $t) {
    $miss = [];
    if (!$hasCase($blocksPhp, $t))  $miss[] = 'render';
    if (!$hasPanel($editorPhp, $t)) $miss[] = 'editor';
    if (!$hasCase($savePhp, $t))    $miss[] = 'save';
    // scripts panel required only for creatable (picker) types; ai_block is special-cased
    if (in_array($t, $picker, true) && $t !== 'ai_block' && !$hasPanel($scriptsPhp, $t)) $miss[] = 'scripts';
    if ($miss) $errors[] = "  [$t] missing: " . implode(', ', $miss);
}

// picker types must be in the allowed registry (else coerced to 'text' on save)
foreach ($picker as $t) {
    if ($t === 'ai_block') continue;
    if (!in_array($t, $allowed, true)) $errors[] = "  [$t] in picker but NOT in allowed_block_types() → coerced to text on save";
}

// render/editor/save impls that aren't in allowed → existing content silently coerced on edit-save
$implTypes = [];
if (preg_match_all("/case\s+'([a-z_]+)'\s*:/", $blocksPhp, $m)) $implTypes = array_unique($m[1]);
foreach ($implTypes as $t) {
    if (in_array($t, $pseudo, true) || in_array($t, $allowed, true)) continue;
    if ($hasPanel($editorPhp, $t) && $hasCase($savePhp, $t)) {
        $errors[] = "  [$t] fully implemented (render+editor+save) but NOT in allowed → legacy content coerced to text on edit-save";
    }
}

// ── 2. Field parity: editor panel vs scripts panel ───────────────────────────
// Extract the field names inside each `block-fields-<type>` region.
$panelFields = function (string $src, string $type): array {
    // slice from this type's panel marker to the next block-fields marker
    if (!preg_match('/block-fields-' . preg_quote($type, '/') . '(?![a-z0-9_])/', $src, $m, PREG_OFFSET_CAPTURE)) return [];
    $start = $m[0][1];
    $rest  = substr($src, $start + 1);
    $endRel = preg_match('/block-fields-[a-z0-9_]+/', $rest, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : strlen($rest);
    $region = substr($src, $start, $endRel + 1);
    // Match name="field[" — covers both positional (field[]) and editor-indexed
    // (field[ ...index... ]) forms. Base field name is captured before the '['.
    preg_match_all('/name="([a-z_][a-z0-9_]*)\[/i', $region, $fm);
    return array_values(array_unique($fm[1] ?? []));
};

echo "\n── Field parity (editor panel vs scripts panel) ──\n";
foreach ($picker as $t) {
    if ($t === 'ai_block') continue;
    if (!$hasPanel($editorPhp, $t) || !$hasPanel($scriptsPhp, $t)) continue; // coverage already flagged
    $ef = $panelFields($editorPhp, $t);
    $sf = $panelFields($scriptsPhp, $t);
    // A field "missing from the scripts panel" is benign if it appears ANYWHERE in
    // scripts.php — that means it's a repeater item field added by a separate add*()
    // JS function rather than the base _createBlock panel. Only flag block-level fields
    // that are truly absent from scripts.php entirely.
    $onlyEditor = array_filter(array_diff($ef, $sf), fn($f) => !preg_match('/name="' . preg_quote($f, '/') . '\[/', $scriptsPhp));
    if ($onlyEditor) {
        $warnings[] = "  [$t] block-level field(s) in editor but absent from scripts entirely: " . implode(', ', $onlyEditor);
    }
}

// ── Report ───────────────────────────────────────────────────────────────────
echo "\n";
if ($warnings) {
    echo "⚠️  " . count($warnings) . " field-parity warning(s) (review — many are Phase-2 indexed-field work):\n"
        . implode("\n", $warnings) . "\n\n";
}
if (!$errors) {
    echo "✅ COVERAGE OK — " . count($allowed) . " allowed types present across render/editor/scripts/save.\n";
    exit(0);
}
echo "❌ " . count($errors) . " coverage error(s) (data-loss / uneditable):\n" . implode("\n", $errors) . "\n";
exit(1);
