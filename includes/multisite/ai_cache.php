<?php
/**
 * Multisite AI content cache (Phase 2, §6a).
 *
 * generate.py fills ai_blocks in the ephemeral working dir — but that dir is
 * deleted every run, so without a cache each rebuild re-spends on the API. This
 * persists the generated copy per domain, OUTSIDE the working dir, and offers it
 * back before generate.py runs.
 *
 * Staleness is owned by the resolver, not this cache. generate.py keys each entry on
 * `_ai_input_hash` = hash(RESOLVED prompt + model) — the actual string sent to the
 * model, which already carries business/city/service/neighborhoods/etc. This file does
 * NO hashing and models nothing about which fields feed a prompt; it just stores and
 * offers copy. On inject it hands each block its cached value plus the hash it was made
 * under (`_ai_cache_hash`) WITHOUT locking; generate.py reuses it only if the freshly
 * resolved prompt still hashes the same, else regenerates. So a research/prompt/model
 * change invalidates automatically, with no shadow field list to keep in sync.
 *
 * Contract:
 *   - keyed by each ai_block's stable `id` (home/core) or page-file+type+occurrence (landing)
 *   - each entry stores the `input_hash` generate.py stamped and the generated `value`
 *   - self-healing: missing/mismatched hash → generate.py regenerates; orphan entry → ignored
 *
 * Cache lives at sites/{master}/multisite/cache/{domainSlug}.json (gitignored).
 */

/** Block keys that are static config/scaffold — never part of the cached value. */
const MS_AI_CONFIG_KEYS = [
    'type', 'id', 'ai_type_id', 'ai_mode', 'ai_render_as', 'ai_model',
    'ai_inject_target', 'ai_inject_field', 'ai_inject_mode', 'ai_prompt_override',
];

/** The generated payload of a block = content only (no static config, no `_`-prefixed meta). */
function ms_ai_block_value(array $block): array {
    $v = [];
    foreach ($block as $k => $val) {
        if ($k === '' || $k[0] === '_') continue;                 // drop _ai_* generation metadata
        if (in_array($k, MS_AI_CONFIG_KEYS, true)) continue;      // drop static config/scaffold
        $v[$k] = $val;
    }
    return $v;
}

/** True if a block is AI-driven: a standalone ai_block, or a real block carrying ai_type_id (enrich). */
function ms_ai_is_ai_block(array $b): bool {
    return ($b['type'] ?? '') === 'ai_block' || ($b['ai_type_id'] ?? '') !== '';
}

/** The cached value of a block. Standalone: whole block minus config/meta. Enrich: only the injected field. */
function ms_ai_cached_value(array $b, array $registry): array {
    if (($b['type'] ?? '') === 'ai_block') {
        return ms_ai_block_value($b);   // whole block is AI-generated
    }
    // Enrich: only the injected field is AI-generated — caching more would freeze static content.
    $typeId = $b['ai_type_id'] ?? '';
    $field  = $b['ai_inject_field'] ?? ($registry[$typeId]['ai_inject_field'] ?? '');
    return ($field !== '' && array_key_exists($field, $b)) ? [$field => $b[$field]] : [];
}

/** Walk every AI-driven block (home content_blocks + each core page) via a callback that may mutate. */
function ms_ai_walk_site(array &$site, callable $fn): void {
    if (isset($site['content_blocks']) && is_array($site['content_blocks'])) {
        foreach ($site['content_blocks'] as $i => &$b) {
            if (is_array($b) && ms_ai_is_ai_block($b)) $fn($b);
        }
        unset($b);
    }
    if (isset($site['pages']) && is_array($site['pages'])) {
        foreach ($site['pages'] as $pid => &$page) {
            if (!isset($page['content_blocks']) || !is_array($page['content_blocks'])) continue;
            foreach ($page['content_blocks'] as $i => &$b) {
                if (is_array($b) && ms_ai_is_ai_block($b)) $fn($b);
            }
            unset($b);
        }
        unset($page);
    }
}

/**
 * Landing pages (data/pages/*.json) are generated per-city from templates, so their
 * AI blocks have NO stable `id` and the SAME template block recurs across many city
 * pages. Key them by page-file + ai_type_id + per-page occurrence instead — unique
 * per (template, city, block). Namespaced with "page:" so it can't collide with the
 * home/core `id` keys sharing the cache's `fields` map.
 */
function ms_ai_page_key(string $pageBase, string $aiTypeId, int $occ): string {
    return 'page:' . $pageBase . '::' . $aiTypeId . '#' . $occ;
}

/** Walk one landing page's AI blocks, passing each block plus its per-type occurrence index. */
function ms_ai_walk_page_blocks(array &$page, callable $fn): void {
    if (!isset($page['content_blocks']) || !is_array($page['content_blocks'])) return;
    $occ = [];
    foreach ($page['content_blocks'] as &$b) {
        if (!is_array($b) || !ms_ai_is_ai_block($b)) continue;
        $tid = $b['ai_type_id'] ?? '';
        $i = $occ[$tid] ?? 0; $occ[$tid] = $i + 1;
        $fn($b, $i);
    }
    unset($b);
}

/** All landing page JSON files in the working dir (empty if none). */
function ms_ai_pages_files(string $workingDir): array {
    return glob($workingDir . '/data/pages/*.json') ?: [];
}

/**
 * Offer cached copy to the working site BEFORE generate.py: inject each cached block's
 * value plus the hash it was generated under (`_ai_cache_hash`), WITHOUT locking. Whether
 * to reuse is decided by generate.py (resolved-prompt hash), so this file computes nothing.
 * @return array ['candidates' => int]  — blocks offered for reuse.
 */
function ms_ai_inject_from_cache(string $workingDir, string $cacheFile, array $registry): array {
    $siteFile = $workingDir . '/data/site.json';
    if (!file_exists($siteFile) || !file_exists($cacheFile)) return ['candidates' => 0];
    $site  = json_decode(file_get_contents($siteFile), true);
    $cache = json_decode(file_get_contents($cacheFile), true);
    if (!is_array($site) || !is_array($cache)) return ['candidates' => 0];
    $entries = $cache['fields'] ?? [];

    $candidates = 0;
    ms_ai_walk_site($site, function (array &$b) use ($entries, &$candidates) {
        $id = $b['id'] ?? '';
        if ($id === '' || !isset($entries[$id])) return;
        foreach (($entries[$id]['value'] ?? []) as $k => $v) $b[$k] = $v;
        $b['_ai_cache_hash'] = $entries[$id]['input_hash'] ?? '';
        $candidates++;
    });
    if ($candidates > 0) {
        $tmp = $siteFile . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $siteFile);
    }

    foreach (ms_ai_pages_files($workingDir) as $pf) {
        $page = json_decode(file_get_contents($pf), true);
        if (!is_array($page)) continue;
        $base = basename($pf, '.json');
        $pageHits = 0;
        ms_ai_walk_page_blocks($page, function (array &$b, int $occ) use ($entries, $base, &$candidates, &$pageHits) {
            $key = ms_ai_page_key($base, $b['ai_type_id'] ?? '', $occ);
            if (!isset($entries[$key])) return;
            foreach (($entries[$key]['value'] ?? []) as $k => $v) $b[$k] = $v;
            $b['_ai_cache_hash'] = $entries[$key]['input_hash'] ?? '';
            $candidates++; $pageHits++;
        });
        if ($pageHits > 0) {
            $tmp = $pf . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            rename($tmp, $pf);
        }
    }

    return ['candidates' => $candidates];
}

/**
 * Extract every block generate.py stamped with `_ai_input_hash` into the per-domain cache
 * AFTER generate.py — whether freshly generated or reused. Stores the stamp verbatim (no
 * hashing here). Overwrites entries for changed blocks; leaves others.
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
        if (($b['_ai_input_hash'] ?? '') === '') return;   // only blocks generate.py stamped
        $id = $b['id'] ?? '';
        if ($id === '') {                                  // stable id required to cache (§6a rule 1)
            if (function_exists('progress_log')) {
                progress_log("AI block '" . ($b['ai_type_id'] ?? '?') . "' has no stable id — not cached; it will regenerate (and cost) on every run.", 'warn');
            }
            return;
        }
        $fields[$id] = [
            'ai_type_id' => $b['ai_type_id'] ?? '',
            'input_hash' => $b['_ai_input_hash'],
            'value'      => ms_ai_cached_value($b, $registry),
        ];
    });

    foreach (ms_ai_pages_files($workingDir) as $pf) {
        $page = json_decode(file_get_contents($pf), true);
        if (!is_array($page)) continue;
        $base = basename($pf, '.json');
        ms_ai_walk_page_blocks($page, function (array &$b, int $occ) use (&$fields, $registry, $base) {
            if (($b['_ai_input_hash'] ?? '') === '') return;
            $key = ms_ai_page_key($base, $b['ai_type_id'] ?? '', $occ);
            $fields[$key] = [
                'ai_type_id' => $b['ai_type_id'] ?? '',
                'input_hash' => $b['_ai_input_hash'],
                'value'      => ms_ai_cached_value($b, $registry),
            ];
        });
    }

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
