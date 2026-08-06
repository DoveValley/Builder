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
 * Buy one domain. THE ONLY CODE HERE THAT SPENDS MONEY.
 *
 * Deliberately manual and one-at-a-time: a registrar adapter should complete a
 * real purchase under supervision before anything schedules it unattended.
 *
 * Rails, in order — each one exists because of a specific way this can go wrong:
 *   1. must be tracked and not already owned      (no double-buying)
 *   2. must have a registrar assigned             (no guessing where to spend)
 *   3. that registrar must have a purchase adapter (no silent "not wired" after a confirm)
 *   4. availability RE-CHECKED right now          (a "ready" flag from last week is not evidence)
 *   5. on failure: status=buy-failed + the reason, and NOTHING retries on its own
 *
 * @return array{ok:bool, message:string}
 */
function infra_domain_buy(string $domain, array $opts = []): array
{
    $domain = strtolower(trim($domain));
    $rec    = infra_state_get_domain($domain);
    $fail   = function (string $m, bool $record = false) use ($domain) {
        if ($record) infra_state_upsert_domain(['domain' => $domain, 'status' => 'buy-failed', 'buy_error' => $m]);
        return ['ok' => false, 'message' => $m];
    };

    if (!$rec)                        return $fail('not tracked in fleet state');
    if (($rec['owned'] ?? '') === 'yes') return $fail('already owned — refusing to buy it twice');

    $registrar = (string) ($rec['buy_registrar'] ?? '');
    if ($registrar === '')            return $fail('no registrar assigned — set column 3 first');
    $def = infra_registrar_type_def($registrar);
    if (!$def)                        return $fail("unknown registrar '{$registrar}'");
    if (empty($def['buy_wired'])) {
        return $fail(empty($def['buy'])
            ? $def['label'] . ' cannot register domains over its API at all — buy it in their dashboard, then use "Mark as owned"'
            : $def['label'] . "'s purchase adapter is not written yet — only NameSilo can buy today");
    }
    if (!infra_registrar_config($registrar)) return $fail("no saved credentials for '{$registrar}'");

    // 4. Availability is re-checked NOW. The stored flag may be stale, and buying
    //    a name someone else took in the meantime is the expensive kind of wrong.
    $check = infra_registrar_check_availability([$domain], $registrar);
    $res   = $check[$domain] ?? ['available' => null, 'note' => 'no result'];
    if ($res['available'] === false) {
        infra_state_upsert_domain(['domain' => $domain, 'ready_to_buy' => 'no',
            'avail_note' => $res['note'] ?: 'taken', 'avail_checked_at' => gmdate('Y-m-d H:i:s')]);
        return $fail('no longer available (' . ($res['note'] ?: 'taken') . ') — not buying');
    }
    if ($res['available'] !== true) {
        return $fail('could not confirm availability (' . ($res['note'] ?: 'inconclusive') . ') — not buying on a maybe');
    }

    // ---- the purchase ----
    $years = max(1, (int) ($opts['years'] ?? 1));
    $buy   = infra_registrar_register($domain, $years, $registrar);
    if (empty($buy['ok'])) {
        return $fail($buy['message'] ?? 'purchase failed', true);
    }

    $now = gmdate('Y-m-d H:i:s');
    infra_state_upsert_domain([
        'domain'    => $domain,
        'owned'     => 'yes',
        'owned_at'  => $now,
        'status'    => 'owned',
        'registrar' => $registrar,   // where it actually lives, for the go-live NS switch
        'buy_error' => '',
    ]);
    return ['ok' => true, 'message' => $buy['message'] ?? "bought {$domain}"];
}

/**
 * Record a domain bought by hand. Porkbun and Cloudflare Registrar have no
 * registration API, so their purchases happen in a dashboard — without this the
 * domain would sit at 'ready' forever and never reach provisioning.
 */
function infra_domain_mark_owned(string $domain, string $registrar = ''): array
{
    $domain = strtolower(trim($domain));
    $rec    = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'message' => 'not tracked in fleet state'];

    $registrar = $registrar !== '' ? strtolower(trim($registrar)) : (string) ($rec['buy_registrar'] ?? '');
    infra_state_upsert_domain([
        'domain'       => $domain,
        'owned'        => 'yes',
        'owned_at'     => gmdate('Y-m-d H:i:s'),
        'status'       => 'owned',
        'registrar'    => $registrar,
        'ready_to_buy' => '',
        'avail_note'   => 'marked owned by hand',
        'buy_error'    => '',
    ]);
    return ['ok' => true, 'message' => "{$domain} marked owned"
        . ($registrar !== '' ? " at {$registrar}" : '') . ' — no purchase was made by the console'];
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
