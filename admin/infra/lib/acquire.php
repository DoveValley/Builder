<?php
/**
 * infra/lib/acquire.php — the acquisition stage: begin → ready → owned.
 *
 * This is everything BEFORE the existing provisioning pipeline. Nothing here
 * spends money: availability checking is read-only at every registrar. The
 * purchase step itself lives separately so that the money-spending code has one
 * obvious home rather than being mixed into a checker.
 */
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/registrar.php';
require_once __DIR__ . '/cache.php';

const INFRA_OWNED_INDEX_TTL = 1800;   // our own registrar holdings change rarely

/**
 * Domains held across all configured registrar accounts, cached.
 * Listing 300+ domains is several paged API calls, so it must not run per-check.
 * @return array lowercase domain => registrar name
 */
function infra_owned_index_cached(): array
{
    $c = infra_cache_get('reg_owned', INFRA_OWNED_INDEX_TTL);
    if ($c !== null) return $c;
    $idx = infra_registrar_owned_index();
    infra_cache_put('reg_owned', $idx);
    return $idx;
}

/**
 * Check availability for a set of domains and write the result to fleet state.
 *
 * Distinguishes three outcomes, and never collapses them:
 *   available            → ready_to_buy=yes, status=ready
 *   taken by someone else → ready_to_buy=no,  note='taken'
 *   taken because WE own it → ready_to_buy=no, note='self-owned'
 *   check failed          → ready_to_buy left UNCHANGED, note records why
 *
 * That last case matters: a network blip must never mark a good name dead.
 *
 * @param  string[] $domains
 * @return array{summary:string, counts:array}
 */
function infra_domains_apply_availability(array $domains, string $registrarName): array
{
    $domains = array_values(array_unique(array_filter(array_map(
        fn($d) => strtolower(trim((string) $d)), $domains))));
    $counts = ['available' => 0, 'taken' => 0, 'self' => 0, 'failed' => 0, 'premium' => 0];

    if (!$domains) {
        return ['summary' => 'Availability check: nothing to check.', 'counts' => $counts];
    }
    if ($registrarName === '' || !infra_registrar_config($registrarName)) {
        return ['summary' => "Availability check skipped — registrar '{$registrarName}' has no saved credentials.",
                'counts' => $counts];
    }

    $results = infra_registrar_check_availability($domains, $registrarName);
    $mine    = infra_owned_index_cached();
    $now     = gmdate('Y-m-d H:i:s');

    foreach ($domains as $d) {
        $res   = $results[$d] ?? ['available' => null, 'price' => '', 'note' => 'no result returned'];
        $rec   = infra_state_get_domain($d);
        $write = ['domain' => $d, 'avail_checked_at' => $now, 'avail_price' => (string) $res['price']];

        if ($res['available'] === true) {
            $write['ready_to_buy'] = 'yes';
            $write['avail_note']   = $res['note'] === 'premium' ? 'premium' : '';
            // Only advance the lifecycle from the acquisition stage — never drag a
            // domain that is already owned/staged/live backwards to 'ready'.
            if (in_array($rec['status'] ?? 'begin', ['', 'begin', 'ready'], true)) $write['status'] = 'ready';
            $counts['available']++;
            if ($res['note'] === 'premium') $counts['premium']++;
        } elseif ($res['available'] === false) {
            $write['ready_to_buy'] = 'no';
            if (isset($mine[$d])) {
                $write['avail_note'] = 'self-owned';
                $counts['self']++;
            } else {
                $write['avail_note'] = $res['note'] !== '' ? $res['note'] : 'taken';
                $counts['taken']++;
            }
            if (in_array($rec['status'] ?? 'begin', ['', 'ready'], true)) $write['status'] = 'begin';
        } else {
            // Inconclusive: record the reason, leave the yes/no verdict alone.
            $write['avail_note'] = $res['note'] !== '' ? $res['note'] : 'check inconclusive';
            $counts['failed']++;
        }
        infra_state_upsert_domain($write);
    }

    $parts = ["{$counts['available']} available"];
    if ($counts['premium']) $parts[] = "{$counts['premium']} of them premium";
    if ($counts['taken'])   $parts[] = "{$counts['taken']} taken";
    if ($counts['self'])    $parts[] = "{$counts['self']} already yours";
    if ($counts['failed'])  $parts[] = "{$counts['failed']} could not be checked";

    return [
        'summary' => 'Availability via ' . $registrarName . ' — ' . implode(', ', $parts) . '.',
        'counts'  => $counts,
    ];
}

/**
 * Assign a registrar to each domain, spreading round-robin across the registrars
 * that can actually complete a purchase.
 *
 * Spreading is a footprint decision, not a convenience: putting several hundred
 * domains for one network at a single registrar is exactly the clustering the
 * network strategy is trying to avoid.
 *
 * @return array{n:int, spread:array}
 */
function infra_domains_assign_registrars(array $domains, string $choice): array
{
    if ($choice !== '__rr__') {
        return ['n' => infra_state_bulk_set($domains, ['buy_registrar' => $choice]), 'spread' => [$choice => count($domains)]];
    }
    $pool = infra_registrar_buyable();
    if (!$pool) return ['n' => 0, 'spread' => []];

    $n = 0; $spread = array_fill_keys($pool, 0);
    foreach ($domains as $d) {
        // Persistent counter, so the spread stays even across separate batches.
        $pick = $pool[infra_state_counter_next('rr_buy_registrar') % count($pool)];
        if (infra_state_bulk_set([$d], ['buy_registrar' => $pick])) { $n++; $spread[$pick]++; }
    }
    return ['n' => $n, 'spread' => $spread];
}
