<?php
/**
 * infra/lib/golive.php — Phase-3 go-live orchestration.
 * Lifecycle: staged → queued (scheduled) → releasing/awaiting-ns → live.
 * "Going live" = switching the domain's nameservers at the registrar to the
 * Cloudflare pair; Cloudflare then flips the zone to active, which we detect.
 */
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/registrar.php';
require_once __DIR__ . '/fleet.php';   // infra_cf_zone_index() (cached)

/**
 * Refresh live status from Cloudflare (zone active ⇒ live). Uses the cached CF
 * zone index (one sweep across accounts) instead of a call per domain, so it
 * scales to thousands of domains. Callers that need truly-live data
 * (cron, the Refresh button) set infra_cache_force() first.
 * @return int number newly marked live
 */
function infra_golive_refresh_live(): int
{
    $idx = infra_cf_zone_index();
    $n = 0;
    foreach (infra_state_all_domains() as $dom => $rec) {
        if (($rec['status'] ?? '') === 'live') continue;
        $z = $idx[$dom] ?? null;
        if ($z && ($z['status'] ?? '') === 'active') {
            infra_state_upsert_domain(['domain' => $dom, 'status' => 'live']);
            $n++;
        }
    }
    return $n;
}

/** Schedule all not-yet-live domains into daily batches. @return int scheduled */
function infra_golive_schedule(int $perDay, string $startDate): int
{
    $perDay = max(1, $perDay);
    // Only schedule domains that are actually ready to go live — not live, and not
    // stuck in 'partial'/'register-failed'. (Re-scheduling 'queued' is fine.)
    $rows = array_filter(infra_state_all_domains(), fn($r) => in_array($r['status'] ?? '', ['staged', 'queued'], true));
    ksort($rows);
    $start = strtotime($startDate ?: gmdate('Y-m-d'));
    if ($start === false) $start = time();
    $i = 0;
    foreach ($rows as $dom => $r) {
        $date = gmdate('Y-m-d', $start + intdiv($i, $perDay) * 86400);
        infra_state_upsert_domain(['domain' => $dom, 'go_live_at' => $date, 'status' => 'queued']);
        $i++;
    }
    return $i;
}

/** Domains scheduled on/before $date and not yet live. @return array domain=>record */
function infra_golive_due(?string $date = null): array
{
    $date = $date ?: gmdate('Y-m-d');
    $due = [];
    foreach (infra_state_all_domains() as $dom => $r) {
        if (($r['status'] ?? '') === 'live') continue;
        $gla = $r['go_live_at'] ?? '';
        if ($gla !== '' && $gla <= $date) $due[$dom] = $r;
    }
    return $due;
}

/**
 * Release one domain: switch nameservers at the registrar (or surface the manual
 * step), and mark its status. Live is confirmed later via infra_golive_refresh_live().
 * @return array{ok:bool, manual:bool, message:string, ns:array}
 */
function infra_golive_release(string $domain): array
{
    $rec = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'manual' => false, 'message' => 'not in fleet state', 'ns' => []];
    $ns = array_values(array_filter(array_map('trim', explode(',', $rec['nameservers'] ?? ''))));
    if (!$ns) return ['ok' => false, 'manual' => false, 'message' => 'no Cloudflare nameservers on record — stage it first', 'ns' => []];

    $sw = infra_registrar_set_ns($domain, $ns, $rec['registrar'] ?? '');
    infra_state_upsert_domain([
        'domain'     => $domain,
        'status'     => $sw['ok'] ? 'releasing' : 'awaiting-ns',
        'go_live_at' => $rec['go_live_at'] ?: gmdate('Y-m-d'),
    ]);
    return ['ok' => $sw['ok'], 'manual' => !empty($sw['manual']), 'message' => $sw['message'], 'ns' => $ns];
}
