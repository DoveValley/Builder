<?php
/**
 * infra/lib/uptime.php — does this website actually answer?
 *
 * Plesk can only tell you a site is configured. This asks the site itself, which is
 * the question you would otherwise open a browser to settle. Nothing here reads or
 * writes fleet state — it takes a domain and reports what came back.
 *
 * Results are cached (same TTL store as the Plesk probes) because a server with 40
 * sites on it means 40 outbound requests, and that should happen when you ask for it,
 * not on every page load.
 */
require_once __DIR__ . '/cache.php';

/** How long a check stays fresh before the page calls it stale. */
const INFRA_SITE_TTL = 900;   // 15 minutes

/**
 * Ask one website whether it is up. Read-only; makes up to three requests, stopping
 * as soon as it has an answer.
 *
 * @return array{up:bool,code:int,scheme:string,cert_ok:bool,final:string,ms:int,error:string,at:string}
 */
function infra_site_check(string $domain): array
{
    $domain = strtolower(trim(preg_replace('#^https?://#i', '', $domain), " \t/"));
    $out = ['up' => false, 'code' => 0, 'scheme' => '', 'cert_ok' => false,
            'final' => '', 'ms' => 0, 'error' => '', 'at' => gmdate('c')];
    if ($domain === '') { $out['error'] = 'no domain'; return $out; }

    // A HEAD is enough and avoids pulling every page down. Some hosts refuse it, so a
    // 405/501 falls back to a GET rather than being reported as a broken site.
    $ask = function (string $url, bool $verify, bool $head) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => $head,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'SiteFactory-InfraCheck/1.0',
        ]);
        curl_exec($ch);
        $r = [
            'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'final' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'ms'    => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
            'error' => curl_error($ch),
        ];
        curl_close($ch);
        return $r;
    };

    // 1. HTTPS with the certificate checked — the normal, healthy case.
    $r = $ask('https://' . $domain . '/', true, true);
    if (in_array($r['code'], [405, 501], true)) $r = $ask('https://' . $domain . '/', true, false);
    if ($r['code'] > 0) {
        return array_merge($out, ['up' => $r['code'] < 400, 'code' => $r['code'], 'scheme' => 'https',
                                  'cert_ok' => true, 'final' => $r['final'], 'ms' => $r['ms']]);
    }

    // 2. Same again without checking the certificate. If this works, the site is alive
    //    and the certificate is the problem — worth saying, since the two look identical
    //    from a browser tab that just shows a warning.
    $r2 = $ask('https://' . $domain . '/', false, true);
    if ($r2['code'] > 0) {
        return array_merge($out, ['up' => $r2['code'] < 400, 'code' => $r2['code'], 'scheme' => 'https',
                                  'cert_ok' => false, 'final' => $r2['final'], 'ms' => $r2['ms'],
                                  'error' => 'certificate problem: ' . $r['error']]);
    }

    // 3. Plain HTTP. Answering here but not on HTTPS is its own diagnosis.
    $r3 = $ask('http://' . $domain . '/', false, true);
    if ($r3['code'] > 0) {
        return array_merge($out, ['up' => $r3['code'] < 400, 'code' => $r3['code'], 'scheme' => 'http',
                                  'cert_ok' => false, 'final' => $r3['final'], 'ms' => $r3['ms'],
                                  'error' => 'answers on http but not https']);
    }

    return array_merge($out, ['error' => $r['error'] ?: 'no reply']);
}

/** A previous check for this domain, or null if it has never been checked (or went stale). */
function infra_site_check_cached(string $domain, int $ttl = INFRA_SITE_TTL): ?array
{
    return infra_cache_get('site:' . strtolower($domain), $ttl);
}

/** Check a domain now and remember the answer. */
function infra_site_check_run(string $domain): array
{
    $res = infra_site_check($domain);
    infra_cache_put('site:' . strtolower($domain), $res);
    return $res;
}

/** One short phrase for a result, in words rather than status codes. */
function infra_site_verdict(array $c): string
{
    if (!empty($c['up']) && !empty($c['cert_ok'])) return 'working';
    if (!empty($c['up']))                          return 'working, but ' . ($c['error'] ?: 'not properly secured');
    if (($c['code'] ?? 0) >= 400)                  return 'answering with an error';
    return 'not answering';
}
