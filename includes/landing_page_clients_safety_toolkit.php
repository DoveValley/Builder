<?php
/**
 * Landing Page Clients — Safety Toolkit reuse (redesign plan item #7).
 *
 * The real reusable part is DATA: plugins/safety_toolkit/niches/{slug}/
 * items.json, 5 real national trust organizations per niche (icon/heading/
 * text/link), already written for all 4 niches. Its own
 * safety_toolkit_niche_items()/safety_toolkit_pick() functions are already
 * globally available by the time any LPC code runs — includes/functions.php
 * auto-requires every plugins/*\/plugin.php unconditionally, including this
 * one, so no require_once is needed here (unlike Area Map's render.php,
 * which ISN'T auto-loaded that way). This file only adds the one piece
 * actually missing: mapping LPC's own niche_id naming onto safety_toolkit's
 * niche folder names (they match for 3 of 4 niches; pest-control's folder
 * is named 'pest').
 */

/** LPC niche_id -> plugins/safety_toolkit/niches/{slug} folder name. */
function lpc_safety_toolkit_niche_slug($niche_id) {
    $map = ['pest-control' => 'pest'];
    return $map[$niche_id] ?? $niche_id;
}

/**
 * Up to 3 of a niche's 5 real trust organizations, deterministically picked
 * and ordered per CLIENT (not per domain the way the niche fleet seeds this —
 * an LPC client doesn't have its own domain the way a niche-fleet site does),
 * same crc32 seeding idiom safety_toolkit_pick() already uses, just a
 * different seed so two clients on the same niche don't show an identical
 * three. Returns [] when there's no niche or no data file for it yet — same
 * "no data means nothing renders" rule as everywhere else in this feature.
 */
function lpc_safety_toolkit_items($niche_id, $client_id) {
    $slug  = lpc_safety_toolkit_niche_slug((string) $niche_id);
    $items = safety_toolkit_niche_items($slug);
    if (empty($items)) return [];
    return safety_toolkit_pick($items, (string) $client_id, 3);
}
