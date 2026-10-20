<?php
// Block operations — add/remove a content block across every layer that can hold a
// copy of it, in one call.
//
// WHY THIS EXISTS: a block-level change has to be applied to THREE independent layers
// or it only half-lands (see the three-layer lesson that cost a session):
//   1. the master's site.json        — the homepage's own content_blocks
//   2. the master's templates.json   — every service-page template, a separate copy
//   3. data/pages/*.json             — already-generated pages, frozen snapshots taken
//                                      from the template at generation time; no code or
//                                      master-data fix ever reaches these
// Plus a FOURTH thing that is easy to miss: `layout_variants` reference blocks by ID
// STRING (e.g. "testimonials_1"), so removing a block without stripping its id from
// every variant leaves the variant pointing at a block that no longer exists.
//
// Everything here goes through the subsystem's own write primitives — load_data()/
// save_data() for site.json, tpl_load()/tpl_save() for templates.json — rather than
// hand-rolled json_encode, so a file written here is identical to one the panel wrote.
//
// The pure helpers at the top take and return arrays and touch no files, so they can be
// unit-exercised or reused by a future operation (inserting a block, reordering) without
// dragging the file layer along.

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/templates_store.php';

/**
 * Remove every block of $type from a content_blocks list.
 *
 * @param array $blocks     content_blocks as stored
 * @param string $type      block `type` to drop
 * @param array $removedIds OUT — the `id` of each block removed, for variant cleanup
 * @return array the list without those blocks, re-indexed
 */
function block_ops_remove_type(array $blocks, string $type, array &$removedIds = []): array {
    $kept = [];
    foreach ($blocks as $b) {
        if (is_array($b) && ($b['type'] ?? '') === $type) {
            if (!empty($b['id'])) $removedIds[] = $b['id'];
            continue;
        }
        $kept[] = $b;
    }
    // array_values via $kept[] — a gap in the keys would re-encode as a JSON object and
    // silently break every consumer that expects a list.
    return $kept;
}

/**
 * Strip removed block ids out of a template's layout_variants.
 * Each variant is a flat list of block-id strings; a variant that ends up empty is
 * dropped rather than left as an empty ordering.
 */
function block_ops_clean_variants(array $variants, array $removedIds): array {
    if (!$removedIds) return $variants;
    $drop = array_flip($removedIds);
    $out  = [];
    foreach ($variants as $variant) {
        if (!is_array($variant)) { $out[] = $variant; continue; }
        $cleaned = [];
        foreach ($variant as $bid) {
            if (isset($drop[(string) $bid])) continue;
            $cleaned[] = $bid;
        }
        if ($cleaned) $out[] = $cleaned;
    }
    return $out;
}

/**
 * Move every block of $type to the END of a content_blocks list, preserving the
 * relative order of everything else. Already-last is a no-op, so this is idempotent.
 */
function block_ops_move_type_to_end(array $blocks, string $type, array &$movedIds = []): array {
    $rest = []; $moved = [];
    foreach ($blocks as $b) {
        if (is_array($b) && ($b['type'] ?? '') === $type) {
            $moved[] = $b;
            if (!empty($b['id'])) $movedIds[] = $b['id'];
        } else {
            $rest[] = $b;
        }
    }
    return array_merge($rest, $moved);
}

/**
 * Move the given ids to the end of each layout variant, keeping other ids in order.
 *
 * Block-order rotation is currently OFF fleet-wide (`"layout":{"enabled":false}` in the
 * build output), so variants are inert today — but they are stored per template and
 * layout_apply() would honour them the moment rotation is switched back on. Leaving a
 * stale variant behind would silently undo this reorder at that point, so the variants
 * are kept in step rather than ignored.
 */
function block_ops_move_ids_to_end_in_variants(array $variants, array $ids): array {
    if (!$ids) return $variants;
    $move = array_flip($ids);
    $out  = [];
    foreach ($variants as $variant) {
        if (!is_array($variant)) { $out[] = $variant; continue; }
        $keep = []; $tail = [];
        foreach ($variant as $bid) {
            if (isset($move[(string) $bid])) $tail[] = $bid; else $keep[] = $bid;
        }
        $out[] = array_merge($keep, $tail);
    }
    return $out;
}

/** Does this block answer to $key — matching either its `type` or its `ai_type_id`? */
function block_ops_matches(array $b, string $key): bool {
    return ($b['type'] ?? '') === $key || ($b['ai_type_id'] ?? '') === $key;
}

/**
 * Move the first block matching $moveKey to sit immediately after the first block
 * matching $anchorKey. Either key may name a block `type` or an `ai_type_id`, because
 * the interesting anchors are AI blocks that all share type `ai_block`.
 *
 * Leaves the list alone when either block is absent, or when it is already in place,
 * so it is idempotent and safe to run across templates that don't have both.
 */
function block_ops_move_after(array $blocks, string $moveKey, string $anchorKey, array &$movedIds = []): array {
    $from = $to = null;
    foreach ($blocks as $i => $b) {
        if (!is_array($b)) continue;
        if ($from === null && block_ops_matches($b, $moveKey))   $from = $i;
        if ($to   === null && block_ops_matches($b, $anchorKey)) $to   = $i;
    }
    if ($from === null || $to === null || $from === $to) return $blocks;
    if ($from === $to + 1) return $blocks;   // already directly after the anchor

    $moved = $blocks[$from];
    array_splice($blocks, $from, 1);
    // Re-find the anchor: removing an earlier element shifts it left by one.
    $anchor = $from < $to ? $to - 1 : $to;
    array_splice($blocks, $anchor + 1, 0, [$moved]);
    if (!empty($moved['id'])) $movedIds[] = $moved['id'];
    return $blocks;
}

/**
 * Move a block type to sit after another, across all three layers of the ACTIVE site.
 *
 * @return array{site:int,templates:int,pages:int,errors:string[]}
 */
function block_ops_move_after_everywhere(string $moveKey, string $anchorKey, bool $dryRun = false): array {
    $report = ['site' => 0, 'templates' => 0, 'pages' => 0, 'errors' => []];

    $apply = function (array $node) use ($moveKey, $anchorKey) {
        $ids = [];
        $fresh = block_ops_move_after($node['content_blocks'], $moveKey, $anchorKey, $ids);
        $changed = ($fresh !== $node['content_blocks']);
        $node['content_blocks'] = $fresh;
        return [$node, $changed];
    };

    $data = load_data();
    if (is_array($data) && !empty($data['content_blocks'])) {
        [$data, $changed] = $apply($data);
        if ($changed) {
            $report['site'] = 1;
            if (!$dryRun && !save_data($data)) $report['errors'][] = 'site.json write failed';
        }
    }

    $templates = tpl_load(); $dirty = false;
    foreach ($templates as $i => $tpl) {
        if (empty($tpl['content_blocks'])) continue;
        [$templates[$i], $changed] = $apply($tpl);
        if ($changed) { $report['templates']++; $dirty = true; }
    }
    if ($dirty && !$dryRun && !tpl_save($templates)) $report['errors'][] = 'templates.json write failed';

    if (defined('PAGES_DIR') && is_dir(PAGES_DIR)) {
        foreach (glob(rtrim(PAGES_DIR, '/') . '/*.json') ?: [] as $file) {
            $page = json_decode((string) @file_get_contents($file), true);
            if (!is_array($page) || empty($page['content_blocks'])) continue;
            [$page, $changed] = $apply($page);
            if (!$changed) continue;
            $report['pages']++;
            if ($dryRun) continue;
            $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tmp  = $file . '.tmp.' . getmypid();
            if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
                $report['errors'][] = 'page write failed: ' . basename($file);
                @unlink($tmp);
            }
        }
    }
    return $report;
}

/**
 * Lift a block up past any unbroken run of "filler" blocks sitting directly in front
 * of it, so it lands immediately after the last substantive block.
 *
 * Written for the FAQ, which is by a wide margin the largest block of page-unique text
 * (measured at ~2,526 chars/page against ~1,400 for the next) but sat behind a CTA
 * banner and a links box. Expressed as "lift above the filler immediately preceding it"
 * rather than a fixed index because the stacks differ by role — hubs have two filler
 * blocks in front of the FAQ, leaves and standalone pages have one — and a fixed
 * position would be wrong for at least one of them.
 *
 * No-op when the block is already at the front of its run, so it is idempotent.
 *
 * @param string[] $filler block types treated as liftable-over
 */
function block_ops_lift_above_filler(array $blocks, string $type, array $filler, array &$movedIds = []): array {
    $idx = null;
    foreach ($blocks as $i => $b) {
        if (is_array($b) && ($b['type'] ?? '') === $type) { $idx = $i; break; }
    }
    if ($idx === null || $idx === 0) return $blocks;

    $target = $idx;
    while ($target > 0 && in_array(($blocks[$target - 1]['type'] ?? ''), $filler, true)) $target--;
    if ($target === $idx) return $blocks;

    if (!empty($blocks[$idx]['id'])) $movedIds[] = $blocks[$idx]['id'];
    $moved = $blocks[$idx];
    array_splice($blocks, $idx, 1);
    array_splice($blocks, $target, 0, [$moved]);
    return $blocks;
}

/** The same lift applied inside each layout variant, which orders blocks by ID string. */
function block_ops_lift_ids_in_variants(array $variants, array $ids, array $fillerIdPrefixes): array {
    if (!$ids) return $variants;
    $out = [];
    foreach ($variants as $variant) {
        if (!is_array($variant)) { $out[] = $variant; continue; }
        foreach ($ids as $id) {
            $pos = array_search($id, $variant, true);
            if ($pos === false || $pos === 0) continue;
            $t = $pos;
            while ($t > 0) {
                $prev = (string) $variant[$t - 1];
                $isFiller = false;
                foreach ($fillerIdPrefixes as $p) if (str_starts_with($prev, $p)) { $isFiller = true; break; }
                if (!$isFiller) break;
                $t--;
            }
            if ($t === $pos) continue;
            array_splice($variant, $pos, 1);
            array_splice($variant, $t, 0, [$id]);
        }
        $out[] = $variant;
    }
    return $out;
}

/**
 * Lift a block type above its preceding filler across all three layers of the ACTIVE site.
 *
 * @return array{site:int,templates:int,pages:int,variants:int,errors:string[]}
 */
function block_ops_lift_above_filler_everywhere(string $type, array $filler, bool $dryRun = false): array {
    $report = ['site' => 0, 'templates' => 0, 'pages' => 0, 'variants' => 0, 'errors' => []];
    $prefixes = array_map(fn($f) => $f . '_', $filler);

    $apply = function (array $node) use ($type, $filler, $prefixes, &$report) {
        $ids = [];
        $fresh = block_ops_lift_above_filler($node['content_blocks'], $type, $filler, $ids);
        $changed = ($fresh !== $node['content_blocks']);
        $node['content_blocks'] = $fresh;
        if (!empty($node['layout_variants'])) {
            $before = $node['layout_variants'];
            $node['layout_variants'] = block_ops_lift_ids_in_variants($before, $ids, $prefixes);
            if ($node['layout_variants'] !== $before) { $changed = true; $report['variants']++; }
        }
        return [$node, $changed];
    };

    $data = load_data();
    if (is_array($data) && !empty($data['content_blocks'])) {
        [$data, $changed] = $apply($data);
        if ($changed) {
            $report['site'] = 1;
            if (!$dryRun && !save_data($data)) $report['errors'][] = 'site.json write failed';
        }
    }

    $templates = tpl_load(); $dirty = false;
    foreach ($templates as $i => $tpl) {
        if (empty($tpl['content_blocks'])) continue;
        [$templates[$i], $changed] = $apply($tpl);
        if ($changed) { $report['templates']++; $dirty = true; }
    }
    if ($dirty && !$dryRun && !tpl_save($templates)) $report['errors'][] = 'templates.json write failed';

    if (defined('PAGES_DIR') && is_dir(PAGES_DIR)) {
        foreach (glob(rtrim(PAGES_DIR, '/') . '/*.json') ?: [] as $file) {
            $page = json_decode((string) @file_get_contents($file), true);
            if (!is_array($page) || empty($page['content_blocks'])) continue;
            [$page, $changed] = $apply($page);
            if (!$changed) continue;
            $report['pages']++;
            if ($dryRun) continue;
            $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tmp  = $file . '.tmp.' . getmypid();
            if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
                $report['errors'][] = 'page write failed: ' . basename($file);
                @unlink($tmp);
            }
        }
    }
    return $report;
}

/**
 * Move a block type to the end across all three layers of the ACTIVE site.
 *
 * @return array{site:int,templates:int,pages:int,variants:int,errors:string[]}
 */
function block_ops_move_type_to_end_everywhere(string $type, bool $dryRun = false): array {
    $report = ['site' => 0, 'templates' => 0, 'pages' => 0, 'variants' => 0, 'errors' => []];

    $apply = function (array $node, string $type, int &$variantCount) {
        $ids = [];
        $fresh = block_ops_move_type_to_end($node['content_blocks'], $type, $ids);
        $changed = ($fresh !== $node['content_blocks']);
        $node['content_blocks'] = $fresh;
        if (!empty($node['layout_variants'])) {
            $before = $node['layout_variants'];
            $node['layout_variants'] = block_ops_move_ids_to_end_in_variants($before, $ids);
            if ($node['layout_variants'] !== $before) { $changed = true; $variantCount++; }
        }
        return [$node, $changed];
    };

    // Layer 1 — site.json (the homepage)
    $data = load_data();
    if (is_array($data) && !empty($data['content_blocks'])) {
        [$data, $changed] = $apply($data, $type, $report['variants']);
        if ($changed) {
            $report['site'] = 1;
            if (!$dryRun && !save_data($data)) $report['errors'][] = 'site.json write failed';
        }
    }

    // Layer 2 — templates.json
    $templates = tpl_load(); $dirty = false;
    foreach ($templates as $i => $tpl) {
        if (empty($tpl['content_blocks'])) continue;
        [$templates[$i], $changed] = $apply($tpl, $type, $report['variants']);
        if ($changed) { $report['templates']++; $dirty = true; }
    }
    if ($dirty && !$dryRun && !tpl_save($templates)) $report['errors'][] = 'templates.json write failed';

    // Layer 3 — already-generated pages
    if (defined('PAGES_DIR') && is_dir(PAGES_DIR)) {
        foreach (glob(rtrim(PAGES_DIR, '/') . '/*.json') ?: [] as $file) {
            $page = json_decode((string) @file_get_contents($file), true);
            if (!is_array($page) || empty($page['content_blocks'])) continue;
            [$page, $changed] = $apply($page, $type, $report['variants']);
            if (!$changed) continue;
            $report['pages']++;
            if ($dryRun) continue;
            $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tmp  = $file . '.tmp.' . getmypid();
            if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
                $report['errors'][] = 'page write failed: ' . basename($file);
                @unlink($tmp);
            }
        }
    }
    return $report;
}

/**
 * Remove a block type from all three layers of the ACTIVE site.
 *
 * @param string $type   block type to remove (e.g. 'testimonials')
 * @param bool   $dryRun report what would change without writing anything
 * @return array{site:int,templates:int,variant_ids:int,pages:int,errors:string[]}
 */
function block_ops_remove_type_everywhere(string $type, bool $dryRun = false): array {
    $report = ['site' => 0, 'templates' => 0, 'variant_ids' => 0, 'pages' => 0, 'errors' => []];

    // ── Layer 1: the master's site.json (the homepage) ────────────────────────
    $data = load_data();
    if (is_array($data) && !empty($data['content_blocks'])) {
        $ids   = [];
        $fresh = block_ops_remove_type($data['content_blocks'], $type, $ids);
        if (count($fresh) !== count($data['content_blocks'])) {
            $report['site'] = count($data['content_blocks']) - count($fresh);
            $data['content_blocks'] = $fresh;
            if (!empty($data['layout_variants'])) {
                $data['layout_variants'] = block_ops_clean_variants($data['layout_variants'], $ids);
            }
            if (!$dryRun && !save_data($data)) $report['errors'][] = 'site.json write failed';
        }
    }

    // ── Layer 2: templates.json, content_blocks AND layout_variants ───────────
    $templates = tpl_load();
    $tplDirty  = false;
    foreach ($templates as $i => $tpl) {
        if (empty($tpl['content_blocks'])) continue;
        $ids   = [];
        $fresh = block_ops_remove_type($tpl['content_blocks'], $type, $ids);
        if (count($fresh) === count($tpl['content_blocks'])) continue;

        $report['templates'] += count($tpl['content_blocks']) - count($fresh);
        $templates[$i]['content_blocks'] = $fresh;
        if (!empty($tpl['layout_variants'])) {
            $before = 0;
            foreach ($tpl['layout_variants'] as $v) $before += is_array($v) ? count($v) : 0;
            $templates[$i]['layout_variants'] = block_ops_clean_variants($tpl['layout_variants'], $ids);
            $after = 0;
            foreach ($templates[$i]['layout_variants'] as $v) $after += is_array($v) ? count($v) : 0;
            $report['variant_ids'] += $before - $after;
        }
        $tplDirty = true;
    }
    if ($tplDirty && !$dryRun && !tpl_save($templates)) $report['errors'][] = 'templates.json write failed';

    // ── Layer 3: already-generated pages ──────────────────────────────────────
    // No panel primitive owns a single page file (the generation engine writes them),
    // so this writes directly — with the same atomic tmp+rename and the same JSON flags
    // every other writer in the codebase uses.
    if (defined('PAGES_DIR') && is_dir(PAGES_DIR)) {
        foreach (glob(rtrim(PAGES_DIR, '/') . '/*.json') ?: [] as $file) {
            $page = json_decode((string) @file_get_contents($file), true);
            if (!is_array($page) || empty($page['content_blocks'])) continue;
            $ids   = [];
            $fresh = block_ops_remove_type($page['content_blocks'], $type, $ids);
            if (count($fresh) === count($page['content_blocks'])) continue;

            $page['content_blocks'] = $fresh;
            if (!empty($page['layout_variants'])) {
                $page['layout_variants'] = block_ops_clean_variants($page['layout_variants'], $ids);
            }
            $report['pages']++;
            if ($dryRun) continue;

            $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tmp  = $file . '.tmp.' . getmypid();
            if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
                $report['errors'][] = 'page write failed: ' . basename($file);
                @unlink($tmp);
            }
        }
    }

    return $report;
}
