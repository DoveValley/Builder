<?php
/**
 * Reads click-tracking data back from the delivery server — the engine
 * itself (collection + storage) lives there (delivery-server/public/
 * api.php's track_click/click_summary actions), since that's the box the
 * WP plugin already talks to for everything else; this file is purely the
 * admin side's read path, same HTTP-GET-with-short-timeout pattern already
 * used for the plugin connection status check.
 */

/**
 * Per-page click counts for one client over the last $days days, summed
 * across however many daily files the delivery server actually has.
 * Returns [] on any failure (no key, unreachable, bad response) rather than
 * throwing — same "Not Connected" spirit as the GSC columns this sits next
 * to in the Reporting tab; a transient fetch failure should read as
 * "nothing to show," not an error.
 */
function lpc_fetch_click_summary($client_id, $days = 30) {
    $apiKey = lpc_get_client_api_key($client_id);
    if ($apiKey === '') return [];

    $url = LPC_DELIVERY_PUBLIC_BASE . '?action=click_summary&key=' . urlencode($apiKey)
        . '&from=' . gmdate('Y-m-d', strtotime('-' . (int) $days . ' days'))
        . '&to=' . gmdate('Y-m-d');
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['days'])) return [];

    // Each day's file is {"page_id__link_type": count} — reassemble into
    // {page_id: {link_type: count}}, summed across every day in range.
    $totals = [];
    foreach ($data['days'] as $dayCounts) {
        foreach ((array) $dayCounts as $key => $count) {
            $parts = explode('__', $key);
            $linkType = array_pop($parts);
            $pageId = implode('__', $parts);
            $totals[$pageId][$linkType] = ($totals[$pageId][$linkType] ?? 0) + (int) $count;
        }
    }
    return $totals;
}

/**
 * One page's counts rolled into the single honest line the Reporting tab
 * actually wants — "N people tried to contact you," not a breakdown per
 * link type, and not a confirmed-conversion count. Blank, not "0", when
 * there's simply no data yet (page too new, or fetch failed) — same
 * "Not Connected" vs "0 clicks" distinction the GSC column already draws.
 */
function lpc_click_summary_line($clickTotals, $page_id) {
    if (!isset($clickTotals[$page_id])) return '';
    $total = array_sum($clickTotals[$page_id]);
    if ($total < 1) return '';
    return $total . ' tried to contact';
}
