<?php
/**
 * AI registry compiler.
 *
 * Merges the shared, seed-once archetypes (multisite/ai/archetypes.json) with a
 * per-niche brief (sites/{master}/multisite/niche_brief.json) and writes the
 * master's data/ai_block_types.json (fully generated / OVERWRITE).
 *
 * Placeholders in an archetype prompt_skeleton:
 *   [[shared.guardrail]] / [[shared.tone_line]]  → the shared blocks from archetypes._shared
 *   [[brief.<field>]]                            → a value from the niche brief
 *   {runtime}  ({business},{city},{state},…)     → left intact for generate.py
 *
 * Usage (CLI):  php multisite/ai/compile.php --master=pest-template
 *               php multisite/ai/compile.php --brief=/path/brief.json --out=/path/ai_block_types.json
 */

/** Flatten a brief value into a prompt-ready string (arrays become comma lists). */
function _ms_ai_brief_val($v): string {
    if (is_array($v)) return implode(', ', array_map('strval', $v));
    if (is_bool($v))  return $v ? 'yes' : 'no';
    return (string)$v;
}

/**
 * Compile a niche brief into a registry array.
 * Returns ['registry'=>array, 'written'=>string[], 'skipped'=>string[], 'errors'=>string[]].
 */
function ms_ai_compile(array $archetypes, array $brief): array {
    $shared  = $archetypes['_shared'] ?? [];
    $enabled = $brief['enabled_archetypes'] ?? [];
    $usesResearch = !empty($brief['uses_research_fields']);

    $registry = [];
    $written = $skipped = $errors = [];

    foreach ($enabled as $id) {
        if (!is_string($id) || $id === '' || $id[0] === '_') { continue; }
        $arch = $archetypes[$id] ?? null;
        if (!is_array($arch)) { $errors[] = "Unknown archetype: {$id}"; continue; }
        if (!empty($arch['requires_research']) && !$usesResearch) {
            $skipped[] = "{$id} (requires research fields; brief has uses_research_fields=false)";
            continue;
        }

        // 1. Inline the shared blocks into the skeleton, then fill brief placeholders.
        $prompt = (string)($arch['prompt_skeleton'] ?? '');
        $prompt = str_replace(
            ['[[shared.guardrail]]', '[[shared.tone_line]]'],
            [(string)($shared['guardrail'] ?? ''), (string)($shared['tone_line'] ?? '')],
            $prompt
        );
        // Fill every [[brief.<field>]] token (in both the skeleton and the inlined shared blocks).
        $prompt = preg_replace_callback('/\[\[brief\.([a-z_]+)\]\]/', function ($m) use ($brief) {
            return _ms_ai_brief_val($brief[$m[1]] ?? '');
        }, $prompt);

        // 2. Assemble the registry entry from the archetype's structural fields.
        $entry = [
            'label'            => $arch['label']            ?? $id,
            'description'      => $arch['description']       ?? '',
            'ai_mode'          => $arch['ai_mode']           ?? 'standalone',
            'ai_render_as'     => $arch['ai_render_as']      ?? null,
            'ai_model'         => $arch['ai_model']          ?? (function_exists('model_default') ? model_default() : 'claude-haiku-4-5-20251001'),
            'ai_inject_target' => $arch['ai_inject_target']  ?? null,
            'ai_inject_field'  => $arch['ai_inject_field']   ?? null,
            'ai_inject_mode'   => $arch['ai_inject_mode']    ?? null,
            'ai_prompt'        => $prompt,
            'ai_output_schema' => $arch['ai_output_schema']  ?? new stdClass(),
            'default_fields'   => $arch['default_fields']    ?? [],
            '_compiled_from'   => $id,
            '_niche'           => $brief['niche'] ?? '',
        ];
        $registry[$id] = $entry;
        $written[] = $id;
    }

    return ['registry' => $registry, 'written' => $written, 'skipped' => $skipped, 'errors' => $errors];
}

/** Atomic write of the registry JSON. Returns true on success. */
function ms_ai_write_registry(string $outFile, array $registry): bool {
    $dir = dirname($outFile);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $outFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) return false;
    return rename($tmp, $outFile);
}

/**
 * Merge freshly-compiled entries into the existing registry WITHOUT clobbering
 * hand-authored ones. Compile owns only entries it created (stamped `_compiled_from`):
 *   - hand-authored (no `_compiled_from`) → always preserved, never overwritten
 *     (even if an enabled archetype shares the id — the archetype is skipped);
 *   - previously-compiled entry still enabled → replaced by the fresh compile;
 *   - previously-compiled entry no longer enabled → dropped (unchecking removes it);
 *   - new enabled archetype → added.
 * @return array ['registry' => array, 'skipped' => string[]]  skipped = archetype ids not
 *               written because a hand-authored block already owns that id.
 */
function ms_ai_merge_registry(array $existing, array $compiled): array {
    $out = [];
    $skipped = [];

    // 1. Keep every hand-authored (unstamped) entry — compile never created these.
    foreach ($existing as $id => $entry) {
        if (is_array($entry) && empty($entry['_compiled_from'])) $out[$id] = $entry;
    }
    // 2. Add/replace compiled entries — but never overwrite a preserved hand-authored id.
    foreach ($compiled as $id => $entry) {
        if (isset($out[$id])) { $skipped[] = $id; continue; }
        $out[$id] = $entry;
    }
    // (Previously-compiled entries whose archetype is no longer enabled are stamped, so they
    //  were excluded in step 1 and absent from $compiled → correctly dropped.)
    return ['registry' => $out, 'skipped' => $skipped];
}

/**
 * High-level: compile a master's brief → its ai_block_types.json.
 * Returns the ms_ai_compile() result plus 'out' (path) and 'ok' (bool).
 */
function ms_ai_compile_master(string $baseDir, string $masterId): array {
    $archFile  = $baseDir . '/multisite/ai/archetypes.json';
    $ovFile    = $baseDir . '/sites/' . $masterId . '/multisite/archetypes.json';
    $briefFile = $baseDir . '/sites/' . $masterId . '/multisite/niche_brief.json';
    $outFile   = $baseDir . '/sites/' . $masterId . '/data/ai_block_types.json';

    $arch  = json_decode(@file_get_contents($archFile), true);
    $brief = json_decode(@file_get_contents($briefFile), true);
    if (!is_array($arch))  return ['ok' => false, 'errors' => ["Cannot read archetypes: {$archFile}"], 'written' => [], 'skipped' => []];

    // Per-master prompt overrides (edited on the Niche Brief tab): shallow-merge each
    // archetype's overridden keys (label/description/ai_model/prompt_skeleton) over the
    // shared seed. Only present keys override; everything else falls back to the seed.
    $ov = is_file($ovFile) ? json_decode((string)@file_get_contents($ovFile), true) : null;
    if (is_array($ov)) {
        foreach ($ov as $id => $fields) {
            if ($id === '_about' || !is_array($fields)) continue;
            $arch[$id] = (isset($arch[$id]) && is_array($arch[$id])) ? array_replace($arch[$id], $fields) : $fields;
        }
    }
    if (!is_array($brief)) return ['ok' => false, 'errors' => ["Cannot read niche brief: {$briefFile}"], 'written' => [], 'skipped' => []];

    $res = ms_ai_compile($arch, $brief);

    // Non-destructive: merge the compiled entries into the existing registry so hand-authored
    // (unstamped) block types survive a recompile instead of being silently overwritten/dropped.
    $existing = is_file($outFile) ? (json_decode((string)@file_get_contents($outFile), true) ?: []) : [];
    $merged   = ms_ai_merge_registry($existing, $res['registry']);
    $res['registry'] = $merged['registry'];
    foreach ($merged['skipped'] as $id) {
        $res['skipped'][] = "{$id} (a hand-authored block already uses this id — kept it; archetype not written)";
    }

    $res['out'] = $outFile;
    $res['ok']  = ms_ai_write_registry($outFile, $res['registry']);
    if (!$res['ok']) $res['errors'][] = "Failed to write {$outFile}";
    return $res;
}

// ── CLI entrypoint ────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $opts = getopt('', ['master:', 'brief:', 'arch:', 'out:']);
    $baseDir = dirname(__DIR__, 2); // project root

    if (!empty($opts['master'])) {
        $res = ms_ai_compile_master($baseDir, $opts['master']);
    } else {
        $archFile  = $opts['arch']  ?? ($baseDir . '/multisite/ai/archetypes.json');
        $briefFile = $opts['brief'] ?? null;
        $outFile   = $opts['out']   ?? null;
        if (!$briefFile || !$outFile) { fwrite(STDERR, "Need --master=ID  OR  --brief=FILE --out=FILE\n"); exit(2); }
        $arch  = json_decode((string)@file_get_contents($archFile), true) ?: [];
        $brief = json_decode((string)@file_get_contents($briefFile), true) ?: [];
        $res = ms_ai_compile($arch, $brief);
        $res['out'] = $outFile;
        $res['ok']  = ms_ai_write_registry($outFile, $res['registry']);
    }

    fwrite(STDOUT, "Compiled: " . count($res['written']) . " block(s) → " . ($res['out'] ?? '?') . "\n");
    if ($res['written']) fwrite(STDOUT, "  written: " . implode(', ', $res['written']) . "\n");
    if (!empty($res['skipped'])) fwrite(STDOUT, "  skipped: " . implode(' | ', $res['skipped']) . "\n");
    if (!empty($res['errors']))  fwrite(STDERR, "  errors:  " . implode(' | ', $res['errors']) . "\n");
    exit($res['ok'] ? 0 : 1);
}
