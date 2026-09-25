<?php
/**
 * CallTrackingMetrics API client — an isolated capability, not part of the site
 * factory. The only caller is the batch target-list editor's "Get CTM Phone
 * Numbers" bulk action (admin/multisite_api.php, action=ctm_get_numbers).
 * Nothing else in the app reads or writes anything here.
 *
 * Auth is a token exchange, NOT plain HTTP Basic Auth — confirmed against the
 * real API (both the community ctm-php client's source and a live test call):
 * POST access key + secret to /authentication.json, get back a short-lived
 * session token, then pass THAT token as a `?auth_token=` query param on every
 * subsequent call. An earlier version of this module used a Basic Auth header
 * directly with the key/secret pair, which CTM apparently accepts for coarse
 * lookups but not for permission-gated actions like Numbers — this is the real
 * mechanism. CTM_ACCESS_KEY/CTM_SECRET_KEY still come from config.php; the
 * account_id in the URL path is still whatever the caller passes in (the
 * sub-account id typed into the batch page's toolbar) — auth only decides
 * WHETHER that account is reachable at all, not which one you're asking for.
 */

require_once __DIR__ . '/../admin/infra/lib/http.php'; // infra_http() — shared curl client + call counter

const CTM_API_BASE = 'https://api.calltrackingmetrics.com/api/v1';

function ctm_configured(): bool
{
    return defined('CTM_ACCESS_KEY') && trim((string) CTM_ACCESS_KEY) !== ''
        && defined('CTM_SECRET_KEY') && trim((string) CTM_SECRET_KEY) !== '';
}

/**
 * Exchange the access key/secret for a session token. Cached for the lifetime
 * of this process only (each admin request that calls ctm_get_number_for_domain()
 * in a loop authenticates once, not once per domain) — the real token is valid
 * for weeks, but nothing here persists it past one request, on purpose: keeping
 * this isolated tool's on-disk footprint to just the two credential files.
 */
function ctm_auth_token(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    if (!ctm_configured()) {
        return $cached = ['ok' => false, 'error' => 'CTM_ACCESS_KEY/CTM_SECRET_KEY are not configured in config.php.'];
    }

    $r = infra_http('POST', CTM_API_BASE . '/authentication.json', [
        'headers' => ['Accept: application/json'],
        'body'    => http_build_query(['token' => CTM_ACCESS_KEY, 'secret' => CTM_SECRET_KEY]),
        'timeout' => 20,
    ]);
    if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($r['json']) || empty($r['json']['success'])) {
        $msg = is_array($r['json']) ? json_encode($r['json']) : ($r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['code']));
        return $cached = ['ok' => false, 'error' => "Authentication failed: {$msg}"];
    }
    return $cached = [
        'ok'               => true,
        'token'            => $r['json']['token'],
        'first_account_id' => $r['json']['first_account']['id'] ?? null,
    ];
}

/** Every CTM call goes through this one — one place to fix auth/parsing/errors. */
function ctm_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $auth = ctm_auth_token();
    if (!$auth['ok']) return ['ok' => false, 'error' => $auth['error']];

    $query['auth_token'] = $auth['token'];
    $url = CTM_API_BASE . $path . '?' . http_build_query($query);

    $opts = [
        'headers' => ['Accept: application/json', 'Content-Type: application/json'],
        'timeout' => 20,
    ];
    if ($body !== null) $opts['body'] = json_encode($body);

    $r = infra_http($method, $url, $opts);
    if ($r['code'] < 200 || $r['code'] >= 300) {
        $msg = is_array($r['json']) ? json_encode($r['json']) : ($r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['code']));
        return ['ok' => false, 'error' => $msg, 'code' => $r['code']];
    }
    return ['ok' => true, 'data' => $r['json'], 'code' => $r['code']];
}

/** Available numbers in one area code, under one sub-account. ~50 results, best-effort order from CTM. */
function ctm_search_numbers(string $accountId, string $areaCode): array
{
    $r = ctm_request('GET', "/accounts/{$accountId}/numbers/search.json", [
        'country'  => 'US',
        'searchby' => 'area',
        'areacode' => $areaCode,
    ]);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'numbers' => $r['data']['numbers'] ?? []];
}

/**
 * Score how "nice" a number is, using only the exchange (NXX, digits 4-6)
 * and subscriber line (XXXX, digits 7-10) -- the area code is fixed per
 * search so it's excluded. An adjacent pair is "aa"/"bb"/... within ONE of
 * those two segments; a pair straddling the exchange/subscriber boundary
 * (e.g. exchange ...5, subscriber 5...) does not count, and two pairs both
 * crammed into the 3-digit exchange isn't geometrically possible anyway.
 *   2 = the requested pattern: two adjacent pairs of DIFFERENT digits,
 *       positioned either both within the subscriber (e.g. NXX-5588) or one
 *       in the exchange and one in the subscriber (e.g. exchange 5-5-4 with
 *       pair "55", subscriber 9-9-2-2 with pair "99" or "22").
 *   1 = only one such pair found (in either segment alone).
 *   0 = no adjacent pair in either segment.
 * Used to prefer memorable numbers among CTM's search results before
 * purchase; a plain number scores 0 and sorts no worse than CTM's own
 * original order.
 */
function ctm_number_pattern_score(string $phoneE164): int
{
    $digits = preg_replace('/\D/', '', $phoneE164);
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) !== 10) return 0;

    $exchange   = substr($digits, 3, 3);
    $subscriber = substr($digits, 6, 4);

    $pairDigits = function (string $seg): array {
        preg_match_all('/(\d)\1/', $seg, $m);
        return array_unique($m[1]);
    };

    $exPairs  = $pairDigits($exchange);
    $subPairs = $pairDigits($subscriber);

    if (count($subPairs) >= 2) return 2;
    foreach ($exPairs as $e) {
        foreach ($subPairs as $s) {
            if ($e !== $s) return 2;
        }
    }

    return ($exPairs || $subPairs) ? 1 : 0;
}

/** Buy one specific number (E.164) under a sub-account. */
function ctm_buy_number(string $accountId, string $phoneNumberE164): array
{
    $r = ctm_request('POST', "/accounts/{$accountId}/numbers", [], ['phone_number' => $phoneNumberE164]);
    if (!$r['ok']) return $r;
    $num = $r['data']['number'] ?? null;
    if (!$num) return ['ok' => false, 'error' => 'Purchase call succeeded but returned no number object.'];
    return ['ok' => true, 'number' => $num];
}

/**
 * Set a number's dashboard label to the domain — this is the "description" the
 * domain gets registered under in CTM. Field name is CTM's own `name` (confirmed
 * from their public number-resource schema, which shows `"name": null` on a
 * fresh purchase — not documented anywhere as settable, so this tries the one
 * community-documented update endpoint first and a plain resource PUT as a
 * fallback. ctm_get_number_for_domain() always re-reads the number afterward to
 * confirm the label actually stuck, rather than trusting a 200 alone.
 */
function ctm_set_number_name(string $accountId, string $numberId, string $name): array
{
    $r = ctm_request('POST', "/accounts/{$accountId}/numbers/{$numberId}/update_number", [], ['name' => $name]);
    if (!$r['ok']) {
        $r = ctm_request('PUT', "/accounts/{$accountId}/numbers/{$numberId}.json", [], ['number' => ['name' => $name]]);
    }
    return $r;
}

function ctm_get_number(string $accountId, string $numberId): array
{
    return ctm_request('GET', "/accounts/{$accountId}/numbers/{$numberId}.json");
}

/** One page of every number owned under a sub-account — used to find a number's id when only its phone value is known (purchase doesn't get re-fetched by phone anywhere else). */
function ctm_list_numbers(string $accountId, int $page = 1): array
{
    $r = ctm_request('GET', "/accounts/{$accountId}/numbers.json", ['page' => $page]);
    if (!$r['ok']) return $r;
    return [
        'ok'           => true,
        'numbers'      => $r['data']['numbers'] ?? [],
        'total_pages'  => $r['data']['total_pages'] ?? 1,
    ];
}

/** Release (permanently give up) a purchased number — the counterpart to ctm_buy_number(). Irreversible: CTM does not guarantee you can reclaim the same number later. */
function ctm_release_number(string $accountId, string $numberId): array
{
    $r = ctm_request('DELETE', "/accounts/{$accountId}/numbers/{$numberId}.json");
    if (!$r['ok']) return $r;
    return ['ok' => true, 'released' => $r['data']['released'] ?? true];
}

/**
 * Real area code for a city — from this app's own reference `cities` table
 * (admin/infra/state/fleet.db, real NANPA-sourced data) when available, else
 * never invented. This list does double duty:
 *  1. Real US cities that table has no row for at all.
 *  2. Known corrections where the table's own `area_codes` list is real but
 *     UNRANKED — its `ac_source` can be 'near' (a nearby-city approximation,
 *     several overlapping codes with no ordering) rather than an exact match,
 *     so "take the first one" isn't reliable. Glendale, CA is a caught example:
 *     the table returned "747 818 323 213 626 310 424" (near-approx, 7 codes,
 *     arbitrary order) and picked 747 (a 2009 overlay) over 818 (the real
 *     original code for that area since 1984). Add a case here whenever one is
 *     found — there's no general "prefer the lower number" rule that holds,
 *     since overlay-vs-original isn't a numeric relationship.
 */
function ctm_area_code_for_city(string $city, string $ss): ?string
{
    static $manual = [
        'spring hill|fl'   => '352', 'lexington|ky'     => '859', 'boise|id'        => '208',
        'silver spring|md' => '301', 'west hartford|ct' => '860', 'athens|ga'       => '706',
        'van nuys|ca'      => '818', 'antioch|tn'       => '615', 'brooklyn|md'     => '410',
        'ronkonkoma|ny'    => '631', 'bensalem|pa'      => '215', 'cumberland|ri'   => '401',
        'elmhurst|ny'      => '718', 'temple hills|md'  => '301', 'east brunswick|nj' => '732',
        // Verified against real overlay/original-code history (each searched, not
        // guessed) during the full audit of this batch's 34 multi-code cities —
        // 20 already matched their real primary; these 14 didn't:
        'glendale|ca'      => '818', 'provo|ut'         => '801', 'dallas|ga'       => '770',
        'fairfax|va'       => '703', 'schaumburg|il'    => '847', 'allentown|pa'    => '610',
        'broken arrow|ok'  => '918', 'syracuse|ut'      => '801', 'alexandria|va'   => '703',
        'morristown|nj'    => '973', 'marietta|ga'      => '770', 'ogden|ut'        => '801',
        'fenton|mo'        => '636', 'plainfield|il'    => '815',
    ];
    $key = strtolower(trim($city)) . '|' . strtolower(trim($ss));
    if (isset($manual[$key])) return $manual[$key];

    $dbPath = dirname(__DIR__) . '/admin/infra/state/fleet.db';
    if (!is_file($dbPath)) return null;
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $st = $db->prepare('SELECT area_codes FROM cities WHERE lower(city) = lower(?) AND ss = ?');
        $st->execute([$city, strtoupper($ss)]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if ($rec && trim($rec['area_codes'] ?? '') !== '') {
            $codes = preg_split('/\s+/', trim($rec['area_codes']));
            return $codes[0] ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/**
 * The whole flow for one domain: area code -> search -> buy -> label -> verify
 * the label stuck. Never guesses a wrong-region number — a zero-inventory area
 * code fails loudly instead of silently substituting a nearby one.
 */
function ctm_get_number_for_domain(string $accountId, string $domain, string $city, string $ss): array
{
    $areaCode = ctm_area_code_for_city($city, $ss);
    if ($areaCode === null) {
        return ['ok' => false, 'error' => "No known area code for {$city}, {$ss} — not attempted."];
    }

    $search = ctm_search_numbers($accountId, $areaCode);
    if (!$search['ok']) return ['ok' => false, 'error' => "Search failed: {$search['error']}"];
    if (empty($search['numbers'])) {
        return ['ok' => false, 'error' => "No numbers available in area code {$areaCode} ({$city}, {$ss})."];
    }

    $numbers = $search['numbers'];
    usort($numbers, fn($a, $b) => ctm_number_pattern_score($b['phone_number'] ?? $b['number'] ?? '')
        <=> ctm_number_pattern_score($a['phone_number'] ?? $a['number'] ?? ''));

    $candidate = $numbers[0];
    $phoneE164 = $candidate['phone_number'] ?? $candidate['number'] ?? null;
    if (!$phoneE164) return ['ok' => false, 'error' => 'Search result had no usable phone number field.'];

    $buy = ctm_buy_number($accountId, $phoneE164);
    if (!$buy['ok']) return ['ok' => false, 'error' => "Purchase failed: {$buy['error']}"];

    $number    = $buy['number'];
    $numberId  = $number['id'] ?? null;
    $formatted = $number['formatted'] ?? $number['number'] ?? $phoneE164;

    $warning = '';
    if ($numberId) {
        $label = ctm_set_number_name($accountId, $numberId, $domain);
        if (!$label['ok']) {
            $warning = "Bought {$formatted} but could not label it with the domain in CTM ({$label['error']}) — set it by hand.";
        } else {
            $verify  = ctm_get_number($accountId, $numberId);
            $gotName = $verify['ok'] ? ($verify['data']['name'] ?? null) : null;
            if ($gotName !== $domain) {
                $warning = "Bought {$formatted}, but its CTM label reads '" . ($gotName ?? '(blank)') . "' instead of the domain — check it in CTM.";
            }
        }
    } else {
        $warning = 'Bought a number but the purchase response had no id — could not label it.';
    }

    return [
        'ok'            => true,
        'phone'         => $formatted,
        'phone_e164'    => $phoneE164,
        'ctm_number_id' => $numberId,
        'area_code'     => $areaCode,
        'warning'       => $warning,
    ];
}
