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
            'note'    => 'Uses your existing Ahrefs subscription. Keywords Explorer costs API units, not money: '
                       . 'a request costs 50 units minimum plus one unit per row per field, and volume/difficulty/cpc '
                       . 'are all cheap fields. <strong>Keywords per request is capped by your plan</strong> — Lite 100, '
                       . 'Standard 250, Advanced 500, Enterprise unlimited. Set it too high and the API refuses the '
                       . 'whole batch, so it defaults to the safe 100. Rate limit is 60 requests a minute.',
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
        $c = infra_kw_provider($type);
        if (trim((string) ($c['api_key'] ?? '')) !== '') $out[$type] = $meta;
    }
    return $out;
}

/* --------------------------------------------------------------- keywords */

/**
 * Build the keyword for a city from a niche's template.
 * `{city}` is the city name; `{state}`/`{ss}` are available too.
 */
function infra_kw_phrase(string $template, array $city): string
{
    $t = trim($template) !== '' ? $template : '{city}';
    return trim(strtr($t, [
        '{city}'  => $city['city'] ?? '',
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
    $rowCount = count((array) ($r['json']['keywords'] ?? []));
    $truncated = $rowCount > 0 && $rowCount < count($safe);

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

/* ------------------------------------------------------------ dispatchers */

function infra_kw_quota(string $type): array
{
    $c = infra_kw_provider($type);
    if (trim((string) ($c['api_key'] ?? '')) === '') return ['ok' => false, 'msg' => 'No API key stored.', 'remaining' => null];
    if ($type === 'ahrefs') return infra_kw_ahrefs_quota($c);
    return ['ok' => false, 'msg' => 'No adapter for ' . $type . '.', 'remaining' => null];
}

function infra_kw_fetch(string $type, array $phrases): array
{
    $c = infra_kw_provider($type);
    if (trim((string) ($c['api_key'] ?? '')) === '') return ['ok' => false, 'msg' => 'No API key stored for ' . $type . '.', 'rows' => []];
    if ($type === 'ahrefs') return infra_kw_ahrefs_fetch($c, $phrases);
    return ['ok' => false, 'msg' => 'No adapter for ' . $type . '.', 'rows' => []];
}

function infra_kw_batch_size(string $type): int
{
    $c = infra_kw_provider($type);
    return max(1, min(1000, (int) ($c['batch'] ?? 100)));
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
function infra_kw_score(array $m): ?int
{
    $vol = $m['volume'] === '' ? null : (int) $m['volume'];
    $kd  = $m['kd']     === '' ? null : (int) $m['kd'];
    $cpc = $m['cpc']    === '' ? null : (float) $m['cpc'];
    if ($vol === null && $kd === null && $cpc === null) return null;

    // Demand: 0 at no searches, 1 at ~500/mo. Log scale — 50 to 100 searches is a
    // far bigger step than 900 to 1000.
    $demand = $vol === null ? 0.5 : min(1.0, log10(max(1, $vol)) / log10(500));
    // Winnability: KD 0 = 1.0, KD 50+ = near 0. These are local service terms;
    // anything above 40 is not a cheap win.
    $win    = $kd  === null ? 0.5 : max(0.0, 1 - ($kd / 50));
    // Lead value: 0 at $0, 1 at $12 a click.
    $value  = $cpc === null ? 0.5 : min(1.0, $cpc / 12);

    $score = ($demand * $win * $value) ** (1 / 3);   // geometric mean, keeps 1-10 usable
    return max(1, min(10, (int) round($score * 10)));
}

function infra_kw_score_formula(): string
{
    return 'Demand (volume, log-scaled to 500/mo) × Winnability (KD, 0 best and 50+ hopeless) '
         . '× Lead value (CPC, capped at $12) — geometric mean, scaled 1–10. '
         . 'A zero on any one of the three sinks the city, which is deliberate.';
}
