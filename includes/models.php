<?php
/**
 * Claude model catalog — the single PHP-side reader of includes/models.json (the same
 * file generate.py reads). Every editor dropdown and every save-handler whitelist should
 * go through model_ids() / model_is_valid() / model_options() so there is exactly one
 * list of valid models and one place to change it. See includes/models.json.
 */

/** Full catalog: ['default' => id, 'models' => [id => [label,tier,input,output], ...]]. Cached per request. */
function models_catalog(): array {
    static $cat = null;
    if ($cat !== null) return $cat;
    $raw = @json_decode((string)@file_get_contents(__DIR__ . '/models.json'), true);
    $models = (is_array($raw) && isset($raw['models']) && is_array($raw['models'])) ? $raw['models'] : [];
    $default = (is_array($raw) ? ($raw['default'] ?? '') : '');
    if ($default === '' || !isset($models[$default])) $default = array_key_first($models) ?: '';
    $cat = ['default' => $default, 'models' => $models];
    return $cat;
}

/** Ordered list of valid model IDs. */
function model_ids(): array {
    return array_keys(models_catalog()['models']);
}

/** The default/fallback model ID. */
function model_default(): string {
    return models_catalog()['default'];
}

/** Is $id an offered model? */
function model_is_valid(string $id): bool {
    return isset(models_catalog()['models'][$id]);
}

/**
 * Sanitize a posted model value: return it if valid, else the default (never trust raw input).
 * $fallback lets a caller pin a role-specific default (e.g. keep an existing block on its model).
 */
function model_or_default(?string $id, ?string $fallback = null): string {
    $id = trim((string)$id);
    if (model_is_valid($id)) return $id;
    if ($fallback !== null && model_is_valid($fallback)) return $fallback;
    return model_default();
}

/** id => label map for building <select> options. */
function model_options(): array {
    $out = [];
    foreach (models_catalog()['models'] as $id => $m) $out[$id] = $m['label'] ?? $id;
    return $out;
}

/** Ready-made <option> tags; marks $selected (falls back to default) as selected. */
function model_options_html(string $selected = ''): string {
    if (!model_is_valid($selected)) $selected = model_default();
    $html = '';
    foreach (model_options() as $id => $label) {
        $sel = $id === $selected ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($id, ENT_QUOTES) . '"' . $sel . '>'
               . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    return $html;
}
