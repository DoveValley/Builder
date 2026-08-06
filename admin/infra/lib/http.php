<?php
/**
 * infra/lib/http.php — minimal curl JSON client for infrastructure APIs.
 * Self-contained: no dependency on any factory code.
 */

/**
 * @param string $method GET|POST|PUT|DELETE
 * @param string $url
 * @param array  $opts   headers[], body(string|array), verify(bool), timeout(int),
 *                       cookie(string) — sent as-is; with_headers(bool) — capture the response
 * @return array{code:int,raw:string,json:mixed,error:string,cookies:array}
 */
function infra_http(string $method, string $url, array $opts = []): array
{
    $verify  = $opts['verify']  ?? true;    // secure by default; callers to self-signed origins (Plesk :8443) pass verify=false
    $timeout = $opts['timeout'] ?? 20;
    $headers = $opts['headers'] ?? [];

    // Session-auth APIs (INWX) hand back a cookie on login that must ride along on
    // every later call. Captured explicitly rather than via a cookie jar file, so
    // nothing is written to disk and concurrent requests cannot cross sessions.
    $cookies = [];
    $sink = function ($ch, string $line) use (&$cookies) {
        if (stripos($line, 'Set-Cookie:') === 0
            && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
            $cookies[trim($m[1])] = trim($m[2]);
        }
        return strlen($line);
    };

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => $sink,
    ]);
    if (!empty($opts['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    if (array_key_exists('body', $opts)) {
        $body = is_string($opts['body']) ? $opts['body'] : json_encode($opts['body']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $decoded;
    }

    return [
        'code'    => $code,
        'raw'     => is_string($raw) ? $raw : '',
        'json'    => $json,
        'error'   => $error,
        'cookies' => $cookies,
    ];
}
