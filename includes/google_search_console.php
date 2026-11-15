<?php
/**
 * Google Search Console API client — one module for this service, reused
 * everywhere it's needed, per this project's own "one module per service"
 * rule. Generic (gsc_ prefixed, no lpc_ prefix) — nothing here is specific
 * to Landing Page Clients, even though that's the only caller today.
 *
 * Standing fleet-wide rule: GSC is NEVER used on niche-fleet domains (see
 * feedback_never_use_google_search_console memory — verifying many lookalike
 * -independent domains in one account would reveal common ownership). That
 * rule does not apply here: each Landing Page Client is a real, named,
 * single business on its own domain, explicitly confirmed fine for this
 * feature by Scott on 2026-11-15. Do not wire this into any niche-fleet code.
 *
 * Auth model: a single service-account JSON key (one Google Cloud identity),
 * added by each CLIENT individually as a read-only user on their own GSC
 * property — never an interactive OAuth consent flow per client. The admin
 * panel's job is just to show the service account's email so the operator
 * can tell the client what to add, then query once access is granted.
 *
 * Credentials: config/gsc_service_account.json (gitignored), the standard
 * JSON key Google Cloud Console generates for a service account.
 */

function gsc_creds_file() {
    return BASE_DIR . '/config/gsc_service_account.json';
}

function gsc_has_creds() {
    return file_exists(gsc_creds_file());
}

function gsc_service_account_email() {
    if (!gsc_has_creds()) return '';
    $creds = json_decode(file_get_contents(gsc_creds_file()), true);
    return $creds['client_email'] ?? '';
}

function gsc_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Standard Google service-account JWT bearer assertion (RFC 7523) — signed
 * locally with the service account's private key, exchanged for a short-lived
 * access token at Google's token endpoint. No interactive browser flow.
 */
function gsc_get_access_token() {
    static $cached = null;
    if ($cached && $cached['expires'] > time()) return $cached['token'];

    if (!gsc_has_creds()) return null;
    $creds = json_decode(file_get_contents(gsc_creds_file()), true);
    if (!isset($creds['private_key'], $creds['client_email'])) return null;

    $now = time();
    $header = gsc_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = gsc_base64url(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $signInput = $header . '.' . $claim;
    $signature = '';
    if (!openssl_sign($signInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
        error_log('gsc: failed to sign JWT assertion');
        return null;
    }
    $jwt = $signInput . '.' . gsc_base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $http !== 200) return null;
    $data = json_decode($body, true);
    if (empty($data['access_token'])) return null;

    $cached = ['token' => $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600) - 60];
    return $cached['token'];
}

/**
 * Clicks for one exact page URL over the last $days, via searchAnalytics.query
 * filtered to the 'page' dimension. Returns null (not 0) on any failure — the
 * caller renders null as "Not Connected", 0 as a real, verified zero.
 */
function gsc_fetch_page_clicks($propertyUrl, $pageUrl, $days = 28) {
    $token = gsc_get_access_token();
    if (!$token) return null;

    $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($propertyUrl) . '/searchAnalytics/query';
    $body = json_encode([
        'startDate' => gmdate('Y-m-d', strtotime("-$days days")),
        'endDate'   => gmdate('Y-m-d'),
        'dimensions' => ['page'],
        'dimensionFilterGroups' => [[
            'filters' => [['dimension' => 'page', 'operator' => 'equals', 'expression' => $pageUrl]],
        ]],
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http !== 200) return null;
    $data = json_decode($resp, true);
    if (!isset($data['rows'])) return 0; // valid response, genuinely zero clicks
    return (int) array_sum(array_column($data['rows'], 'clicks'));
}
