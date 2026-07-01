<?php
/**
 * Multisite AI content cache (Phase 2, §6a).
 *
 * generate.py fills ai_blocks in the ephemeral working dir — but that dir is
 * deleted every run, so without a cache each rebuild re-spends on the API. This
 * persists the generated copy per domain, OUTSIDE the working dir, and re-injects
 * it before generate.py runs so filled blocks are locked and skipped (zero calls).
 *
 * Contract (§6a):
 *   - keyed by each ai_block's stable `id`
 *   - each entry stamps the prompt_hash that produced it
 *   - self-healing: hash match → reuse (lock); hash mismatch → regenerate (leave
 *     unlocked); missing block id → generate; orphaned cache entry → ignore.
 *
 * Cache lives at sites/{master}/multisite/cache/{domainSlug}.json (gitignored).
 */

/** Block keys that are static config/scaffold — never part of the cached value. */
const MS_AI_CONFIG_KEYS = [
    'type', 'id', 'ai_type_id', 'ai_mode', 'ai_render_as', 'ai_model',
    'ai_inject_target', 'ai_inject_field', 'ai_inject_mode', 'ai_prompt_override',
];

/** Effective prompt template for a block (override or registry), hashed for staleness. */
function ms_ai_prompt_hash(array $block, array $registry): string {
    $typeId = $block['ai_type_id'] ?? '';
    $tmpl = ($block['ai_prompt_override'] ?? '') !== ''
        ? $block['ai_prompt_override']
        : ($registry[$typeId]['ai_prompt'] ?? '');
    return substr(hash('sha256', $typeId . "\0" . $tmpl), 0, 16);
}

/** The generated payload of a block = everything except static config keys. */
function ms_ai_block_value(array $block): array {
    $v = [];
    foreach ($block as $k => $val) {
        if (!in_array($k, MS_AI_CONFIG_KEYS, true)) $v[$k] = $val;
    }
    return $v;
}

/** Walk site.json's ai_blocks (home content_blocks + each core page) via a callback that may mutate. */
function ms_ai_walk_site(array &$site, callable $fn): void {
    if (isset($site['content_blocks']) && is_array($site['content_blocks'])) {
        foreach ($site['content_blocks'] as $i => &$b) {
            if (($b['type'] ?? '') === 'ai_block') $fn($b);
        }
        unset($b);
    }
    if (isset($site['pages']) && is_array($site['pages'])) {
        foreach ($site['pages'] as $pid => &$page) {
            if (!isset($page['content_blocks']) || !is_array($page['content_blocks'])) continue;
            foreach ($page['content_blocks'] as $i => &$b) {
                if (($b['type'] ?? '') === 'ai_block') $fn($b);
            }
            unset($b);
        }
        unset($page);
    }
}

/**
 * Re-inject cached copy into the working site BEFORE generate.py.
 * Cache-hits (id present + prompt_hash matches) are filled and _ai_locked so
 * generate.py skips them. Misses / stale entries are left for generate.py to fill.
 * @return array ['hits'=>int, 'stale'=>int, 'misses'=>int]
 */
function ms_ai_inject_from_cache(string $workingDir, string $cacheFile, array $registry): array {
    $siteFile = $workingDir . '/data/site.json';
    if (!file_exists($siteFile) || !file_exists($cacheFile)) {
        return ['hits' => 0, 'stale' => 0, 'misses' => 0];
    }
    $site  = json_decode(file_get_contents($siteFile), true);
    $cache = json_decode(file_get_contents($cacheFile), true);
    if (!is_array($site) || !is_array($cache)) return ['hits' => 0, 'stale' => 0, 'misses' => 0];
    $entries = $cache['fields'] ?? [];

    $hits = 0; $stale = 0; $misses = 0;
    ms_ai_walk_site($site, function (array &$b) use ($entries, $registry, &$hits, &$stale, &$misses) {
        $id = $b['id'] ?? '';
        if ($id === '' || !isset($entries[$id])) { $misses++; return; }
        if (($entries[$id]['prompt_hash'] ?? '') !== ms_ai_prompt_hash($b, $registry)) { $stale++; return; }
        foreach (($entries[$id]['value'] ?? []) as $k => $v) $b[$k] = $v;
        $b['_ai_locked'] = true;   // generate.py will skip it
        $hits++;
    });

    if ($hits > 0) {
        $tmp = $siteFile . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $siteFile);
    }
    return ['hits' => $hits, 'stale' => $stale, 'misses' => $misses];
}

/**
 * Extract every filled ai_block from the working site into the per-domain cache
 * AFTER generate.py. Overwrites entries for regenerated blocks; leaves others.
 * @return int number of cached entries.
 */
function ms_ai_extract_to_cache(string $workingDir, string $cacheFile, array $registry): int {
    $siteFile = $workingDir . '/data/site.json';
    if (!file_exists($siteFile)) return 0;
    $site = json_decode(file_get_contents($siteFile), true);
    if (!is_array($site)) return 0;

    $existing = file_exists($cacheFile) ? (json_decode(file_get_contents($cacheFile), true) ?: []) : [];
    $fields = $existing['fields'] ?? [];

    ms_ai_walk_site($site, function (array &$b) use (&$fields, $registry) {
        if (empty($b['_ai_generated'])) return;      // only cache genuinely generated blocks
        $id = $b['id'] ?? '';
        if ($id === '') return;                       // stable id required to cache (§6a rule 1)
        $fields[$id] = [
            'ai_type_id'  => $b['ai_type_id'] ?? '',
            'prompt_hash' => ms_ai_prompt_hash($b, $registry),
            'value'       => ms_ai_block_value($b),
        ];
    });

    $out = [
        'generated_at' => gmdate('c'),
        'fields'       => $fields,
    ];
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tmp = $cacheFile . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $cacheFile);
    return count($fields);
}
