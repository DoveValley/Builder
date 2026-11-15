<?php
/**
 * Reporting tab's data layer — split from landing_page_clients_publish.php
 * the same way the rest of this feature splits by concern. Depends on
 * admin/infra/lib/keywords.php (infra_kw_provider()/infra_kw_dfs_post(),
 * the already-credentialed DataForSEO client) and google_search_console.php
 * — callers must require_once admin/infra/lib/keywords.php themselves (not
 * required here, same reasoning as lpc_city_candidates()'s doc comment:
 * this file loads on every admin page via functions.php, most of which
 * never need it).
 */

/**
 * Does $domain appear in the top $depth organic results for $keyword, and
 * at what absolute position? Reuses infra_kw_dfs_post() — the same
 * DataForSEO client the Cities/Niche tab and this feature's own kickoff
 * wizard already use — rather than writing a second DataForSEO HTTP client.
 * Real cost per call (same provider as the wizard's keyword fetch), so
 * every caller must be an explicit admin action, never automatic.
 */
function lpc_check_rank_for_domain($domain, $keyword, $depth = 100) {
    if (!infra_kw_has_creds('dataforseo')) {
        return ['ok' => false, 'msg' => 'No DataForSEO credentials configured.', 'position' => null];
    }
    $c = infra_kw_provider('dataforseo');
    $r = infra_kw_dfs_post($c, 'serp/google/organic/live/advanced', [
        'keyword'       => $keyword,
        'location_code' => 2840,
        'language_code' => 'en',
        'device'        => 'desktop',
        'depth'         => $depth,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => $r['msg'], 'position' => null];
    }

    $targetHost = strtolower(preg_replace('/^www\./', '', parse_url($domain, PHP_URL_HOST) ?: $domain));
    foreach ((array) ($r['result'][0]['items'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'organic') continue;
        $itemHost = strtolower(preg_replace('/^www\./', '', (string) ($item['domain'] ?? '')));
        if ($itemHost === $targetHost) {
            return ['ok' => true, 'msg' => '', 'position' => (int) ($item['rank_absolute'] ?? 0)];
        }
    }
    return ['ok' => true, 'msg' => '', 'position' => null]; // genuinely not found in the depth checked
}

/**
 * Checks rank for every published page of one client, using a plain
 * "{service} {city}, {state}" phrase as the search keyword — NOT the page's
 * own SEO title. An earlier version used the SEO title directly and it
 * genuinely broke the DataForSEO call (confirmed live: "Internal SE Server
 * Error") — a title like "General Repair in Testville, TX | Mr Test Restore"
 * contains a "|" and brand-name suffix nobody actually searches, unlike the
 * plain city+service phrase this same pattern already uses for the wizard's
 * keyword-volume lookup.
 */
function lpc_refresh_rank_for_client($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found', 'checked' => 0];

    $domain = $client['identity']['domain'] ?? '';
    if ($domain === '') return ['ok' => false, 'msg' => 'Client has no domain set.', 'checked' => 0];

    // Back-to-back calls to DataForSEO's live SERP endpoint can hit a brief
    // rate limit (confirmed live: a second call within the same second as a
    // prior one silently failed "Internal SE Server Error") — a short pause
    // between pages trades a few seconds of wall-clock time for not quietly
    // skipping real pages in a client with many published pages.
    $checked = 0;
    $attempted = 0;
    $failed = [];
    foreach (lpc_list_pages($client_id) as $page_id) {
        $page = lpc_load_page($client_id, $page_id);
        if (!$page || $page['status'] !== 'published') continue;
        $attempted++;

        if ($attempted > 1) usleep(1200000); // ~1.2s between calls

        [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
        $target  = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
        $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $service_id);
        $keyword = trim(($service['label'] ?? $service_id) . ' ' . ($target['city'] ?? '') . ', ' . ($target['state'] ?? ''));

        $result = lpc_check_rank_for_domain($domain, $keyword);
        if (!$result['ok']) { $failed[] = $page_id; continue; } // leave the existing cached value rather than overwrite with a failed probe

        $page['rank_position']   = $result['position'];
        $page['rank_checked_at'] = gmdate('c');
        lpc_save_page($client_id, $page_id, $page);
        $checked++;
    }

    $msg = $failed ? ($checked . ' of ' . $attempted . ' checked — ' . count($failed) . ' failed, try again') : '';
    return ['ok' => true, 'msg' => $msg, 'checked' => $checked, 'attempted' => $attempted];
}
