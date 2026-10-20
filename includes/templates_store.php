<?php
// Templates store — the read/write primitives for a site's templates.json.
//
// Lifted out of admin/templates_save.php so they can be reused. That file is a POST
// handler: it exits at the top on the login guard, the REQUEST_METHOD check and the
// CSRF check, so requiring it from anywhere else (a CLI one-off, another endpoint)
// never reaches the functions at all. The panel keeps calling these through its own
// thin _tpl_load()/_tpl_save() wrappers, so there is still exactly ONE implementation
// of "how templates.json is written" rather than a second copy living in a script.
//
// Writes are atomic (tmp + rename) and use the same JSON flags as every other writer
// in the codebase, so a file written here is byte-comparable with one written by the
// panel — see save_data() in includes/data.php for the same shape.

/** Load this site's templates. Missing or malformed file reads as an empty list. */
function tpl_load(): array {
    if (!defined('TEMPLATES_FILE') || !file_exists(TEMPLATES_FILE)) return [];
    $raw = json_decode((string) file_get_contents(TEMPLATES_FILE), true);
    return is_array($raw) ? $raw : [];
}

/**
 * Write the template list back. array_values() so a list that had keys removed is
 * re-encoded as a JSON array and never silently becomes an object.
 */
function tpl_save(array $templates): bool {
    if (!defined('TEMPLATES_FILE')) return false;
    $dir = dirname(TEMPLATES_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode(array_values($templates), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = TEMPLATES_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, TEMPLATES_FILE);
}
