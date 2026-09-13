<?php
/**
 * Layout variations (multisite structural differentiation, item 2a).
 *
 * A page's blocks render in one natural order (variant 0). For multisite we let the
 * operator generate up to 3 ALTERNATE orderings — hero pinned first, last block pinned
 * last, a couple of swaps in the movable middle — reviewed/edited and saved on the page.
 * At generation each cloned site deterministically gets ONE ordering by domain hash, so
 * sites aren't structurally identical while every layout stays sane.
 *
 * A variant is stored as an ordered list of block IDs (so it survives block edits).
 * Applied by reordering blocks to match; any block whose id isn't listed keeps its place.
 */

/** Assign a stable, unique id to any block that lacks one (deterministic — type + counter). */
function ensure_block_ids(array $blocks): array {
    $used = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $used[$b['id']] = true; }
    foreach ($blocks as &$b) {
        if (empty($b['id'])) {
            $base = (string)($b['type'] ?? 'block');
            $n = 1; $id = $base . '_' . $n;
            while (isset($used[$id])) { $n++; $id = $base . '_' . $n; }
            $b['id'] = $id; $used[$id] = true;
        }
    }
    unset($b);
    return $blocks;
}

/**
 * Generate up to ($total-1) alternate orderings (each a full list of block ids incl. the
 * pinned first + last). Variant 0 = natural order and is NOT returned. Empty if too few
 * movable blocks. Blocks must already have ids (call ensure_block_ids first).
 */
function layout_generate_variants(array $blocks, int $total = 4, bool $randomize = false): array {
    $ids = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $ids[] = $b['id']; }
    $n = count($ids);
    if ($n < 4) return [];                          // need hero + >=2 middle + last

    $first  = $ids[0];
    $last   = $ids[$n - 1];
    $middle = array_slice($ids, 1, $n - 2);
    $m      = count($middle);
    if ($m < 2) return [];

    $variants = [];
    $seen = [implode('|', $middle) => true];        // natural middle — never repeat it

    // Each candidate = a primary adjacent swap (i,i+1) plus a second non-overlapping one,
    // i.e. "a couple of swaps." Deterministic order by default; shuffled on Regenerate so
    // the operator can roll a different (still subtle, still valid) set.
    $primaries = range(0, $m - 2);
    if ($randomize) shuffle($primaries);
    foreach ($primaries as $i) {
        if (count($variants) >= $total - 1) break;
        $cand = $middle;
        [$cand[$i], $cand[$i + 1]] = [$cand[$i + 1], $cand[$i]];
        if ($m >= 4) {
            $j = ($i + 2) % $m; $k = ($j + 1) % $m;
            if (!in_array($j, [$i, $i + 1], true) && !in_array($k, [$i, $i + 1], true)) {
                [$cand[$j], $cand[$k]] = [$cand[$k], $cand[$j]];
            }
        }
        $key = implode('|', $cand);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $variants[] = array_merge([$first], $cand, [$last]);
    }
    return $variants;
}

/** Stable variant index in [0,count) for a domain on a given axis (salt). Deterministic — no RNG. */
function ms_variant(string $domain, int $count, string $salt = ''): int {
    if ($count <= 1) return 0;
    return crc32($salt . '|' . strtolower(trim($domain))) % $count;
}

/**
 * Generate up to ($total-1) alternate orderings of an arbitrary list (each a full
 * permutation of $items — not ids, not a page, just "a couple of swaps" applied to
 * whatever was handed in). Same swap shape as layout_generate_variants(), generalized
 * so a caller can pre-slice off a fixed head/tail first and only pass the movable
 * middle. Variant 0 (natural order) is NOT included. Empty if fewer than 2 items.
 */
function layout_generate_variants_for_list(array $items, int $total = 4): array {
    $m = count($items);
    if ($m < 2) return [];
    $idxVariants = [];
    $seen = [implode('|', range(0, $m - 1)) => true];   // natural order — never repeat it
    $primaries = range(0, $m - 2);
    foreach ($primaries as $i) {
        if (count($idxVariants) >= $total - 1) break;
        $cand = range(0, $m - 1);
        [$cand[$i], $cand[$i + 1]] = [$cand[$i + 1], $cand[$i]];
        if ($m >= 4) {
            $j = ($i + 2) % $m; $k = ($j + 1) % $m;
            if (!in_array($j, [$i, $i + 1], true) && !in_array($k, [$i, $i + 1], true)) {
                [$cand[$j], $cand[$k]] = [$cand[$k], $cand[$j]];
            }
        }
        $key = implode('|', $cand);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $idxVariants[] = $cand;
    }
    return array_map(fn($order) => array_map(fn($i) => $items[$i], $order), $idxVariants);
}

/**
 * Rotate a block list for one domain: the first $skipTop and last $skipBottom blocks
 * never move (so a locked opening or a deliberately-placed closing CTA stays put);
 * whatever's strictly between them is deterministically reordered by a hash of the
 * domain (same domain always lands on the same ordering — no drift on rebuild).
 *
 * Computed live from $blocks every time — nothing pre-authored or saved on the page.
 * This is the domain-level axis the multisite batch build applies (see
 * ms_differentiate_working_dir()); it is separate from, and stacks with, the per-CITY
 * axis engine.php applies at landing-page generation time via the same
 * layout_generate_variants()/ms_variant() building blocks.
 */
function layout_rotate_blocks(array $blocks, string $domain, int $skipTop, int $skipBottom, string $salt): array {
    $n = count($blocks);
    $skipTop    = max(0, $skipTop);
    $skipBottom = max(0, $skipBottom);
    if ($skipTop + $skipBottom >= $n) return $blocks;   // nothing left that could move

    $top    = array_slice($blocks, 0, $skipTop);
    $bottom = $skipBottom > 0 ? array_slice($blocks, $n - $skipBottom) : [];
    $middle = array_slice($blocks, $skipTop, $n - $skipTop - $skipBottom);

    $variants = layout_generate_variants_for_list($middle);
    if (!$variants) return $blocks;                     // too few movable blocks — natural order
    $idx = ms_variant($domain, 1 + count($variants), $salt);
    if ($idx === 0) return $blocks;
    return array_merge($top, $variants[$idx - 1], $bottom);
}

/** Reorder blocks to match a variant's id order; blocks not listed keep their place. */
function layout_apply(array $blocks, array $variantIds): array {
    if (!$variantIds) return $blocks;
    $byId = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $byId[$b['id']] = $b; }
    $out = []; $taken = [];
    foreach ($variantIds as $id) {
        if (isset($byId[$id])) { $out[] = $byId[$id]; $taken[$id] = true; }
    }
    // Append anything not covered by the variant (added after the variant was saved).
    foreach ($blocks as $b) {
        if (empty($b['id']) || !isset($taken[$b['id']])) $out[] = $b;
    }
    return $out;
}

/**
 * The tunable knob for per-city block-order variation, and its fallback — the
 * exact number (4) this whole mechanism was hardcoded to before the Gen-Mod
 * tab existed. A missing/invalid value always falls back to that original
 * number, so a master that's never touched Gen-Mod builds exactly as it
 * always has.
 */
function ms_layout_variation_defaults(): array {
    return ['variant_count' => 4];
}

/** Clamp to a sane range and fill in anything missing — the one place both the
 *  save endpoint and the generator read from, so a hand-edited or half-written
 *  config file can't produce a nonsensical count. */
function ms_layout_variation_settings(array $raw): array {
    $d = ms_layout_variation_defaults();
    $n = is_numeric($raw['variant_count'] ?? null) ? max(2, min(8, (int)$raw['variant_count'])) : $d['variant_count'];
    return ['variant_count' => $n];
}

/**
 * Defaults for the section-order rotation pin counts (see layout_rotate_blocks()) — 1/1 for
 * both scopes, the original hardcoded pin behaviour (hero stays first, closing block stays
 * last). Saved per master in multisite/section_rotation.json (admin/rotation_settings_save.php),
 * same read/write helpers as Gen-Mod's layout_variation.json — a missing/invalid value always
 * falls back to these, so a master that's never touched this setting builds exactly as it
 * always has.
 */
function ms_rotation_defaults(): array {
    return ['home_top' => 1, 'home_bottom' => 1, 'landing_top' => 1, 'landing_bottom' => 1];
}

/** Clamp to a sane range and fill in anything missing — same shape as ms_layout_variation_settings(). */
function ms_rotation_settings(array $raw): array {
    $d = ms_rotation_defaults();
    $out = [];
    foreach ($d as $k => $default) {
        $out[$k] = is_numeric($raw[$k] ?? null) ? max(0, min(20, (int)$raw[$k])) : $default;
    }
    return $out;
}

