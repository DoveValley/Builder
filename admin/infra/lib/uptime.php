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
 * Resolve a hostname via a fixed external resolver (DNS-over-HTTPS, Cloudflare then
 * Google) instead of this box's own system resolver. Exists because this box's own
 * default resolver (its hosting provider's, ahead of 1.1.1.1/8.8.8.4 in
 * resolv.conf) independently lagged the rest of the internet by hours on a domain
 * whose nameservers had already switched everywhere else
 * (brooklineapplianceservice.com, 2026-11-10) — infra_site_check() kept reaching the
 * OLD host and reporting a site that was actually live as down. The question this
 * check exists to answer is "would a real visitor reach the live site", and a local
 * resolver's own cache is the wrong source of truth for that regardless of whether
 * it is stale — asking the public internet directly is the more correct check, not
 * just a workaround for this one incident.
 *
 * Best-effort: returns [] on any failure (network, timeout, malformed response)
 * rather than throwing, so a failed lookup here just falls back to the system
 * resolver — this is a safety net added on top of existing behavior, never a new
 * way for a check to fail.
 *
 * @return string[] IPv4 addresses, or [] if the external lookup didn't work
 */
function infra_dns_resolve_external(string $domain): array
{
    foreach (['https://1.1.1.1/dns-query', 'https://8.8.8.8/resolve'] as $endpoint) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint . '?name=' . urlencode($domain) . '&type=A',
            CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if (!$ok) continue;
        $data = json_decode((string) $body, true);
        $ips = [];
        foreach ($data['Answer'] ?? [] as $rec) {
            if (($rec['type'] ?? 0) === 1 && filter_var($rec['data'] ?? '', FILTER_VALIDATE_IP)) $ips[] = $rec['data'];
        }
        if ($ips) return $ips;
    }
    return [];
}

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

    // Resolved once per check, reused by every request below (HEAD/GET, cert
    // verified/not, https/http) — see infra_dns_resolve_external()'s docblock for why
    // this exists. Empty means the external lookup didn't work; curl just falls back
    // to the system resolver exactly as before this existed.
    $externalIps = infra_dns_resolve_external($domain);

    // A HEAD is enough and avoids pulling every page down. Some hosts refuse it, so a
    // 405/501 falls back to a GET rather than being reported as a broken site.
    $ask = function (string $url, bool $verify, bool $head) use ($domain, $externalIps) {
        $ch = curl_init();
        $opts = [
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
        ];
        if ($externalIps) {
            $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
            $opts[CURLOPT_RESOLVE] = array_map(fn($ip) => $domain . ':' . $port . ':' . $ip, $externalIps);
        }
        curl_setopt_array($ch, $opts);
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
