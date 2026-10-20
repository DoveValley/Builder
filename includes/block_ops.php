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
