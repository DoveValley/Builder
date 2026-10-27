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
 * CTM dashboard label: NICHE-B<batch seq>-<row, zero-padded>-<SS>-<City>-<domain>
 * — e.g. WAT-B26-002-CA-Glendale-ellisonrestoration.com. A sub-account holding
 * numbers for several batches/niches at once otherwise has no way to tell them
 * apart from the bare domain alone, or to tell WHERE a number is without opening
 * the target list. Niche = the master id's own first 3 letters. Batch number =
 * batch.json's real `seq` field (the same number Scott already calls this batch
 * by, "batch 26" — not the internal b5-style id). Row = this domain's place in
 * the target list's own order (the same "#" column shown on screen). City has
 * its spaces stripped ("Sioux Falls" -> "SiouxFalls") so it doesn't read as an
 * extra hyphen-delimited field of its own.
 *
 * Single shared builder — used both by the live purchase flow (ctm_get_numbers)
 * and any later bulk re-label pass, so the two can never drift into different
 * formats.
 */
function ctm_build_label(string $nicheCode, int $batchSeq, int $rowNum, string $ss, string $city, string $domain): string
{
    $cityCompact = preg_replace('/\s+/', '', trim($city));
    return sprintf('%s-B%d-%03d-%s-%s-%s', strtoupper($nicheCode), $batchSeq, $rowNum, strtoupper(trim($ss)), $cityCompact, $domain);
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
 * Repeatedly search one area code, pooling every unique number CTM offers
 * across calls, until CTM_POOL_DRY_STREAK consecutive calls add nothing new
 * to the pool -- confirmed by real testing (ctm_dry_run.php) that CTM's
 * search endpoint is NOT reliably deterministic call to call: one area code
 * returned the same 20 numbers twice in a row on one run, then an almost
 * entirely different batch on the very next run minutes later. A single
 * search can't be trusted to show everything on offer. Hard-capped at
 * CTM_POOL_MAX_ATTEMPTS total calls regardless of streak, so one area code
 * can never stall a batch run (or run up the call count) indefinitely.
 */
function ctm_pool_numbers(string $accountId, string $areaCode): array
{
    $maxAttempts = 8;
    $dryStreak   = 3;
    $delaySeconds = 1;

    $pool = [];
    $dry = 0;
    $attempts = 0;

    while ($attempts < $maxAttempts && $dry < $dryStreak) {
        $attempts++;
        $search = ctm_search_numbers($accountId, $areaCode);
        if (!$search['ok']) {
            if (empty($pool)) return ['ok' => false, 'error' => $search['error'], 'attempts' => $attempts];
            break; // keep whatever was already pooled if a later call fails
        }

        $before = count($pool);
        foreach ($search['numbers'] as $n) {
            $phone = $n['phone_number'] ?? $n['number'] ?? null;
            if ($phone && !isset($pool[$phone])) $pool[$phone] = $n;
        }
        $dry = (count($pool) > $before) ? 0 : $dry + 1;

        if ($attempts < $maxAttempts && $dry < $dryStreak) sleep($delaySeconds);
    }

    return ['ok' => true, 'numbers' => array_values($pool), 'attempts' => $attempts];
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

/**
 * One page of call records under a sub-account, date-filtered.
 *
 * Confirmed live against the real API (not guessed from docs — CTM's public docs
 * page for this endpoint is a JS-rendered Postman collection, not fetchable): GET
 * /accounts/{id}/calls.json, `start_date`/`end_date` as YYYY-MM-DD, `per_page` capped
 * server-side at 150 ("per_page limit is 150" on a 400). The field this module's join
 * depends on is `tracking_label` — the exact string ctm_build_label() writes when a
 * number is purchased, e.g. "WAT-B26-002-CA-Glendale-ellisonrestoration.com".
 *
 * ⚠ THIS ACCOUNT IS NOT FLEET-EXCLUSIVE. A real pull against 151459 turned up calls
 * back to 2021 for unrelated businesses ("Gorilla Power", "slableakservice.com") that
 * pre-date this site factory entirely — this sub-account was already in use before any
 * fleet number was bought into it. Every caller of this function MUST resolve
 * `tracking_label` against the real `domains` table before counting a call as fleet
 * traffic; this client has no way to filter that out itself, since CTM has no notion
 * of "which labels are ours."
 */
function ctm_list_calls(string $accountId, string $startDate, string $endDate, int $page = 1, int $perPage = 150): array
{
    $r = ctm_request('GET', "/accounts/{$accountId}/calls.json", [
        'start_date' => $startDate, 'end_date' => $endDate, 'page' => $page, 'per_page' => $perPage,
    ]);
    if (!$r['ok']) return $r;
    return [
        'ok'            => true,
        'calls'         => $r['data']['calls'] ?? [],
        'total_pages'   => (int) ($r['data']['total_pages'] ?? 1),
        'total_entries' => (int) ($r['data']['total_entries'] ?? 0),
    ];
}

/** One page of every number owned under a sub-account — used to find a number's id when only its phone value is known (purchase doesn't get re-fetched by phone alone anywhere else). */
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
        // Found one at a time, same as above: the table's 'near' approximation
        // pulled San Antonio's 210, but Boerne is Hill Country, not San Antonio
        // proper — its real code is 830.
        'boerne|tx'        => '830',
        // Different failure mode, same fix: this one WAS an exact match, but
        // Frederick has two real area codes on record ("240 301", an
        // original/overlay pair like Glendale/Provo above) and the lookup just
        // took whichever was listed first — 240, the newer overlay, not 301.
        'frederick|md'     => '301',
        // Verified against live sources (Wikipedia + area-code reference sites) while
        // filling area_code by hand for appliance-site batch b3 rows 47/65-100 — same
        // "took whichever was listed first, or a 'near' guess" failure as above, found
        // on 6 of that batch's 37 cities. Dallas-area suburbs (Frisco/McKinney) split
        // off Dallas proper's 214 into 972 in 1996; Arlington TX was never in the 214
        // complex at all, it's always been Fort Worth's 817/682. Fort Worth itself had
        // 682 listed first, but 682 is the 2000 overlay — 817 is the 1953 original.
        // Chicago's 'near' list was all suburban-ring codes with no Chicago-proper code
        // in it at all; San Francisco's 'near' pick (650) is the Peninsula, not SF —
        // SF's real code, 415, is one of the original 86 from 1947 and wasn't even a
        // candidate in the 'near' list.
        'san francisco|ca' => '415', 'chicago|il'       => '312', 'fort worth|tx'   => '817',
        'frisco|tx'        => '972', 'mckinney|tx'      => '972', 'arlington|tx'    => '817',
        // These 9 had NO row in fleet.db's cities table at all (not 'near', not
        // 'exact' — just missing), found 2026-10-27 while clearing the rebuy-
        // blockers ahead of the mold/pest/water batch-1 area-code cleanup. Picked
        // each city's primary/original code for consistency with the rest of this
        // table — NOT because the overlay would be wrong: a 2026-10-27 audit found
        // most of this table's "corrections" were cosmetic (overlay codes are
        // equally real and equally local, no geographic difference), so where a
        // city below has a known overlay it's noted, but either code would work.
        'kent|wa'          => '253', // overlay 425 also serves Kent
        'carson|ca'        => '310', // overlay 424
        'pasadena|ca'      => '626', // no overlay live yet (279 pending CPUC approval)
        'leander|tx'       => '512', // overlay 737
        'charleston|sc'    => '843', // overlay 854
        'lehi|ut'          => '801', // overlay 385
        'warwick|ri'       => '401', // no overlay — RI is single-code statewide
        'miami beach|fl'   => '305', // overlays 786, 645
        'tustin|ca'        => '714', // overlay 657
        // Full mold-site batch-2 audit, 2026-10-27 — all 19 of this batch's cities with
        // no row in fleet.db's cities table at all, verified one at a time against live
        // sources. 17 of 19 were already correct on file; 2 were real mismatches
        // (overlay instead of primary), found and left on their LIVE rows pending a
        // Correct & Regenerate decision rather than changed out from under a working
        // number — see coltonmoldremediation.com / norbertmoldremediation.com.
        'pueblo|co'            => '719', 'whitestown|in'      => '317', // overlay 463
        'new hyde park|ny'     => '516', // overlay 363
        'san gabriel|ca'       => '626', // overlay 840 (same split code as Pasadena above)
        'red bank|nj'          => '732', // overlay 848
        'columbia|sc'          => '803', // overlay 839
        'foley|al'             => '251', 'grayslake|il'       => '847', // overlay 224 — this batch's row had the overlay
        'sonoma|ca'            => '707', // overlay 369
        'glenwood springs|co'  => '970', 'arlington|tn'       => '901',
        'connersville|in'      => '765', 'chowchilla|ca'      => '559', // overlay 349
        'maricopa|az'          => '520', // Pinal County town, NOT Maricopa COUNTY (Phoenix) — overlay 887
        'bogalusa|la'          => '985', 'lenoir|nc'          => '828',
        'manvel|tx'            => '281', // Houston-suburb split code, not downtown's 713
        'london|oh'            => '740', // this batch's row had 614 (Columbus) — wrong city's code entirely
        'lynn haven|fl'        => '850',
        // Re-audit of mold-site batch 2, 2026-10-27 — this time EVERY one of the 100
        // rows (not just ones missing a fleet.db entry), each checked against real
        // split/overlay history rather than trusted because fleet.db had an 'exact'
        // row on file. Found 10 more real overlay-vs-primary mismatches the narrower
        // first pass missed:
        'vista|ca'             => '760', // overlay 442 — same San Diego North County split as Oceanside below
        'oceanside|ca'         => '760', // overlay 442
        'american fork|ut'     => '801', // overlay 385 — same statewide pattern as Provo/Syracuse/Ogden/Lehi above
        'layton|ut'            => '801', // overlay 385
        'north canton|oh'      => '330', // overlay 234 — Akron/Canton's original code
        'burbank|ca'           => '818', // overlay 747 — same San Fernando Valley split as Glendale above
        'richardson|tx'        => '972', // the 1996 214/972 split moved the whole Dallas suburban ring, not just Frisco/McKinney
        'grand prairie|tx'     => '972', // same 1996 suburban-ring split as Richardson
        'naperville|il'        => '630', // overlay 331 — DuPage County's original code
        'garden grove|ca'      => '714', // overlay 657 — Orange County's original code, same pattern as Tustin above
    ];
    $key = strtolower(trim($city)) . '|' . strtolower(trim($ss));
    if (isset($manual[$key])) return $manual[$key];

    $dbPath = dirname(__DIR__) . '/admin/infra/state/fleet.db';
    if (!is_file($dbPath)) return null;
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $st = $db->prepare('SELECT area_codes, ac_source FROM cities WHERE lower(city) = lower(?) AND ss = ?');
        $st->execute([$city, strtoupper($ss)]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        // Only an EXACT match is a fact. 'near' is this table's own flag for "borrowed
        // from a nearby city in the same state" — a guess, not a verified answer, and
        // guessing here means CTM can buy a real number in the wrong region (found on
        // Boerne, TX: the table's 'near' pick was San Antonio's 210, but Boerne is
        // Hill Country and really uses 830). Treat 'near' the same as no row at all —
        // refuse rather than buy on a guess. Add the real code to $manual above once
        // it's been checked, same as every other entry in that list.
        if ($rec && ($rec['ac_source'] ?? '') === 'exact' && trim($rec['area_codes'] ?? '') !== '') {
            $codes = preg_split('/\s+/', trim($rec['area_codes']));
            return $codes[0] ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/**
 * The whole flow for one domain: area code -> pool -> buy -> label -> verify
 * the label stuck. Never guesses a wrong-region number — a zero-inventory area
 * code fails loudly instead of silently substituting a nearby one. "Pool"
 * (ctm_pool_numbers()) means this doesn't settle for one search's results —
 * it keeps searching the same area code until inventory stops turning up
 * anything new, then picks the best-scoring number out of everything seen.
 *
 * @param string $label The CTM dashboard label to set on the purchased number —
 *        composed by the caller (niche + batch + row position + domain, see
 *        admin/multisite_api.php's ctm_get_numbers action), not just the bare
 *        domain, so a sub-account holding numbers for several batches/niches at
 *        once can still tell them apart at a glance.
 * @param string $areaCodeOverride A human already typed an area code into this
 *        row — trust it outright and skip the lookup entirely, rather than
 *        second-guessing a person who may well know the city better than the
 *        reference table does. Left blank, this falls back to
 *        ctm_area_code_for_city(), which itself refuses (returns null) rather
 *        than buy on an unverified guess.
 */
function ctm_get_number_for_domain(string $accountId, string $domain, string $city, string $ss, string $label, string $areaCodeOverride = ''): array
{
    $areaCodeOverride = trim($areaCodeOverride);
    if ($areaCodeOverride !== '') {
        if (!preg_match('/^\d{3}$/', $areaCodeOverride)) {
            return ['ok' => false, 'error' => "'{$areaCodeOverride}' isn't a 3-digit area code — fix the row's Area Code field or clear it to look one up."];
        }
        $areaCode = $areaCodeOverride;
    } else {
        $areaCode = ctm_area_code_for_city($city, $ss);
        if ($areaCode === null) {
            return ['ok' => false, 'error' => "No verified area code for {$city}, {$ss} — only an approximate match on record. Type the real area code into this row, or add it to \$manual in ctm_area_code_for_city() once confirmed."];
        }
    }

    $pooled = ctm_pool_numbers($accountId, $areaCode);
    if (!$pooled['ok']) return ['ok' => false, 'error' => "Search failed: {$pooled['error']}"];
    if (empty($pooled['numbers'])) {
        return ['ok' => false, 'error' => "No numbers available in area code {$areaCode} ({$city}, {$ss}) after {$pooled['attempts']} searches."];
    }

    $numbers = $pooled['numbers'];
    usort($numbers, fn($a, $b) => ctm_number_pattern_score($b['phone_number'] ?? $b['number'] ?? '')
        <=> ctm_number_pattern_score($a['phone_number'] ?? $a['number'] ?? ''));

    $candidate = $numbers[0];
    $bestScore = ctm_number_pattern_score($candidate['phone_number'] ?? $candidate['number'] ?? '');
    $phoneE164 = $candidate['phone_number'] ?? $candidate['number'] ?? null;
    if (!$phoneE164) return ['ok' => false, 'error' => 'Search result had no usable phone number field.'];

    $buy = ctm_buy_number($accountId, $phoneE164);
    if (!$buy['ok']) return ['ok' => false, 'error' => "Purchase failed: {$buy['error']}"];

    $number    = $buy['number'];
    $numberId  = $number['id'] ?? null;
    $formatted = $number['formatted'] ?? $number['number'] ?? $phoneE164;

    $warning = '';
    if ($numberId) {
        $labelResult = ctm_set_number_name($accountId, $numberId, $label);
        if (!$labelResult['ok']) {
            $warning = "Bought {$formatted} but could not label it in CTM ({$labelResult['error']}) — set it by hand.";
        } else {
            $verify  = ctm_get_number($accountId, $numberId);
            $gotName = $verify['ok'] ? ($verify['data']['name'] ?? null) : null;
            if ($gotName !== $label) {
                $warning = "Bought {$formatted}, but its CTM label reads '" . ($gotName ?? '(blank)') . "' instead of '{$label}' — check it in CTM.";
            }
        }
    } else {
        $warning = 'Bought a number but the purchase response had no id — could not label it.';
    }

    return [
        'ok'             => true,
        'phone'          => $formatted,
        'phone_e164'     => $phoneE164,
        'ctm_number_id'  => $numberId,
        'area_code'      => $areaCode,
        'warning'        => $warning,
        'pool_attempts'  => $pooled['attempts'],
        'pool_size'      => count($numbers),
        'pattern_score'  => $bestScore,
    ];
}

/**
 * Where a tracking number forwards to — call routing, not the number itself.
 *
 * ── CONFIRMED LIVE 2026-10-21, not guessed from CTM's docs (their public API
 * reference is a JS-rendered Postman collection, not fetchable) — matched against a
 * real third-party client library (calltracking/ctm-php) and verified end to end with
 * a real attach + real detach against this estate's own account, then reverted. ──
 *
 * `route_to` is NOT a field you can PUT on the number itself — that returns HTTP 200
 * and silently changes nothing. Routing is its own sub-resource:
 *   - attach: POST /accounts/{a}/numbers/{number_id}/receiving_numbers.json
 *             body {"number": "+1XXXXXXXXXX"} — creates a NEW receiving-number object
 *             every time, even if that phone already exists as a receiving number
 *             elsewhere on the account (tested; it does not find-and-reuse).
 *   - detach: DELETE /accounts/{a}/receiving_numbers/{receiving_number_id}.json —
 *             account-level, NOT nested under /numbers/{id}/ (that 404s).
 */
/**
 * If $phone is a bare local number (7 digits, no area code) and $areaCode is a real
 * 3-digit code, combine them before any CTM lookup. Found live 2026-10-27: a row can
 * have the correct Area Code typed into its own column while Phone holds only the
 * local 7 digits — nothing else ever concatenates the two — and ctm_number_by_phone()'s
 * mismatch guard has no way to tell "right number, just missing its area code" from
 * "wrong number entirely" on a query this short. Confirmed live that CTM's own search
 * isn't a strict match on a 7-digit query either — it returned a completely unrelated
 * number for one. Leaves anything else (already full, or not a plain 7-digit string)
 * untouched — only intervenes in this one narrow, understood case.
 */
function ctm_combine_phone_area(string $phone, string $areaCode): string
{
    $digits = preg_replace('/\D/', '', $phone);
    $areaCode = trim($areaCode);
    if (strlen($digits) === 7 && preg_match('/^\d{3}$/', $areaCode)) {
        return $areaCode . '-' . $digits;
    }
    return $phone;
}

function ctm_number_by_phone(string $accountId, string $phone): array
{
    $digits = preg_replace('/\D/', '', $phone);
    $r = ctm_request('GET', "/accounts/{$accountId}/numbers.json", ['number' => $digits, 'per_page' => 1]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $num = $r['data']['numbers'][0] ?? null;
    if (!$num) return ['ok' => false, 'error' => "CTM has no tracking number matching {$phone} under account {$accountId}."];
    // Confirm the search actually returned THIS number, not just its best guess —
    // callers (release, routing) act on the id with real, sometimes irreversible
    // consequences (ctm_release_number is a permanent DELETE), so a fuzzy/substring
    // match from CTM's own search must never be trusted silently.
    // Compare the last 10 digits only — CTM returns E.164 (+1 country code prefix,
    // 11 digits), but every $phone this is ever called with (params.csv's own
    // `phone` column) is stored without one. A straight full-string compare would
    // reject every real, correct match, not just actual mismatches.
    $gotPhone  = (string) ($num['phone_number'] ?? $num['number'] ?? '?');
    $gotDigits = substr(preg_replace('/\D/', '', $gotPhone), -10);
    if ($gotDigits !== substr($digits, -10)) {
        return ['ok' => false, 'error' => "CTM's search for {$phone} returned a different number ({$gotPhone}) — refusing to act on a mismatch."];
    }
    return ['ok' => true, 'number' => $num];
}

function ctm_add_receiving_number(string $accountId, string $numberId, string $destinationE164): array
{
    $r = ctm_request('POST', "/accounts/{$accountId}/numbers/{$numberId}/receiving_numbers.json", [], ['number' => $destinationE164]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $rpn = $r['data']['receiving_number'] ?? null;
    if (!$rpn || empty($rpn['id'])) return ['ok' => false, 'error' => 'CTM accepted the request but returned no receiving-number id.'];
    return ['ok' => true, 'receiving_number' => $rpn];
}

/** Account-level, not nested under the number — see this section's docblock. */
function ctm_remove_receiving_number(string $accountId, string $receivingNumberId): array
{
    return ctm_request('DELETE', "/accounts/{$accountId}/receiving_numbers/{$receivingNumberId}.json");
}

/**
 * The whole flow for one domain: find its tracking number by phone, drop whatever it
 * used to route to (if anything — Scott's explicit call, 2026-10-21: never ring two
 * destinations at once, always replace), then attach the new one.
 *
 * $oldReceivingId is THIS ESTATE's own record of the last receiving-number id it
 * created for this domain (stored on the row, written back by the caller) — CTM has
 * no "what does this number currently point at, by id" lookup worth trusting more
 * than what we already wrote down ourselves. A failed removal is not fatal: proceeding
 * anyway (rather than refusing) means a destination already cleared by hand in CTM
 * can't permanently block this domain from ever being re-routed again.
 */
function ctm_set_routing_for_domain(string $accountId, string $trackingPhone, string $destination, string $oldReceivingId = ''): array
{
    $found = ctm_number_by_phone($accountId, $trackingPhone);
    if (!$found['ok']) return ['ok' => false, 'error' => $found['error']];
    $numberId = $found['number']['id'];

    if ($oldReceivingId !== '') {
        ctm_remove_receiving_number($accountId, $oldReceivingId);
    }

    $destDigits = preg_replace('/\D/', '', $destination);
    if (strlen($destDigits) === 10) $destDigits = '1' . $destDigits;
    if (strlen($destDigits) !== 11) {
        return ['ok' => false, 'error' => "'{$destination}' doesn't look like a real US phone number."];
    }
    $added = ctm_add_receiving_number($accountId, $numberId, '+' . $destDigits);
    if (!$added['ok']) return ['ok' => false, 'error' => "Could not set the new destination: {$added['error']}"];

    return [
        'ok'          => true,
        'destination' => $added['receiving_number']['formatted'] ?? $destination,
        'receiving_id' => $added['receiving_number']['id'],
    ];
}

// ── "Check primary area code" — real, AI-plus-web-search verification ──────────
// A separate capability from ctm_area_code_for_city() above: that function only
// ever answers from the manual table or fleet.db's own (sometimes wrong) data —
// it cannot discover a NEW mismatch, only repeat one already found by hand. This
// does the real research every past manual audit in this file's history did by
// hand (see the big comment block above ctm_area_code_for_city()), as a button
// instead of a one-off agent dispatch. Real, billed Anthropic call per city not
// already in the cache below — never per domain, since the area code is a fact
// about the CITY, shared by every domain (any niche, any batch) in that city.

/** Fleet-wide, niche-agnostic — the real-world fact does not depend on which
 *  batch or master asked. One city checked once is never re-billed. */
function ctm_area_code_check_cache_path(): string
{
    return dirname(__DIR__) . '/admin/infra/state/area_code_checks.json';
}

function ctm_area_code_check_cache_get(string $city, string $ss): ?array
{
    $all = json_decode((string) @file_get_contents(ctm_area_code_check_cache_path()), true);
    if (!is_array($all)) return null;
    return $all[strtolower(trim($city)) . '|' . strtolower(trim($ss))] ?? null;
}

/** Read-modify-write under a lock — same reasoning as research_cities.php's
 *  cities.json lock: two batches checking area codes at the same time must not
 *  race and silently drop each other's results. */
function ctm_area_code_check_cache_set(string $city, string $ss, array $result): void
{
    require_once __DIR__ . '/multisite/batch.php';
    $path = ctm_area_code_check_cache_path();
    ms_with_launch_lock($path . '.lock', function () use ($path, $city, $ss, $result) {
        $all = json_decode((string) @file_get_contents($path), true);
        if (!is_array($all)) $all = [];
        $all[strtolower(trim($city)) . '|' . strtolower(trim($ss))] = $result + ['checked_at' => gmdate('c')];
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $path);
        }
    });
}

/** Shared by the single-city and batched lookups below — one prompt text, not two. */
function ctm_area_code_prompt(string $city, string $ss): string
{
    return "You are determining the correct US phone area code for a local business's tracking number.\n\n"
        . "City: {$city}, {$ss}\n\n"
        . "Use real web search (Wikipedia / NANPA area-code-history pages) to determine the PRIMARY/ORIGINAL "
        . "area code for this exact city — the code its own long-standing local phone numbers actually carry. "
        . "If this area underwent a geographic SPLIT at some point, use the code this specific city landed in at "
        . "the time of that split. If a newer OVERLAY code was added later on the SAME territory (not a split — "
        . "an overlay shares the same geography as its base code), the OLDER base code is primary, not the "
        . "overlay. Do not assume \"older is right\" or \"newer/suburb-sounding is right\" as a blanket rule — "
        . "it depends entirely on this specific place's real history, verified by search, not guessed from "
        . "general knowledge.\n\n"
        . "Reply with ONLY one line of JSON, nothing else, in this exact shape:\n"
        . '{"primary_code": "###", "evidence": "one sentence with what you found"}';
}

/** Turn one raw model reply into the same result shape the cache stores, or an
 *  error entry — shared by the single and batched paths. */
function ctm_area_code_parse_reply(?string $text): array
{
    if ($text === null) {
        return ['checked' => false, 'cached' => false, 'primary_code' => '', 'evidence' => '', 'error' => 'Request failed.'];
    }
    if (defined('ANTHROPIC_RATE') && $text === ANTHROPIC_RATE) {
        return ['checked' => false, 'cached' => false, 'primary_code' => '', 'evidence' => '', 'error' => 'Rate limited — try again shortly.'];
    }
    if (!preg_match('/\{.*\}/s', $text, $m) || !is_array($j = json_decode($m[0], true))) {
        return ['checked' => false, 'cached' => false, 'primary_code' => '', 'evidence' => '',
                 'error' => 'Model did not return parseable JSON: ' . substr($text, 0, 200)];
    }
    $primaryCode = preg_replace('/\D/', '', (string) ($j['primary_code'] ?? ''));
    if (strlen($primaryCode) !== 3) {
        return ['checked' => false, 'cached' => false, 'primary_code' => '', 'evidence' => '',
                 'error' => 'Model did not return a valid 3-digit code: ' . substr($m[0], 0, 200)];
    }
    return ['checked' => true, 'cached' => false, 'primary_code' => $primaryCode,
             'evidence' => trim((string) ($j['evidence'] ?? '')), 'error' => ''];
}

/**
 * Ask Claude, with real web search turned on, what the true primary/original area
 * code for $city, $ss actually is. Checks the fleet-wide cache first. Deliberately
 * does NOT take the on-file code as an input or compare against it — this
 * function has one job (what IS the real code), so the same cached answer serves
 * both the "verify an existing code" and "fill in a blank one" callers in
 * check_area_codes.php without needing two prompts or two cache shapes.
 *
 * A thin single-city wrapper over ctm_verify_area_codes_many() below — kept
 * around for callers that only ever have one city at a time.
 *
 * @return array{checked:bool, cached:bool, primary_code:string, evidence:string, error:string}
 */
function ctm_verify_area_code_via_ai(string $city, string $ss): array
{
    $many = ctm_verify_area_codes_many([['city' => $city, 'ss' => $ss]]);
    return $many[strtolower(trim($city)) . '|' . strtolower(trim($ss))]
        ?? ['checked' => false, 'cached' => false, 'primary_code' => '', 'evidence' => '', 'error' => 'Internal error — no result for this city.'];
}

/**
 * Same real lookup, batched: every cache-miss city's AI call fires CONCURRENTLY
 * (anthropic_message_many() — curl_multi under the hood) instead of one after
 * another. This is the real fix for a multi-row "Insert/verify" run being slow —
 * a brand-new city is still a genuine 10-40s web-search-backed call, but five new
 * cities now take as long as the SLOWEST one, not the sum of all five. A city
 * already in the cache costs nothing and is never sent to the API, same as the
 * single-city path — duplicate cities in $pairs (several domains, same city) are
 * also deduped before anything is billed.
 *
 * @param array<int,array{city:string,ss:string}> $pairs
 * @return array<string,array{checked:bool,cached:bool,primary_code:string,evidence:string,error:string}>
 *         keyed by "city|ss" lowercase, same key shape ctm_area_code_check_cache_get() uses.
 */
/**
 * @param ?callable $onCityDone function(string $city, string $ss, array $result): void
 *        — called the MOMENT each city resolves (cache hit: immediately, inline;
 *        real lookup: as soon as THAT city's call finishes, not after the whole
 *        batch) so a CLI caller can print live progress during what would
 *        otherwise be a silent 10-40s-per-city wait.
 */
function ctm_verify_area_codes_many(array $pairs, ?callable $onCityDone = null): array
{
    $out = []; $need = [];
    foreach ($pairs as $p) {
        $city = trim((string) ($p['city'] ?? ''));
        $ss   = trim((string) ($p['ss'] ?? ''));
        if ($city === '' || $ss === '') continue;
        $key = strtolower($city) . '|' . strtolower($ss);
        if (isset($out[$key]) || isset($need[$key])) continue;   // dedupe
        $cached = ctm_area_code_check_cache_get($city, $ss);
        if ($cached) {
            $result = ['checked' => true, 'cached' => true] + $cached;
            $out[$key] = $result;
            if ($onCityDone) $onCityDone($city, $ss, $result);
            continue;
        }
        $need[$key] = ['city' => $city, 'ss' => $ss];
    }
    if (!$need) return $out;

    require_once __DIR__ . '/anthropic.php';
    $ready = anthropic_ready();
    if (!$ready['ok']) {
        foreach ($need as $key => $p) {
            $result = ['checked' => false, 'cached' => false, 'primary_code' => '', 'evidence' => '', 'error' => $ready['error']];
            $out[$key] = $result;
            if ($onCityDone) $onCityDone($p['city'], $p['ss'], $result);
        }
        return $out;
    }

    $prompts = [];
    foreach ($need as $key => $p) $prompts[$key] = ctm_area_code_prompt($p['city'], $p['ss']);

    anthropic_message_many($prompts, [
        'model' => ANTHROPIC_SMART, 'max_tokens' => 600, 'timeout' => 120, 'concurrency' => 5,
        'web_search' => true, 'web_search_max_uses' => 3,
        'on_result' => function ($key, $text) use (&$out, $need, $onCityDone) {
            $p = $need[$key];
            $result = ctm_area_code_parse_reply($text);
            if ($result['checked']) {
                ctm_area_code_check_cache_set($p['city'], $p['ss'],
                    ['primary_code' => $result['primary_code'], 'evidence' => $result['evidence'], 'error' => '']);
            }
            $out[$key] = $result;
            if ($onCityDone) $onCityDone($p['city'], $p['ss'], $result);
        },
    ]);
    return $out;
}
