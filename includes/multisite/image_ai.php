<?php
/**
 * AI photo generation at batch build time — the automated counterpart to Pic Drop's
 * manual "generate, review, use" flow.
 *
 * The moment an AI photo is confirmed in Pic Drop (picdrop_api.php), the prompt that
 * produced it is captured here as that slot's standing template for the master
 * (multisite/image_prompts.json). A batch run with the relevant images.ai_photos_*
 * checkbox on then EDITS each domain's own copy of that approved photo (not a
 * from-scratch description of it) with one small, domain-deterministic change —
 * staying close to what was actually approved instead of drifting wherever a fresh
 * description happens to land — generates once, and caches the result — same "never
 * re-bill a rebuild" rule as every other per-domain AI step in this pipeline.
 *
 * SCOPE: home-page, site-wide (services_links), and landing/service-page slots.
 * Landing slots are matched by TEMPLATE ID (recorded on capture, read back off the
 * master's own generated page file), never by the master's page FILENAME — a clone's
 * own page file is named after ITS OWN city, not the master's, so filename matching
 * would never hit on any real domain. A domain that doesn't build a given page at all
 * (Page Pool excluded it) is skipped silently — that's an expected, routine outcome,
 * not a failure. Block order can still shift under structure.landing's rotation, so
 * resolution goes by stable block id first, same as the homepage (see
 * ms_image_ai_resolve_block()).
 */

require_once __DIR__ . '/../openai_images.php';
require_once __DIR__ . '/../picdrop.php';   // picdrop_parse_key()
require_once __DIR__ . '/../media_lib.php'; // img_fit_to()
require_once __DIR__ . '/../layout_variations.php'; // ms_variant()
require_once __DIR__ . '/image_overlay.php'; // ms_slug_city()

function ms_image_ai_prompts_file(string $masterDir): string {
    return $masterDir . '/multisite/image_prompts.json';
}

function ms_image_ai_prompts_load(string $masterDir): array {
    $f = ms_image_ai_prompts_file($masterDir);
    if (!file_exists($f)) return [];
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

/**
 * Classifies a captured slot into one of the batch panel's four independent "AI photo"
 * checkboxes: hero_home, hero_landing, other_home, other_landing. Shared by
 * ms_generate_ai_images_for_domain() (to gate + pick the "vary composition" vs "stay
 * close" instruction) and ms_batch_pending_summary() (includes/multisite/steps.php,
 * the pre-flight cost preview) — one classification, so the checkboxes and the
 * numbers next to them can never disagree about what a run will actually do.
 *
 * A global (services_links) slot has no page of its own to be "landing" about, so it
 * always buckets with home — same reasoning "hero" only ever means the single home
 * hero, never the services block, whatever its block type.
 */
function ms_image_ai_slot_category(array $parts, string $blockType): string {
    $isHero = in_array($blockType, ['hero', 'hero_split', 'hero_grid'], true);
    if ($parts['scope'] === 'landing') return $isHero ? 'hero_landing' : 'other_landing';
    return $isHero ? 'hero_home' : 'other_home';
}

/**
 * Reads back the TEMPLATE id a master's own generated landing page file carries
 * (data/pages/{filename} — same relative path a domain's clone uses, see module
 * docblock). This, not the filename itself, is what a landing-scope slot is captured
 * and later matched against: a clone's page file is named after ITS OWN city, so only
 * the page's internal `template_id` field is stable across every domain that builds it.
 */
function ms_image_ai_landing_template_id(string $masterDir, string $pageFilename): string {
    $f = $masterDir . '/data/pages/' . basename($pageFilename);
    if (!is_file($f)) return '';
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? (string) ($d['template_id'] ?? '') : '';
}

/**
 * Called from Pic Drop the instant an AI photo is confirmed for a home/global/landing
 * slot — records the prompt that produced it as this slot's standing template for
 * every future domain build. Core/template scopes are silently ignored here on
 * purpose: they are out of batch scope, so there is nothing to remember yet.
 *
 * Stores $blockType alongside the prompt — see ms_generate_ai_images_for_domain()'s
 * docblock for why a bare block INDEX turned out not to be safe even on the homepage.
 * Also stores $blockId when the captured block has one (ensure_block_ids(), already
 * assigned to homepage blocks on every normal admin content save) — this is what lets
 * ms_image_ai_resolve_block() tell two same-type/same-field blocks apart, which type+
 * field alone cannot. Empty when the block predates the id scheme; resolution falls
 * back to today's type+field matching for those.
 *
 * For a landing slot, also captures `template_id` (read off the master's OWN page
 * file this exact moment — see ms_image_ai_landing_template_id()) — this, not
 * $slotKey's embedded filename, is what ms_generate_ai_images_for_domain() matches
 * against each domain's own page files. A slot whose page has no readable
 * `template_id` right now (shouldn't happen for a normally-generated page) is not
 * captured — there would be nothing for a future domain build to match it against.
 */
function ms_image_ai_prompt_capture(string $masterDir, string $slotKey, string $blockType, string $prompt, string $blockId = ''): void {
    if (trim($prompt) === '') return;
    $parts = picdrop_parse_key($slotKey);
    if ($parts === null || !in_array($parts['scope'], ['home', 'global', 'landing'], true)) return;

    $entry = ['prompt' => $prompt, 'block_type' => $blockType, 'block_id' => $blockId];
    if ($parts['scope'] === 'landing') {
        $templateId = ms_image_ai_landing_template_id($masterDir, $parts['id']);
        if ($templateId === '') return;
        $entry['template_id'] = $templateId;
    }

    // Locked, read-modify-write, not a bare load+save: two near-simultaneous captures
    // (two admins, or a double-submit) racing this file could otherwise each read the
    // same starting state, and whichever writes second silently undoes the first one's
    // prune below — putting the exact duplicate this function exists to prevent back in,
    // with nothing to show for it. picdrop_pairs_save()/picdrop_json_write() already use
    // this same lock-handle-then-read pattern for the same reason.
    $f = ms_image_ai_prompts_file($masterDir);
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
    $fh = fopen($f, 'c+');
    if ($fh === false) return;
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $all = $raw !== false && trim($raw) !== '' ? (json_decode($raw, true) ?: []) : [];

    // A block that moves to a different position gets a NEW slotKey on its next capture
    // (the key bakes in the index at capture time) — without this, the OLD key for the
    // same block+field stays behind forever, since nothing else ever prunes it. Both keys
    // then resolve, via $blockId, to the SAME live block at build time, and whichever one
    // ms_generate_ai_images_for_domain() processes first silently overwrites the field
    // before the second one reads it — feeding it a stale, unrelated reference image.
    // (That function also guards against this independently, for any duplicate that gets
    // in some other way — this is the write-time half of that fix.)
    //
    // Matched on block_id AND field, never block_id alone: a repeater block (e.g.
    // tab_services) legitimately has several photo fields — ts_tabs.0.photo,
    // ts_tabs.1.photo, etc. — all sharing ONE block_id. Dropping every other entry for
    // that block_id would have deleted 3 of 4 tabs' photos the moment any one of them
    // got captured.
    //
    // Also scoped to the SAME scope+id (home vs. a specific landing template file) —
    // ensure_block_ids() assigns ids like "hero_1" from a counter local to whichever
    // array it's run on, so a home hero and an unrelated landing template's hero can
    // land on the identical id by coincidence. Without this check, confirming one would
    // silently delete the OTHER's unrelated, perfectly valid prompt.
    //
    // And prunes a BLANK-block_id entry for the same scope+id+field too, not just an
    // exact block_id match: a block captured before it ever had an id resolves today via
    // ms_image_ai_resolve_block()'s type+field fallback, which only succeeds when there is
    // exactly one block of that type on the page — so a blank-id entry that still resolves
    // successfully for this exact field can only be describing the SAME physical block
    // this capture is for, just from before it had an id. Left alone, that old entry and
    // this new id-bearing one would collide exactly like two reindexed keys do.
    if ($blockId !== '') {
        foreach ($all as $k => $e) {
            if ($k === $slotKey || !is_array($e)) continue;
            $eBlockId = (string) ($e['block_id'] ?? '');
            if ($eBlockId !== $blockId && $eBlockId !== '') continue;
            $kp = picdrop_parse_key($k);
            if ($kp !== null && $kp['scope'] === $parts['scope'] && $kp['id'] === $parts['id'] && $kp['field'] === $parts['field']) {
                unset($all[$k]);
            }
        }
    }
    $all[$slotKey] = $entry;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * A small, fixed pool, picked deterministically per domain (ms_variant, own salt) —
 * so two domains sharing a template don't read as the same photo shoot, but the same
 * domain never changes pick on a rebuild.
 */
function ms_image_ai_style_pool(): array {
    return [
        'natural morning light, soft shadows',
        'bright midday light, clean and crisp',
        'warm late-afternoon light, golden tone',
        'overcast daylight, even and neutral',
        'early-evening light, warm indoor glow',
        'cool north-facing window light',
    ];
}

/** Only the tokens virtually every site_vars carries — see module docblock: an
 *  unresolved {token} is left visible on purpose rather than guessed at, the same
 *  way the SEO gate already treats an unresolved shortcode as a real bug to catch. */
function ms_image_ai_fill_tokens(string $prompt, array $siteVars): string {
    return strtr($prompt, [
        '{business}' => (string) ($siteVars['business'] ?? ''),
        '{city}'     => (string) ($siteVars['city'] ?? ''),
        '{SS}'       => (string) ($siteVars['SS'] ?? ''),
    ]);
}

/**
 * Generates (or reuses a cached) AI photo for every home/global/landing slot the
 * master has a locked prompt for, writing each straight into the working dir's own
 * site.json (home/global) or its own data/pages/*.json file (landing).
 *
 * Cache hits and locked/missing slots are resolved immediately (cheap, no network).
 * Everything that actually needs a new photo is generated in one concurrent batch
 * via openai_images_generate_many() — a domain with 30+ approved prompts used to
 * mean 30+ requests waited on one at a time (real minutes); this runs several at
 * once instead, then applies each result to site.json as it comes back.
 *
 * @param callable|null $onProgress called (int $done, int $total) as each photo in
 *        the concurrent batch finishes — purely for progress reporting, e.g. a
 *        "generating photo 3 of 12" line in a batch run's status. $total counts
 *        only the photos that actually needed generating (cache hits aren't in it).
 * @param bool $genHeroHome whether the homepage hero (hero/hero_split/hero_grid) gets
 *        a new AI photo — the batch panel's "AI photo — hero (home)" checkbox.
 * @param bool $genHeroLanding same, for each service page's own hero.
 * @param bool $genOtherHome whether every other home-page slot (+ the site-wide
 *        services_links block) gets one — "AI photo — other images (home)".
 * @param bool $genOtherLanding same, for every other slot on service pages.
 *        All four are independent; any combination may be true.
 * @return array{generated:int,cached:int,failed:int}
 */
function ms_generate_ai_images_for_domain(
    string $workingDir, string $domain, string $masterDir, ?callable $onProgress = null,
    bool $genHeroHome = true, bool $genHeroLanding = true, bool $genOtherHome = true, bool $genOtherLanding = true,
    bool $force = false
): array {
    $out = ['generated' => 0, 'cached' => 0, 'failed' => 0, 'errors' => []];
    $templates = ms_image_ai_prompts_load($masterDir);
    if (!$templates) return $out;

    // $force means "start over from the master's own approved photo," not "redo
    // whatever's there now." Without this, a slot that drifted wrong once (the edit
    // API edits FROM the domain's current photo, not from scratch — see below) would
    // just drift again on every forced regenerate, since the reference was always the
    // domain's own prior result. Loaded once, read-only — this is never written to.
    $masterSite = null;
    $masterPageCache = [];          // master pageFile path => decoded array
    $masterTemplateToPageFile = []; // template_id => master pageFile path
    if ($force) {
        $masterSite = json_decode((string) @file_get_contents($masterDir . '/data/site.json'), true);
        if (!is_array($masterSite)) $masterSite = null;
        $masterPagesDir = $masterDir . '/data/pages';
        if (is_dir($masterPagesDir)) {
            foreach (glob($masterPagesDir . '/*.json') ?: [] as $pf) {
                $pd = json_decode((string) @file_get_contents($pf), true);
                if (!is_array($pd)) continue;
                $masterPageCache[$pf] = $pd;
                if (!empty($pd['template_id'])) $masterTemplateToPageFile[(string) $pd['template_id']] = $pf;
            }
        }
    }

    $catFlags = [
        'hero_home' => $genHeroHome, 'hero_landing' => $genHeroLanding,
        'other_home' => $genOtherHome, 'other_landing' => $genOtherLanding,
    ];

    $siteFile = $workingDir . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (!is_array($site)) return $out;
    $siteVars = $site['site_vars'] ?? [];

    // Every landing page THIS domain actually built, read once up front and cached
    // by template_id — a landing slot is matched against this, never against the
    // master's page filename (see module docblock: a clone's own page is named after
    // its own city). A template_id with no entry here means Page Pool didn't build
    // that page for this domain — routine, not a failure, handled below by a plain skip.
    $pageCache = [];          // pageFile path => decoded array, mutated in place below
    $templateToPageFile = []; // template_id => pageFile path
    $pagesDir = $workingDir . '/data/pages';
    if (is_dir($pagesDir)) {
        foreach (glob($pagesDir . '/*.json') ?: [] as $pf) {
            $pd = json_decode((string) @file_get_contents($pf), true);
            if (!is_array($pd)) continue;
            $pageCache[$pf] = $pd;
            if (!empty($pd['template_id'])) $templateToPageFile[(string) $pd['template_id']] = $pf;
        }
    }
    $pageDirty = []; // pageFile path => bool

    $cacheDir = $masterDir . '/multisite/cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $domainSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(preg_replace('#^https?://#i', '', rtrim($domain, '/'))));
    $domainSlug = trim((string) $domainSlug, '-');
    $cacheFile  = $cacheDir . '/' . $domainSlug . '.image_ai.json';
    $cache = file_exists($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];

    // Generated photos are persisted HERE, outside the working dir — build_one.php
    // deletes the working dir after every run, so a cache check against a path inside
    // it (the old bug) could never hit on a later, separate build; it would silently
    // regenerate and re-bill every single rebuild. This directory is the actual
    // cross-build memory. $cacheFile above is now just bookkeeping (prompt/timestamp).
    $persistDir = $cacheDir . '/images/' . $domainSlug;

    $mediaDir = $workingDir . '/uploads/media/';
    $dirty    = false;

    // ── Defensive dedup: collapse any slotKey that collides on the same live block ──
    // A capture event keys by {scope}::{index-at-capture-time}:{field}; if a block later
    // moves to a different index, ms_image_ai_prompt_capture() now prunes the old key —
    // but that fix is write-time only. This is the read-time backstop, for a duplicate
    // that gets into the file some other way (a manual edit, an older unmigrated file, a
    // bug this didn't anticipate). Without it, two slotKeys sharing a block_id both
    // resolve to the SAME live block+field below, and whichever is processed first
    // silently overwrites that field before the second one reads it as its own reference
    // image — confirmed as the actual cause of a wrong photo on a real domain.
    //
    // Covers landing too, not just home: a landing slot's PAGE is matched by template_id
    // (real, and not at risk), but the BLOCK inside that page still resolves by block_id
    // the identical way home does, via the same ms_image_ai_resolve_block() — and
    // ensure_block_ids() hands out ids like "hero_1" from a counter local to whichever
    // template it's run on, so landing block order can shift (structure.landing's
    // per-domain rotation) exactly like home's can. Grouped by scope+id+block_id+field —
    // "id" is the template file for landing, empty for home — so a home block and an
    // unrelated landing template's block that happen to share a generic id (or two
    // different landing templates that do) are never treated as the same slot.
    //
    // Grouped on field too, never block_id alone: a repeater block (e.g. tab_services)
    // legitimately has several photo fields — ts_tabs.0.photo, ts_tabs.1.photo, etc. —
    // all sharing ONE block_id. Grouping by block_id alone would treat a block's own
    // distinct, perfectly valid photo slots as duplicates of each other and silently
    // drop all but one of them.
    $blockIdAtIndex = ['' => []]; // scope-id ('' = home) => block_id => current index
    if (!empty($site['content_blocks']) && is_array($site['content_blocks'])) {
        foreach ($site['content_blocks'] as $i => $b) {
            if (is_array($b) && !empty($b['id'])) $blockIdAtIndex[''][(string) $b['id']] = $i;
        }
    }
    $blockIdAtIndexFor = function (string $scopeId) use (&$blockIdAtIndex, $templateToPageFile, $pageCache) {
        if (isset($blockIdAtIndex[$scopeId])) return $blockIdAtIndex[$scopeId];
        $map = [];
        $pageFile = $templateToPageFile[$scopeId] ?? null;
        $pd = $pageFile !== null ? ($pageCache[$pageFile] ?? null) : null;
        if (is_array($pd) && !empty($pd['content_blocks']) && is_array($pd['content_blocks'])) {
            foreach ($pd['content_blocks'] as $i => $b) {
                if (is_array($b) && !empty($b['id'])) $map[(string) $b['id']] = $i;
            }
        }
        return $blockIdAtIndex[$scopeId] = $map;
    };
    $byBlockField = [];
    foreach ($templates as $slotKey => $entry) {
        $blockId = is_array($entry) ? (string) ($entry['block_id'] ?? '') : '';
        if ($blockId === '') continue;
        $tp = picdrop_parse_key($slotKey);
        if ($tp === null || !in_array($tp['scope'], ['home', 'landing'], true)) continue;
        // Landing is grouped by template_id (the entry's own, authoritative — not the
        // key's $tp['id'], which is just the master's page filename at capture time and
        // can go stale the same way a block index can).
        $scopeId = $tp['scope'] === 'landing' ? (string) ($entry['template_id'] ?? '') : '';
        if ($tp['scope'] === 'landing' && $scopeId === '') continue; // no template_id to group by — skip, don't guess
        $byBlockField[$scopeId . "\0" . $blockId . "\0" . $tp['field']][] = $slotKey;
    }
    $skipSlotKeys = [];
    foreach ($byBlockField as $groupKey => $keys) {
        if (count($keys) < 2) continue;
        [$scopeId, $blockId] = explode("\0", $groupKey, 3);
        $currentIdx = $blockIdAtIndexFor($scopeId)[$blockId] ?? null;
        $keep = null;
        foreach ($keys as $k) {
            $tp = picdrop_parse_key($k);
            if ($tp !== null && $currentIdx !== null && $tp['block'] === $currentIdx) { $keep = $k; break; }
        }
        if ($keep === null) $keep = $keys[0]; // nothing matched the block's current position — keep one rather than drop all
        foreach ($keys as $k) if ($k !== $keep) $skipSlotKeys[$k] = true;
    }

    // ── Pass 1: resolve every slot, handle cache hits immediately, queue the rest ──
    // Anything that actually needs a new photo goes into $pending instead of being
    // generated right here — see Pass 2 below, which fires them all through
    // openai_images_generate_many() instead of one at a time.
    $pending = [];

    foreach ($templates as $slotKey => $entry) {
        if (isset($skipSlotKeys[$slotKey])) continue;
        // Back-compat with the very first (pre-fix) shape, a bare prompt string with no
        // block_type — can't safely resolve those by type, only by the index they were
        // captured with, which is exactly what turned out not to be safe. Treat as a
        // clean failure rather than silently guessing.
        $basePrompt = is_array($entry) ? (string) ($entry['prompt'] ?? '') : (string) $entry;
        $blockType  = is_array($entry) ? (string) ($entry['block_type'] ?? '') : '';
        $blockId    = is_array($entry) ? (string) ($entry['block_id']   ?? '') : '';

        $parts = picdrop_parse_key($slotKey);
        if ($parts === null || !in_array($parts['scope'], ['home', 'global', 'landing'], true)) continue;

        // Which of the four checkboxes governs this slot is decided BEFORE any
        // resolve/cache work below — an unchecked box means this slot is untouched,
        // not "resolve it and then discard the result".
        $category = ms_image_ai_slot_category($parts, $blockType);
        if (!$catFlags[$category]) continue;

        // A landing slot is matched by TEMPLATE ID against this domain's own already-
        // generated pages (module docblock) — never by the master's page filename,
        // which bakes in the MASTER's own city. A domain that didn't build a page for
        // this template at all (Page Pool excluded it) has nothing to do here — that's
        // routine, not a failure, so it's a plain skip with no error recorded.
        $pageFile = null;
        if ($parts['scope'] === 'landing') {
            $templateId = is_array($entry) ? (string) ($entry['template_id'] ?? '') : '';
            if ($templateId === '') {
                $out['failed']++; $out['errors'][] = "$slotKey: no template_id recorded for this landing slot (captured before this fix) — re-confirm the AI photo in Pic Drop once to re-capture it"; continue;
            }
            $pageFile = $templateToPageFile[$templateId] ?? null;
            if ($pageFile === null) continue;
        }
        // The array a block gets resolved/written against — this domain's site.json
        // for home/global, or its own page file for landing. MUST be a real reference
        // (=&, not a value copy) — a later write through $block only reaches $pageCache
        // if $target is the SAME array, not a snapshot of it taken before the write.
        if ($pageFile !== null) { $target = &$pageCache[$pageFile]; } else { $target = &$site; }

        // Resolve the block + field this key points at, directly in this domain's own
        // copy of that page. NOT by the block INDEX the key carries — section-order
        // rotation (structure.home / structure.landing) can and does reorder blocks per
        // domain, so "index 2" does not reliably mean the same block it meant on the
        // master. Found live: 2 of 5 real test domains had a different block at that
        // index, both failing with "field not present" until this was fixed to match by
        // TYPE + field name instead — the same field name won't collide across an
        // unrelated block type, and this is the same field-name-is-block-specific
        // assumption picdrop_fields() already relies on. Resolved BEFORE the cache check
        // below — even a cache hit must still write the cached photo into THIS build's
        // copy, since a fresh clone's block field holds the master's placeholder, not
        // last time's generated photo, until that write happens.
        if ($parts['scope'] !== 'global' && $blockType === '') {
            $out['failed']++; $out['errors'][] = "$slotKey: no block_type recorded for this template (captured before this fix) — re-confirm the AI photo in Pic Drop once to re-capture it"; continue;
        }
        $ambiguous = false;
        $block = &ms_image_ai_resolve_block($target, $parts, $blockType, $blockId, $ambiguous);
        if ($block === null) {
            $where = $parts['scope'] === 'landing' ? "this domain's copy of that page" : "this domain's homepage";
            if ($ambiguous) {
                $msg = "more than one '$blockType' block with field '{$parts['field']}' found on $where "
                     . "and this slot has no recorded id to disambiguate — re-confirm the AI photo in "
                     . "Pic Drop once to re-capture it with a stable id";
            } else {
                $msg = $parts['scope'] === 'global'
                    ? "no services_links block in this domain's site.json"
                    : "no '$blockType' block with field '{$parts['field']}' found on $where";
            }
            $out['failed']++; $out['errors'][] = "$slotKey: $msg"; continue;
        }

        // picdrop_get()/picdrop_set() (includes/picdrop.php), not plain array access —
        // $field can be a dotted path into a repeater row (e.g. "ts_tabs.0.photo"), which
        // a bare $block[$field] treats as one literal (nonexistent) key instead of
        // descending into it. Every tab_services slot silently failed with "field not
        // present" until this was fixed — found while backfilling block ids for a
        // different bug, confirmed the failure predates this change entirely.
        $field = $parts['field'];
        if (picdrop_get($block, $field) === null) {
            $out['failed']++; $out['errors'][] = "$slotKey: field '$field' not present on that block"; continue;
        }

        // The CACHE filename is keyed by slot + the MASTER TEMPLATE prompt (not the
        // per-domain filled-in one, which always differs by city/business) — same
        // convention the AI text cache already uses. A domain never changes this on
        // rebuild, but editing the prompt in Pic Drop changes the hash, so it
        // regenerates once rather than silently reusing a photo made from a prompt
        // Scott has since replaced. The 'edit-v1' tag does the same job for the
        // METHOD, not just the prompt — bumps every previously-cached photo (made by
        // describing a scene from scratch, which is what was drifting "way off") so
        // it regenerates once under the new edit-from-reference approach instead of
        // being served forever from a cache keyed the old way. Purely an internal
        // lookup key — never seen by a visitor or a search engine.
        $cacheFilename = 'ai_' . substr(md5($slotKey . '|' . $basePrompt . '|edit-v1'), 0, 10) . '.webp';
        $persistFile   = $persistDir . '/' . $cacheFilename;

        // The PUBLIC filename is separate and carries real local-SEO signal — same
        // city-based convention the other two image-differentiation schemes already
        // use (ms_slug_city(), includes/multisite/image_overlay.php) — instead of an
        // opaque hash, which had none at all. The stem comes from the slot's own alt
        // text when one exists (already a real description, e.g. "Water damage
        // restoration technician"), falling back to the block type; {city}/{SS}-style
        // page shortcodes mean nothing in a filename and are stripped first. A short
        // hash stays appended — unlike a hand-named upload, two AI slots can easily
        // share near-identical alt text, and this is the only thing guaranteeing the
        // filename stays unique on this domain.
        $altKeyName = picdrop_fields()[picdrop_leaf($field)]['alt'] ?? null;
        $altPath    = $altKeyName !== null ? picdrop_alt_path($field, $altKeyName) : null;
        $altText    = $altPath !== null ? (string) picdrop_get($block, $altPath) : '';
        $stemSource = trim((string) preg_replace('/\s*\{[a-z_]+\}/i', '', $altText));
        $stem = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($stemSource)), '-');
        // Falls back to the block type both when there was no alt text at all AND when
        // stripping shortcodes left only punctuation (e.g. alt text of just
        // "{city}, {SS}" leaves a lone comma — not empty, but not a usable stem either).
        if ($stem === '') {
            $stem = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($blockType)), '-');
        }
        if ($stem === '') $stem = 'photo';

        // A landing page carries its OWN city_vars (a domain can build service pages
        // targeting nearby towns, not only its home city — see module docblock), so its
        // photo's local-SEO filename stamp and its {city}/{SS} prompt tokens both use
        // the PAGE's own city, falling back to the domain's site_vars for {business}
        // (a page has no business name of its own) and for home/global slots outright.
        $tokenVars = $siteVars;
        if ($pageFile !== null && is_array($target['city_vars'] ?? null)) {
            $tokenVars = array_merge($siteVars, $target['city_vars']);
        }
        $slugCitySlug   = ms_slug_city((string) ($tokenVars['city'] ?? ''), (string) ($tokenVars['SS'] ?? ''));
        $shortHash      = substr(md5($slotKey), 0, 6);
        $publicFilename = $stem . ($slugCitySlug !== '' ? '-' . $slugCitySlug : '') . '-' . $shortHash . '.webp';
        $url            = 'uploads/media/' . $publicFilename;

        // Checked BEFORE the cache-hit branch too, not just the needs-generation path
        // below — a field currently holding a locked {token} placeholder is not ours to
        // overwrite, whether or not a matching cache entry happens to exist for this slot.
        $currentValue = (string) picdrop_get($block, $field);
        if (str_contains($currentValue, '{')) {
            $out['failed']++; $out['errors'][] = "$slotKey: current value is a locked {token}, not ours to touch"; continue;
        }

        if (!$force && is_file($persistFile)) {
            if (!is_dir($mediaDir)) mkdir($mediaDir, 0775, true);
            if (!copy($persistFile, $mediaDir . $publicFilename)) {
                $out['failed']++; $out['errors'][] = "$slotKey: cached photo exists but could not be copied into this build"; continue;
            }
            // Self-healing rather than persisting a second cached file: cheap to redo (a
            // local GD resize, no OpenAI call) and this way a cache built before the
            // mobile-variant hook existed still gets one on next use, no backfill needed.
            img_write_mobile_variant($mediaDir . $publicFilename);
            if (!picdrop_set($block, $field, $url)) {
                $out['failed']++; $out['errors'][] = "$slotKey: cached photo exists but the field path could not be written"; continue;
            }
            $cache[$slotKey] = ($cache[$slotKey] ?? []) + ['url' => $url];
            if ($pageFile !== null) { $pageDirty[$pageFile] = true; } else { $dirty = true; }
            $out['cached']++;
            continue;
        }

        // Force: reference the MASTER's own approved photo, not this domain's current
        // one. The edit API edits FROM its reference, so re-editing an already-wrong
        // photo only ever produces another variation of the same wrong scene — going
        // back to the master's photo every time is what actually breaks that loop.
        $refBase = $workingDir;
        $refValue = $currentValue;
        if ($force && $masterSite !== null) {
            $masterTarget = $masterSite;
            if ($parts['scope'] === 'landing') {
                $templateId = is_array($entry) ? (string) ($entry['template_id'] ?? '') : '';
                $masterPageFile = $templateId !== '' ? ($masterTemplateToPageFile[$templateId] ?? null) : null;
                $masterTarget = $masterPageFile !== null ? $masterPageCache[$masterPageFile] : null;
            }
            $masterAmbiguous = false;
            $masterBlock = $masterTarget !== null ? ms_image_ai_resolve_block($masterTarget, $parts, $blockType, $blockId, $masterAmbiguous) : null;
            $masterValue = $masterBlock !== null ? (string) picdrop_get($masterBlock, $field) : '';
            if ($masterValue !== '' && !str_contains($masterValue, '{')) {
                $refBase = $masterDir;
                $refValue = $masterValue;
            }
            // Falls back to the domain's current photo (the pre-force behavior) when the
            // master's own slot can't be resolved — e.g. a landing template Page Pool
            // never built for this domain's clone. Not a failure: still generates, just
            // without the reset-to-master guarantee for this one slot.
        }
        $refPath = $refBase . '/' . ltrim($refValue, '/');
        [$tw, $th] = @getimagesize($refPath) ?: [0, 0];
        if ($tw < 1 || $th < 1) {
            $out['failed']++; $out['errors'][] = "$slotKey: could not read size of existing photo ($refValue)"; continue;
        }

        // Edited FROM the domain's current photo — which is a straight (differentiated)
        // copy of the exact picture approved in Pic Drop — rather than described from
        // scratch. A from-scratch description let every domain's result drift wherever
        // the model felt like going that day; editing keeps the result close to what was
        // actually approved, with one small, deliberate, per-domain-deterministic change
        // so it isn't a pixel duplicate. Still enough to matter for the same reason every
        // OTHER photo in this pipeline gets its own differentiated copy — see
        // picdrop_matching_slots()'s docblock.
        // The approved prompt still rides along (so a meaningful edit to it in Pic
        // Drop — a different subject entirely, say — still has a say), but the
        // instruction now leads with "stay close to the reference," not "here's a
        // scene, go describe it" — that framing is what let results wander.
        //
        // A hero slot — home OR landing — is the one place "stay close" cuts the other
        // way: it's the highest-visibility photo on its page, so a tight "same
        // composition, same framing" instruction — while it defeats hash/dedup
        // checks by producing a genuinely separate generation — still reads as the
        // same photo under reverse image search, which compares visual structure,
        // not bytes. Every non-hero slot keeps the tight instruction; only a hero is
        // allowed to actually vary composition/framing/angle.
        $isHero = $category === 'hero_home' || $category === 'hero_landing';
        $styleIdx = ms_variant($domain, count(ms_image_ai_style_pool()), 'image_style');
        // "Leave margin" tells the model up front that this result gets cropped to
        // fit a fixed slot afterward — the API only accepts 3 fixed output ratios,
        // so a reference's real shape rarely survives untouched, and a subject or
        // piece of equipment composed edge-to-edge is exactly what a later crop
        // cuts into. Cheaper than trying to computationally prevent the mismatch.
        $instruction = $isHero
            ? '. Keep the same subject and general setting as the reference, but vary the framing, '
              . 'angle, or composition — it should read as a distinct photograph, not a copy of the '
              . 'reference. This photo will be cropped to fit its slot afterward, so leave margin '
              . 'around the subject and any equipment — do not compose it edge-to-edge. Also reflect '
              . 'this stylistic touch: '
            : '. Keep this photo nearly identical to the reference — same subject, same composition, '
              . 'same framing. This photo will be cropped to fit its slot afterward, so leave margin '
              . 'around the subject and any equipment — do not compose it edge-to-edge. Make only one '
              . 'small, subtle change: ';
        $prompt = ms_image_ai_fill_tokens($basePrompt, $tokenVars) . $instruction
                . ms_image_ai_style_pool()[$styleIdx] . '.';

        $size = ($tw > 0 && $th > 0)
            ? ($tw >= $th * 1.2 ? '1536x1024' : ($th >= $tw * 1.2 ? '1024x1536' : '1024x1024'))
            : '1024x1024';

        // Not generated here — queued. Pass 2 below fires every queued slot through
        // openai_images_edit_many() together, instead of waiting on each one in
        // turn (see that function's docblock for why this used to be slow: a domain
        // with 30+ approved prompts meant 30+ full request/response waits back to
        // back). $block is intentionally NOT captured here — content_blocks is a
        // plain array, so a reference into it can go stale if anything else in this
        // array touches the array between now and Pass 2; re-resolving by type+field
        // there is cheap and exactly what the single-request path already did.
        $pending[] = [
            'slotKey' => $slotKey, 'parts' => $parts, 'blockType' => $blockType, 'blockId' => $blockId, 'field' => $field,
            'filename' => $publicFilename, 'persistFile' => $persistFile, 'url' => $url, 'refPath' => $refPath,
            'prompt' => $prompt, 'tw' => $tw, 'th' => $th, 'size' => $size, 'pageFile' => $pageFile,
        ];
    }
    unset($block);

    // ── Pass 2: generate every queued slot concurrently, apply each as it lands ──
    if ($pending) {
        $jobs = array_map(
            fn($p) => ['prompt' => $p['prompt'], 'ref_path' => $p['refPath'], 'opts' => ['size' => $p['size'], 'quality' => 'medium', 'output_format' => 'webp']],
            $pending
        );

        $total = count($jobs);
        $done  = 0;
        $results = openai_images_edit_many($jobs, ms_image_ai_concurrency(), function ($i, $r) use (&$done, $total, $onProgress) {
            $done++;
            if ($onProgress) $onProgress($done, $total);
        });

        foreach ($pending as $i => $p) {
            $slotKey = $p['slotKey'];
            $r = $results[$i];
            if (!$r['ok']) {
                $out['failed']++; $out['errors'][] = "$slotKey: openai_images_generate failed (HTTP {$r['code']}): {$r['error']}"; continue;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'msai');
            file_put_contents($tmpFile, $r['bytes']);
            if (!is_dir($mediaDir)) mkdir($mediaDir, 0775, true);
            $dest = $mediaDir . $p['filename'];
            [$fitOk] = img_fit_to($tmpFile, $dest, 'image/webp', $p['tw'], $p['th']);
            @unlink($tmpFile);
            if (!$fitOk) {
                $out['failed']++; $out['errors'][] = "$slotKey: img_fit_to() could not process the generated image"; continue;
            }

            // Persist OUTSIDE the working dir so the next, separate build of this same
            // domain hits cache instead of re-billing OpenAI — see the note by $persistDir.
            if (!is_dir($persistDir)) mkdir($persistDir, 0775, true);
            copy($dest, $p['persistFile']);

            // Same target-array rule as Pass 1 (real reference, not a value copy) —
            // re-resolved fresh rather than reusing Pass 1's $block, which content_blocks
            // being a plain array can invalidate between the two passes (see the comment
            // above where $pending is built).
            $pf = $p['pageFile'];
            if ($pf !== null) { $target = &$pageCache[$pf]; } else { $target = &$site; }
            $block = &ms_image_ai_resolve_block($target, $p['parts'], $p['blockType'], $p['blockId'] ?? '');
            if ($block === null) {
                // Vanishingly unlikely (Pass 1 just resolved this same slot) — but the
                // photo is already made and cached, so fail loudly rather than lose it.
                $out['failed']++; $out['errors'][] = "$slotKey: generated but the block moved before it could be written — file is cached at {$p['persistFile']} for the next attempt"; continue;
            }
            if (!picdrop_set($block, $p['field'], $p['url'])) {
                $out['failed']++; $out['errors'][] = "$slotKey: generated but the field path could not be written — file is cached at {$p['persistFile']} for the next attempt"; continue;
            }
            unset($block);

            $cache[$slotKey] = ['url' => $p['url'], 'prompt' => $p['prompt'], 'generated_at' => date('Y-m-d H:i:s')];
            if ($pf !== null) { $pageDirty[$pf] = true; } else { $dirty = true; }
            $out['generated']++;
        }
    }

    if ($dirty) {
        file_put_contents($siteFile, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    foreach ($pageDirty as $pf => $isDirty) {
        if ($isDirty) file_put_contents($pf, json_encode($pageCache[$pf], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if ($dirty || $pageDirty || $out['failed'] > 0) {
        file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return $out;
}

/**
 * How many AI photo requests may be in flight at once for one domain's batch. A
 * flat constant, not a per-batch setting — tuned once here if OpenAI's rate limit
 * ever pushes back, rather than exposed as one more checkbox nobody will tune.
 */
function ms_image_ai_concurrency(): int {
    return 5;
}

/**
 * Resolves a slot's block BY REFERENCE. Tries $blockId first — stable across
 * structure.home's per-domain rotation (see ensure_block_ids(),
 * includes/layout_variations.php: rotation moves whole blocks via array_slice/
 * array_merge, it never rebuilds one, so an id set on a block rides along
 * unchanged) — falling back to matching by TYPE + field for slots captured before
 * the id scheme existed. The type+field fallback refuses to guess when more than
 * one block matches (sets $ambiguous = true, returns null) rather than silently
 * picking the first one: that silent pick was the actual bug an id lookup exists
 * to avoid, so the fallback exists only to cover old data, not as a second way to
 * accept ambiguity. Returns a null-valued reference when nothing matches (PHP has
 * no nullable reference return otherwise). Used from both passes above: once to
 * decide what needs generating, once again to write each result back.
 */
function &ms_image_ai_resolve_block(array &$site, array $parts, string $blockType, string $blockId = '', bool &$ambiguous = false) {
    $null = null;
    $ambiguous = false;
    if ($parts['scope'] === 'global') {
        if (!isset($site['services_links']) || !is_array($site['services_links'])) return $null;
        return $site['services_links'];
    }
    if ($blockType === '' || !isset($site['content_blocks']) || !is_array($site['content_blocks'])) return $null;
    // Reference must chain through a bare variable — indexing an expression like
    // `$site['content_blocks'] ?? []` silently breaks the reference back to $site, so a
    // later `$block[$field] = ...` writes into a throwaway copy and never reaches $site
    // at all. Confirmed with a standalone repro before trusting this: the `?? []` form
    // saved a cache entry claiming success while site.json quietly kept the old photo.
    $blocks = &$site['content_blocks'];

    // picdrop_get() not array_key_exists() — $parts['field'] can be a dotted path into a
    // repeater row (e.g. "ts_tabs.0.photo"), which array_key_exists() only ever checks as
    // one literal (nonexistent) top-level key. Every tab_services slot failed "field not
    // present" until this was fixed, regardless of id/type matching.
    if ($blockId !== '') {
        foreach ($blocks as $i => $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? '') === $blockId && picdrop_get($candidate, $parts['field']) !== null) {
                return $blocks[$i];
            }
        }
        // A recorded id that no longer matches anything (e.g. the block was rebuilt
        // without ids surviving) falls through to the fallback below, same
        // graceful-degradation the no-id case already gets.
    }

    $matchIndex = null;
    $matchCount = 0;
    foreach ($blocks as $i => $candidate) {
        if (is_array($candidate) && ($candidate['type'] ?? '') === $blockType && picdrop_get($candidate, $parts['field']) !== null) {
            $matchCount++;
            if ($matchIndex === null) $matchIndex = $i;
        }
    }
    if ($matchCount > 1) { $ambiguous = true; return $null; }
    if ($matchCount === 1) return $blocks[$matchIndex];
    return $null;
}
