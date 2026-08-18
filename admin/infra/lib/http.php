<?php
/**
 * infra/lib/http.php — minimal curl JSON client for infrastructure APIs.
 * Self-contained: no dependency on any factory code.
 */

/**
 * Outbound-call counter. Every panel/registrar/CF client goes through infra_http(),
 * so counting here counts all of them on equal terms — which is the only honest way
 * to compare what one panel costs against another to answer the same question.
 */
function infra_http_calls(): int   { return (int) ($GLOBALS['__infra_http_calls'] ?? 0); }
function infra_http_calls_reset(): void { $GLOBALS['__infra_http_calls'] = 0; }

/**
 * @param string $method GET|POST|PUT|DELETE
 * @param string $url
 * @param array  $opts   headers[], body(string|array), verify(bool), timeout(int)
 * @return array{code:int,raw:string,json:mixed,error:string}
 */
function infra_http(string $method, string $url, array $opts = []): array
{
    $GLOBALS['__infra_http_calls'] = infra_http_calls() + 1;
    $verify  = $opts['verify']  ?? true;    // secure by default; callers to self-signed origins (Plesk :8443) pass verify=false
    $timeout = $opts['timeout'] ?? 20;
    $headers = $opts['headers'] ?? [];

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
        /* EVERY OUTBOUND CALL GOES OUT OVER IPv4.
         *
         * This factory is dual-stack: 187.127.254.206 on v4, 2a02:4780:75:a6dd::1 on
         * v6. Which one a request uses is decided per destination — api.cloudflare.com
         * publishes AAAA records, so curl preferred v6 and Cloudflare saw the v6
         * address. A Cloudflare API token restricted to 187.127.254.206 then answered
         * "Cannot use the access token from location: 2a02:4780:75:a6dd::1", which reads
         * like a bad token and is not.
         *
         * The same trap is loaded for the rest of the estate: Hestia's API_ALLOWED_IP
         * and Namecheap's whitelisted IP are both the v4 address, and both work today
         * only because those hosts happen to be v4-only. The day one of them adds AAAA,
         * every call would start failing for a reason nothing in the error says.
         *
         * So the source address is pinned rather than left to resolver order: one
         * address to allowlist, everywhere, permanently. Nothing this console talks to
         * is v6-only.
         */
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
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
        'code'  => $code,
        'raw'   => is_string($raw) ? $raw : '',
        'json'  => $json,
        'error' => $error,
    ];
}

/**
 * Can anything open a TCP connection to this host:port? One layer below infra_http() —
 * no TLS, no HTTP, no credentials, so it answers a question a failed API call cannot:
 * whether the machine is there at all.
 *
 * The three outcomes are genuinely different diagnoses and must not be flattened:
 *
 *   open      something is listening and accepted the connection
 *   refused   the machine answered and said no — it is UP, nothing is on that port
 *   filtered  nothing answered at all — a firewall dropping packets, or a dead host
 *
 * "refused" is the useful one: an immediate RST proves the host is alive, which is
 * exactly what a timed-out API call leaves unknown. Sub-second, so it is cheap enough
 * to run on a failure path (and only there).
 *
 * Counted like any other outbound call — it is one, and a diagnosis that hides its
 * own cost is how sweeps quietly get expensive.
 *
 * @return array{open:bool,verdict:string,ms:int,error:string}
 */
function infra_tcp_probe(string $host, int $port, float $timeout = 4.0): array
{
    $GLOBALS['__infra_http_calls'] = infra_http_calls() + 1;
    $host = trim($host);
    if ($host === '') return ['open' => false, 'verdict' => 'filtered', 'ms' => 0, 'error' => 'no host'];

    $t0   = microtime(true);
    $errno = 0; $errstr = '';
    // @-suppressed deliberately: a refused connection is an ANSWER here, not a fault,
    // and letting it raise a warning would put "expected result" in the error log.
    $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms   = (int) round((microtime(true) - $t0) * 1000);

    if ($sock !== false) {
        fclose($sock);
        return ['open' => true, 'verdict' => 'open', 'ms' => $ms, 'error' => ''];
    }

    // ECONNREFUSED is 111 on Linux. Anything else that came back fast is still a
    // reply of some kind; only a silence that ran out the clock is 'filtered'.
    $refused = ($errno === 111) || stripos($errstr, 'refused') !== false;

    return [
        'open'    => false,
        'verdict' => $refused ? 'refused' : 'filtered',
        'ms'      => $ms,
        'error'   => trim($errstr) !== '' ? $errstr : 'no response within ' . $timeout . 's',
    ];
}
