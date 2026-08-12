<?php
/**
 * lib/keywords.php — keyword metrics for the Cities/Niche tab.
 *
 * Pluggable on purpose, the same way lib/registrar.php is: a capability registry
 * declares what each provider can do and drives both the credentials form and
 * what the fetch button offers. Swapping provider is then a config row.
 *
 * Everything here is READ-ONLY against the provider. It spends API units (which
 * you have already paid for in a subscription); it cannot spend money.
 *
 * Credentials live in gitignored config/keywords.json, 0600, and are never
 * rendered back into a page.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/store.php';

/** Providers and what they can do. */
function infra_kw_types(): array
{
    return [
        'ahrefs' => [
            'label'  => 'Ahrefs',
            'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true],
                'country' => ['label' => 'Country', 'secret' => false, 'default' => 'us'],
                'batch'   => ['label' => 'Keywords per request', 'secret' => false, 'default' => '100'],
            ],
            'metrics' => true,
            'quota'   => true,
            'batch_max' => 500,
            'rate_per_min'    => 60,   // Ahrefs: 60 requests/minute
            'calls_per_batch' => 1,
            'note'    => 'Uses your existing Ahrefs subscription. Keywords Explorer costs API units, not money: '
                       . 'a request costs 50 units minimum plus one unit per row per field, and volume/difficulty/cpc '
                       . 'are all cheap fields. <strong>Keywords per request is capped by your plan</strong> — Lite 100, '
                       . 'Standard 250, Advanced 500, Enterprise unlimited. Set it too high and the API refuses the '
                       . 'whole batch, so it defaults to the safe 100. Rate limit is 60 requests a minute.',
        ],
        'dataforseo' => [
            'label'  => 'DataForSEO',
            'fields' => [
                'login'    => ['label' => 'API login (email)', 'secret' => false],
                'password' => ['label' => 'API password',      'secret' => true],
                'location' => ['label' => 'Location code',     'secret' => false, 'default' => '2840'],
                'language' => ['label' => 'Language',          'secret' => false, 'default' => 'en'],
                'batch'    => ['label' => 'Keywords per request', 'secret' => false, 'default' => '1000'],
            ],
            'metrics' => true,
            'quota'   => true,
            'batch_max' => 1000,
            'rate_per_min'    => 12,   // DataForSEO: 12 requests/minute on Live endpoints
            'calls_per_batch' => 2,    // volume, then difficulty
            'note'    => 'Pay-as-you-go, priced in cents rather than subscription units — the whole 10,000-city × 8-niche '
                       . 'sweep lands around $8, which is what makes a wide sweep possible at all. '
                       . '<strong>Each fetch is two calls</strong>: Google Ads search volume (the Keyword Planner source) '
                       . 'for volume and CPC, then Labs bulk keyword difficulty for KD — so a batch that half-fails can '
                       . 'return volume without KD, and does. 1,000 keywords per request, 12 requests a minute. '
                       . 'Location code 2840 is the United States. <strong>Test reports your account balance in dollars</strong>; '
                       . 'unlike Ahrefs, running out here means real money ran out.',
        ],
    ];
}

function infra_kw_config_path(): string { return infra_config_path('keywords.json'); }

function infra_kw_config(): array
{
    $cfg = infra_load_json(infra_kw_config_path(), []);
    return is_array($cfg['providers'] ?? null) ? $cfg['providers'] : [];
}

function infra_kw_provider(string $type): array
{
    return infra_kw_config()[$type] ?? [];
}

function infra_kw_configured(): array
{
    $out = [];
    foreach (infra_kw_types() as $type => $meta) {
        if (infra_kw_has_creds($type)) $out[$type] = $meta;
    }
    return $out;
}

/* --------------------------------------------------------------- keywords */

/**
 * Build the keyword for a city from a niche's template.
 * `{city}` is the city name; `{state}`/`{ss}` are available too.
 */
/**
 * The searchable form of a Census place name.
 *
 * Census names are legal, not colloquial, and ten of the ten thousand carry
 * punctuation that is both unsearchable and — in DataForSEO's case — rejected
 * outright, taking the whole batch of 1,000 with it.
 *
 *   San Buenaventura (Ventura)      → Ventura
 *   El Paso de Robles (Paso Robles) → Paso Robles
 *   Louisville/Jefferson County     → Louisville
 *   Islamorada, Village of Islands  → Islamorada
 *
 * A parenthetical is the name people actually use, so it WINS rather than being
 * stripped; nobody searches "appliance repair San Buenaventura". Text after a
 * comma or slash is the administrative tail and is dropped. The stored city name
 * is left untouched — this is only how it is asked about.
 */
function infra_kw_city_name(array $city): string
{
    $n = trim((string) ($city['city'] ?? ''));
    if (preg_match('/\(([^)]+)\)/u', $n, $m) && trim($m[1]) !== '') {
        $n = trim($m[1]);                       // the common name in brackets
    }
    $n = preg_split('#[,/]#u', $n)[0];          // drop ", Moore County" / "/Jefferson County"
    $n = preg_replace('/[^\p{L}\p{N} \'.\-]+/u', ' ', $n);   // control chars and stray symbols
    return trim(preg_replace('/\s+/u', ' ', $n));
}

function infra_kw_phrase(string $template, array $city): string
{
    $t = trim($template) !== '' ? $template : '{city}';
    return trim(strtr($t, [
        '{city}'  => infra_kw_city_name($city),
        '{state}' => $city['state'] ?? '',
        '{ss}'    => $city['ss'] ?? '',
    ]));
}

/* ----------------------------------------------------------------- ahrefs */

function infra_kw_ahrefs_headers(array $c): array
{
    return ['Authorization: Bearer ' . trim((string) ($c['api_key'] ?? '')), 'Accept: application/json'];
}

/**
 * Remaining API units. This endpoint is free — it consumes no units — which is
 * why it is safe to use as the credentials test.
 * @return array{ok:bool,msg:string,remaining:?int}
 */
function infra_kw_ahrefs_quota(array $c): array
{
    $r = infra_http('GET', 'https://api.ahrefs.com/v3/subscription-info/limits-and-usage',
        ['headers' => infra_kw_ahrefs_headers($c), 'timeout' => 25]);

    if ($r['error'] !== '')                 return ['ok' => false, 'msg' => 'Network error: ' . $r['error'], 'remaining' => null];
    if ($r['code'] === 401 || $r['code'] === 403) return ['ok' => false, 'msg' => 'Ahrefs rejected the API key (HTTP ' . $r['code'] . ').', 'remaining' => null];
    if ($r['code'] !== 200 || !is_array($r['json'])) return ['ok' => false, 'msg' => 'Unexpected reply (HTTP ' . $r['code'] . '): ' . substr($r['raw'], 0, 200), 'remaining' => null];

    $j     = $r['json']['limits_and_usage'] ?? $r['json'];
    $limit = $j['units_limit_workspace']   ?? null;
    $used  = $j['units_usage_workspace']   ?? null;
    $reset = $j['usage_reset_date']        ?? '';

    // A null limit means unlimited, which is not the same as zero left.
    if ($limit === null) {
        return ['ok' => true, 'remaining' => null,
                'msg' => 'Key works. Units: unlimited on this workspace' . ($used !== null ? ', ' . number_format((int) $used) . ' used this period' : '') . '.'];
    }
    $left = max(0, (int) $limit - (int) $used);
    return ['ok' => true, 'remaining' => $left,
            'msg' => 'Key works. ' . number_format($left) . ' of ' . number_format((int) $limit)
                   . ' API units left this period' . ($reset ? ', resets ' . substr((string) $reset, 0, 10) : '') . '.'];
}

/**
 * Fetch volume / difficulty / cpc for a batch of keyword phrases.
 *
 * @return array{ok:bool,msg:string,rows:array<string,array>}  rows keyed by lowercased keyword
 */
function infra_kw_ahrefs_fetch(array $c, array $phrases): array
{
    $phrases = array_values(array_filter(array_map('trim', $phrases)));
    if (!$phrases) return ['ok' => true, 'msg' => '', 'rows' => []];

    // Keywords go over as ONE comma-separated parameter, so a phrase containing a
    // comma would silently split into two. None of ours do; drop any that would.
    $safe = [];
    foreach ($phrases as $p) if (strpos($p, ',') === false) $safe[] = $p;

    $url = 'https://api.ahrefs.com/v3/keywords-explorer/overview?' . http_build_query([
        'country'  => strtolower(trim((string) ($c['country'] ?? 'us'))) ?: 'us',
        'select'   => 'keyword,volume,difficulty,cpc',
        'keywords' => implode(',', $safe),
    ]);
    $r = infra_http('GET', $url, ['headers' => infra_kw_ahrefs_headers($c), 'timeout' => 60]);

    if ($r['error'] !== '')  return ['ok' => false, 'msg' => 'Network error: ' . $r['error'], 'rows' => []];
    if ($r['code'] === 429)  return ['ok' => false, 'msg' => 'Rate limited by Ahrefs (60 requests/minute). Nothing lost — run it again.', 'rows' => []];
    if ($r['code'] !== 200)  return ['ok' => false, 'msg' => 'Ahrefs returned HTTP ' . $r['code'] . ': ' . substr($r['raw'], 0, 250), 'rows' => []];

    // Ask for more keywords than the plan's row cap and Ahrefs answers 200 OK with
    // the response SILENTLY TRUNCATED — 200 keywords in, 100 rows back. Measured on
    // a Lite key. Without this check the missing half reads as "these cities have no
    // search volume", which is a far worse lie than an error would have been.
    //
    // But fewer rows than keywords is NORMAL: Ahrefs omits keywords it has no data
    // for, so 84 in and 83 back means one city has no measurable volume. Treating
    // that as truncation aborted a 500-city run over a single quiet town. Real
    // truncation lands exactly on a plan cap, so that is what is tested for.
    $rowCount  = count((array) ($r['json']['keywords'] ?? []));
    $truncated = $rowCount < count($safe) && in_array($rowCount, [100, 250, 500], true);

    $out = [];
    foreach ((array) ($r['json']['keywords'] ?? []) as $k) {
        $kw = strtolower(trim((string) ($k['keyword'] ?? '')));
        if ($kw === '') continue;
        $out[$kw] = [
            // CPC arrives in US CENTS. Storing it raw would show a $12 click as 1200
            // — the same shape of mistake as Porkbun wanting its price in cents.
            'volume' => isset($k['volume'])     ? (string) (int) $k['volume'] : '',
            'kd'     => isset($k['difficulty']) ? (string) (int) $k['difficulty'] : '',
            'cpc'    => isset($k['cpc']) && $k['cpc'] !== null ? number_format(((int) $k['cpc']) / 100, 2, '.', '') : '',
        ];
    }
    if ($truncated) {
        return ['ok' => false, 'rows' => $out, 'msg' =>
            'Ahrefs returned only ' . $rowCount . ' rows for ' . count($safe) . ' keywords — your plan caps rows per '
          . 'request and it truncates silently rather than erroring. Lower "Keywords per request" to ' . $rowCount
          . ' or less (Lite 100, Standard 250, Advanced 500). The ' . $rowCount . ' rows it did return were kept.'];
    }
    return ['ok' => true, 'msg' => '', 'rows' => $out];
}

/* ------------------------------------------------------------ dataforseo */

function infra_kw_dfs_headers(array $c): array
{
    $login = trim((string) ($c['login'] ?? ''));
    $pass  = trim((string) ($c['password'] ?? ''));

    // DataForSEO's API-access page shows a ready-made base64 "login:password"
    // string next to the password itself, so pasting that into the password box
    // is an easy and reasonable mistake — and it fails as a flat 401 that reads
    // like wrong credentials. If the value decodes to exactly "<this login>:...",
    // it IS the credential pair; use it rather than base64-ing it a second time.
    $decoded = base64_decode($pass, true);
    if ($decoded !== false && $login !== '' && strncmp($decoded, $login . ':', strlen($login) + 1) === 0) {
        return ['Authorization: Basic ' . $pass, 'Content-Type: application/json'];
    }
    return ['Authorization: Basic ' . base64_encode($login . ':' . $pass), 'Content-Type: application/json'];
}

/** One POST to a DataForSEO live endpoint, with its two layers of status code unwrapped. */
function infra_kw_dfs_post(array $c, string $path, array $task): array
{
    $r = infra_http('POST', 'https://api.dataforseo.com/v3/' . ltrim($path, '/'),
        ['headers' => infra_kw_dfs_headers($c), 'body' => json_encode([$task]), 'timeout' => 120]);

    if ($r['error'] !== '')  return ['ok' => false, 'msg' => 'Network error: ' . $r['error'], 'result' => []];
    if ($r['code'] === 401)  return ['ok' => false, 'msg' => 'DataForSEO rejected the login/password.', 'result' => []];

    // 40104 is worth naming: the credentials are RIGHT and the balance reads fine,
    // but the data endpoints stay shut until the account is verified. Reported as a
    // bare HTTP error it looks like a broken key, which sends you fixing the wrong thing.
    if ((int) ($r['json']['status_code'] ?? 0) === 40104) {
        return ['ok' => false, 'result' => [], 'msg' =>
            'DataForSEO needs the account verified before it will serve data — the login and balance are fine. '
          . 'Complete verification at https://app.dataforseo.com/ and try again.'];
    }
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'msg' => 'HTTP ' . $r['code'] . ': '
            . trim(preg_replace('/\s+/', ' ', substr($r['raw'], 0, 400))), 'result' => []];
    }
    // DataForSEO answers HTTP 200 for its own errors and puts the truth in
    // status_code — twice, once for the request and once per task. 20000 is OK.
    if ((int) ($r['json']['status_code'] ?? 0) !== 20000) {
        return ['ok' => false, 'msg' => 'DataForSEO: ' . ($r['json']['status_message'] ?? 'unknown error'), 'result' => []];
    }
    $t = $r['json']['tasks'][0] ?? [];
    if ((int) ($t['status_code'] ?? 0) !== 20000) {
        return ['ok' => false, 'msg' => 'DataForSEO task: ' . ($t['status_message'] ?? 'unknown error'), 'result' => []];
    }
    return ['ok' => true, 'msg' => '', 'result' => (array) ($t['result'] ?? [])];
}

/** @return array{ok:bool,msg:string,remaining:?int} — remaining is dollars, rounded down. */
function infra_kw_dfs_quota(array $c): array
{
    $r = infra_http('GET', 'https://api.dataforseo.com/v3/appendix/user_data',
        ['headers' => infra_kw_dfs_headers($c), 'timeout' => 30]);

    if ($r['error'] !== '') return ['ok' => false, 'msg' => 'Network error: ' . $r['error'], 'remaining' => null];
    if ($r['code'] === 401) return ['ok' => false, 'msg' => 'DataForSEO rejected the login/password.', 'remaining' => null];
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'msg' => 'HTTP ' . $r['code'] . ': ' . substr($r['raw'], 0, 200), 'remaining' => null];
    }
    $res = $r['json']['tasks'][0]['result'][0] ?? [];
    $bal = $res['money']['balance'] ?? null;
    if ($bal === null) return ['ok' => true, 'msg' => 'Login works, but no balance was reported.', 'remaining' => null];

    $bal = (float) $bal;
    // Volume+KD runs about $0.10 per 1,000 keywords across the two calls.
    $kw  = (int) floor($bal / 0.10 * 1000);
    return ['ok' => true, 'remaining' => (int) floor($bal),
            'msg' => 'Login works. Balance $' . number_format($bal, 2)
                   . ' — roughly ' . number_format($kw) . ' keywords at about $0.10 per thousand.'
                   . ($bal < 1 ? ' That is low: a wide sweep will stop partway.' : '')];
}

/**
 * Volume + CPC from Google Ads, KD from Labs — two calls, merged.
 *
 * If the KD call fails the volume figures are still returned, because they were
 * already paid for and half the picture beats none. The caller sees the warning.
 */
function infra_kw_dfs_fetch(array $c, array $phrases): array
{
    $phrases = array_values(array_filter(array_map('trim', $phrases)));
    if (!$phrases) return ['ok' => true, 'msg' => '', 'rows' => []];

    $loc  = (int) ($c['location'] ?? 2840) ?: 2840;
    $lang = trim((string) ($c['language'] ?? 'en')) ?: 'en';
    $task = ['keywords' => array_values($phrases), 'location_code' => $loc, 'language_code' => $lang];

    // DataForSEO rejects the WHOLE batch over one unacceptable keyword, naming it
    // in the error. A thousand cities must not be lost to one odd place name, so
    // the named keyword is dropped and the batch retried. Bounded, because a loop
    // that keeps retrying on a error it cannot parse would spend money forever.
    $dropped = [];
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $vol = infra_kw_dfs_post($c, 'keywords_data/google_ads/search_volume/live', $task);
        if ($vol['ok']) break;
        if (!preg_match("/invalid characters or symbols: '(.+?)'/i", $vol['msg'], $m)) break;
        $bad = $m[1];
        $task['keywords'] = array_values(array_filter($task['keywords'], fn($k) => $k !== $bad));
        $dropped[] = $bad;
        if (!$task['keywords']) break;
    }
    if (!$vol['ok']) return ['ok' => false, 'msg' => $vol['msg'], 'rows' => []];
    $phrases = $task['keywords'];

    $out = [];
    foreach ($vol['result'] as $item) {
        $kw = strtolower(trim((string) ($item['keyword'] ?? '')));
        if ($kw === '') continue;
        $out[$kw] = [
            'volume' => isset($item['search_volume']) && $item['search_volume'] !== null ? (string) (int) $item['search_volume'] : '',
            'kd'     => '',
            // CPC here is already dollars, NOT cents like Ahrefs. Do not divide.
            'cpc'    => isset($item['cpc']) && $item['cpc'] !== null ? number_format((float) $item['cpc'], 2, '.', '') : '',
        ];
    }

    $kdr = infra_kw_dfs_post($c, 'dataforseo_labs/google/bulk_keyword_difficulty/live', $task);
    if (!$kdr['ok']) {
        return ['ok' => false, 'rows' => $out,
                'msg' => 'Volume and CPC came back, but keyword difficulty did not: ' . $kdr['msg']
                       . ' The volume figures were kept; re-run to fill KD in.'];
    }
    foreach ((array) ($kdr['result'][0]['items'] ?? []) as $item) {
        $kw = strtolower(trim((string) ($item['keyword'] ?? '')));
        if ($kw === '' || !isset($out[$kw])) continue;
        $out[$kw]['kd'] = isset($item['keyword_difficulty']) && $item['keyword_difficulty'] !== null
            ? (string) (int) $item['keyword_difficulty'] : '';
    }
    return ['ok' => true, 'rows' => $out, 'msg' => $dropped
        ? 'DataForSEO refused ' . count($dropped) . ' keyword(s) as unsearchable and they were skipped: '
          . implode('; ', array_slice($dropped, 0, 3)) . (count($dropped) > 3 ? ' …' : '')
        : ''];
}

/* ------------------------------------------------------------ dispatchers */

/** Is this provider usable — i.e. are its non-optional credentials filled in? */
function infra_kw_has_creds(string $type): bool
{
    $c = infra_kw_provider($type);
    if ($type === 'ahrefs')     return trim((string) ($c['api_key'] ?? '')) !== '';
    if ($type === 'dataforseo') return trim((string) ($c['login'] ?? '')) !== '' && trim((string) ($c['password'] ?? '')) !== '';
    return false;
}

function infra_kw_quota(string $type): array
{
    if (!infra_kw_has_creds($type)) return ['ok' => false, 'msg' => 'No credentials stored.', 'remaining' => null];
    $c = infra_kw_provider($type);
    if ($type === 'ahrefs')     return infra_kw_ahrefs_quota($c);
    if ($type === 'dataforseo') return infra_kw_dfs_quota($c);
    return ['ok' => false, 'msg' => 'No adapter for ' . $type . '.', 'remaining' => null];
}

function infra_kw_fetch(string $type, array $phrases): array
{
    if (!infra_kw_has_creds($type)) return ['ok' => false, 'msg' => 'No credentials stored for ' . $type . '.', 'rows' => []];
    $c = infra_kw_provider($type);
    if ($type === 'ahrefs')     return infra_kw_ahrefs_fetch($c, $phrases);
    if ($type === 'dataforseo') return infra_kw_dfs_fetch($c, $phrases);
    return ['ok' => false, 'msg' => 'No adapter for ' . $type . '.', 'rows' => []];
}

function infra_kw_batch_size(string $type): int
{
    $c   = infra_kw_provider($type);
    $max = (int) (infra_kw_types()[$type]['batch_max'] ?? 1000);
    $def = (int) (infra_kw_types()[$type]['fields']['batch']['default'] ?? 100);
    return max(1, min($max, (int) ($c['batch'] ?? $def) ?: $def));
}

/* ------------------------------------------------------------------ score */

/**
 * Score a city 1-10 from its metrics.
 *
 * Demand x Winnability x Lead value, each normalised to 0-1 and multiplied, so a
 * zero on any one of them sinks the city — which is the intent: volume with no
 * commercial value, or a valuable term you cannot rank for, are both no good.
 *
 * Returns null when there is not enough to judge; the caller leaves the score
 * alone rather than inventing one.
 */
/**
 * Where each provider's scale tops out. Measured, not assumed — on the same eight
 * cities, DataForSEO reported roughly 3x the volume, about 2x the CPC, and a
 * difficulty of 23 where Ahrefs said 50. Feeding its numbers through the Ahrefs
 * curve scored every city 8-10, which ranks nothing.
 *
 * These are calibrated from a small sample and are meant to be tuned.
 */
function infra_kw_calibration(string $type): array
{
    $cal = [
        'ahrefs'     => ['vol' => 500,  'kd' => 50, 'cpc' => 12.0],
        'dataforseo' => ['vol' => 1500, 'kd' => 25, 'cpc' => 24.0],
    ];
    return $cal[$type] ?? $cal['ahrefs'];
}

function infra_kw_score(array $m, string $type = 'ahrefs'): ?int
{
    $vol = ($m['volume'] ?? '') === '' ? null : (int) $m['volume'];
    $kd  = ($m['kd']     ?? '') === '' ? null : (int) $m['kd'];
    $cpc = ($m['cpc']    ?? '') === '' ? null : (float) $m['cpc'];
    if ($vol === null && $kd === null && $cpc === null) return null;
    $c = infra_kw_calibration($type);

    // Demand: 0 at no searches, 1 at the provider's ceiling. Log scale — 50 to 100
    // searches is a far bigger step than 900 to 1000.
    $demand = $vol === null ? 0.5 : min(1.0, log10(max(1, $vol)) / log10($c['vol']));
    // Winnability: KD 0 = 1.0, the provider's hopeless mark = 0.
    $win    = $kd  === null ? 0.5 : max(0.0, 1 - ($kd / $c['kd']));
    // Lead value: 0 at $0, 1 at the provider's ceiling.
    $value  = $cpc === null ? 0.5 : min(1.0, $cpc / $c['cpc']);

    $score = ($demand * $win * $value) ** (1 / 3);   // geometric mean, keeps 1-10 usable
    return max(1, min(10, (int) round($score * 10)));
}

function infra_kw_score_formula(string $type = 'ahrefs'): string
{
    $c = infra_kw_calibration($type);
    return 'Demand (volume, log-scaled to ' . $c['vol'] . '/mo) × Winnability (KD, 0 best and '
         . $c['kd'] . '+ hopeless) × Lead value (CPC, capped at $' . number_format($c['cpc'], 0) . ') '
         . '— geometric mean, scaled 1–10. A zero on any one of the three sinks the city, which is deliberate. '
         . 'The ceilings differ per provider because their scales do: on the same cities DataForSEO reported '
         . 'about 3× the volume, 2× the CPC, and difficulty 23 where Ahrefs said 50.';
}
