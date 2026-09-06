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

/**
 * Image fields, which are NOT generated content even on a standalone ai_block — the path comes
 * from the template scaffold and the model only ever echoes it back. Caching one freezes a
 * filename for the life of the cache, so when the media library changes the cache keeps
 * reinstating the old name and the page ships a 404. That is not theoretical: water-site's
 * Pic Drop images were deleted in 721a59b and cached ai_blocks were still asking for them
 * weeks later. Same reasoning as the enrich path below — cache what the model wrote, nothing else.
 */
const MS_AI_IMAGE_KEYS = [
    'hs_photo', 'hs_bg_photo', 'it_photo', 'fs_photo', 'hg_photo', 'wb_photo',
    'if_photo', 'lg_photo', 'mi_info_photo', 'mi_photo', 'photo', 'bg_photo',
];

/** The generated payload of a block = content only (no static config, no `_`-prefixed meta). */
function ms_ai_block_value(array $block): array {
    $v = [];
    foreach ($block as $k => $val) {
        if ($k === '' || $k[0] === '_') continue;                 // drop _ai_* generation metadata
        if (in_array($k, MS_AI_CONFIG_KEYS, true)) continue;      // drop static config/scaffold
        if (in_array($k, MS_AI_IMAGE_KEYS, true)) continue;       // drop image paths — see above
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
 * Clears `_ai_locked` on every AI block in the ephemeral clone (home, core pages, and
 * already-generated landing pages) before generate.py runs.
 *
 * A master's OWN ai_blocks are meant to be lockable — that protects a finished, live
 * single site from an accidental regen. But a generator MASTER's blocks are templates,
 * not finished copy: locked, they make every clone inherit the master's own baked text
 * (Lufkin's, or whatever city the master itself is written about) instead of writing
 * something new per city — see feedback_master_ai_blocks_unlocked. Depending on someone
 * remembering to pass --refresh/Force every real run is exactly how that slips through.
 *
 * Only ever writes the WORKING DIR copy passed in, never the master's own stored
 * site.json — a master that also doubles as its own live site stays protected when it's
 * edited directly; this only changes what a disposable clone made FROM it does.
 *
 * @return int  blocks unlocked
 */
function ms_ai_unlock_working_copy(string $workingDir): int {
    $unlocked = 0;
    $clearLock = function (array &$b) use (&$unlocked) {
        if (!empty($b['_ai_locked'])) { $b['_ai_locked'] = false; $unlocked++; }
    };

    $siteFile = $workingDir . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (is_array($site)) {
        ms_ai_walk_site($site, $clearLock);
        file_put_contents($siteFile, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    foreach (ms_ai_pages_files($workingDir) as $pageFile) {
        $page = json_decode((string) @file_get_contents($pageFile), true);
        if (!is_array($page)) continue;
        ms_ai_walk_page_blocks($page, function (array &$b, int $occ) use ($clearLock) { $clearLock($b); });
        file_put_contents($pageFile, json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    return $unlocked;
}

/**
 * Clear the master's own city_spotlight from a clone's working copy before generate.py runs.
 *
 * city_spotlight is a plain site_vars field, not an ai_block, so ms_ai_unlock_working_copy()
 * above never touches it — every clone inherits the MASTER's own spotlight text verbatim
 * (e.g. water-site's master describes Lufkin). generate_site_spotlight() in generate.py treats
 * a non-empty site_vars.city_spotlight as "already generated, skip", and worse, seeds that
 * stale text into the row's OWN cities.json entry as a one-time migration shortcut meant for
 * pre-multisite single-city sites — so a clone whose row is any city other than the master's
 * own ships that OTHER city's photo next to the master's city's paragraph, permanently baking
 * the wrong text into its own cities.json row. Safe to always clear: if the row's city really
 * is the master's own, generate.py finds that city's own cached spotlight in cities.json and
 * skips regenerating anyway — no extra API cost either way.
 */
function ms_clear_inherited_city_spotlight(string $workingDir): bool {
    $siteFile = $workingDir . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (!is_array($site) || empty($site['site_vars']['city_spotlight'])) return false;
    unset($site['site_vars']['city_spotlight']);
    file_put_contents($siteFile, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return true;
}

/**
 * THE single required step for anything the master authored that a clone must never inherit
 * verbatim — one call site in build_one.php, one place in the run tree, one place in this file
 * that any future "generate once per site, skip if already present" feature MUST register
 * itself into. Before this existed, ai-lock and city_spotlight were two separate, easy-to-forget
 * calls discovered one at a time by a bug reaching a live page — see the two functions above for
 * why each one is wrong to skip. Cheap to run unconditionally: every check inside is a per-city
 * cache read that costs nothing extra when the row's city genuinely is the master's own.
 *
 * Add a new field to this list ONLY WHEN generate.py (or any future step) treats a site_vars/
 * ai_block value as "already done" based on mere presence rather than validating it against the
 * row's own city — that is the exact shape of bug this function exists to close off at the door.
 *
 * @return array{unlocked:int, spotlight_cleared:bool}
 */
function ms_scrub_master_content(string $workingDir): array {
    return [
        'unlocked'          => ms_ai_unlock_working_copy($workingDir),
        'spotlight_cleared' => ms_clear_inherited_city_spotlight($workingDir),
    ];
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
        foreach (($entries[$id]['value'] ?? []) as $k => $v) {
            if (in_array($k, MS_AI_IMAGE_KEYS, true)) continue;   // pre-existing caches carry these
            $b[$k] = $v;
        }
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
            foreach (($entries[$key]['value'] ?? []) as $k => $v) {
                if (in_array($k, MS_AI_IMAGE_KEYS, true)) continue;   // pre-existing caches carry these
                $b[$k] = $v;
            }
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
