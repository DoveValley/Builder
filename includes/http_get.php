<?php
/**
 * One plain HTTP GET, with retry and a real User-Agent.
 *
 * Not a service client — the service clients (Anthropic, Nominatim, Wikimedia,
 * findtreatment) sit above this and own their own URLs, auth and response shapes.
 * This exists so none of them re-rolls curl, which is how they drifted: one had
 * retry with backoff, one had a 40-second timeout and no retry at all, and only
 * some identified themselves.
 *
 * A real User-Agent is not politeness. Wikimedia and Nominatim both require one and
 * will throttle or refuse anonymous clients, and that failure arrives as an empty
 * body rather than as an error saying so.
 *
 * @param array $opts ua, timeout, connect_timeout, tries, headers[], follow
 * @return array{ok:bool,body:string,code:int,error:string}
 */
function http_get(string $url, array $opts = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => '', 'code' => 0, 'error' => 'PHP cURL extension is not available.'];
    }
    $tries   = max(1, (int) ($opts['tries'] ?? 3));
    $ua      = (string) ($opts['ua'] ?? 'SiteFactory/1.0 (+static site generator)');
    $timeout = max(1, (int) ($opts['timeout'] ?? 30));
    $connect = max(1, (int) ($opts['connect_timeout'] ?? 12));
    $headers = (array) ($opts['headers'] ?? []);
    $follow  = !array_key_exists('follow', $opts) || !empty($opts['follow']);

    $code = 0; $err = '';
    for ($i = 0; $i < $tries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connect,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $code >= 200 && $code < 300) {
            return ['ok' => true, 'body' => (string) $body, 'code' => $code, 'error' => ''];
        }
        // 4xx other than 429 will not improve by asking again — only back off for
        // throttling and server-side failures.
        $worthRetrying = $code === 0 || $code === 429 || $code >= 500;
        if (!$worthRetrying || $i === $tries - 1) break;
        usleep((int) (300000 * ($i + 1)));   // 0.3s, 0.6s, …
    }
    return ['ok' => false, 'body' => '', 'code' => $code,
            'error' => $err !== '' ? $err : ('HTTP ' . $code)];
}

/** The same, decoded. Returns [] when the request failed or the body was not JSON. */
function http_get_json(string $url, array $opts = []): array
{
    $r = http_get($url, $opts + ['headers' => ['Accept: application/json']]);
    if (!$r['ok']) return [];
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : [];
}
