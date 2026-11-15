<?php
/**
 * Landing Page Clients — best-effort branding/contact detection from a
 * client's live website, for pre-filling the kickoff wizard.
 *
 * Deliberately heuristic, not authoritative: every value returned here is a
 * starting point the wizard still shows as an editable field, never written
 * silently. Plain curl + regex/DOMDocument, same style as
 * includes/multisite/geocode.php — no new HTTP client needed for one-off
 * page scraping that isn't a real external "service" (CLAUDE.md's
 * one-module-per-API rule is about services we call repeatedly with auth,
 * not this).
 */

/**
 * @return array{ok:bool,error:?string,business_name:?string,logo_url:?string,
 *               primary_color:?string,accent_color:?string,font:?string,
 *               phone:?string,address:array{street:?string,city:?string,state:?string,zip:?string}}
 */
function lpc_detect_site_info(string $domain): array {
    $out = [
        'ok' => false, 'error' => null,
        'business_name' => null, 'logo_url' => null,
        'primary_color' => null, 'accent_color' => null, 'font' => null,
        'phone' => null,
        'address' => ['street' => null, 'city' => null, 'state' => null, 'zip' => null],
    ];

    $domain = trim($domain);
    if ($domain === '') { $out['error'] = 'No domain given.'; return $out; }
    $url = preg_match('~^https?://~i', $domain) ? $domain : "https://{$domain}";

    $html = lpc_detect_fetch($url);
    if ($html === null) { $out['error'] = 'Could not reach that site.'; return $out; }
    $out['ok'] = true;

    // wp-json is the most reliable source for the real site name when present
    // (confirmed format while testing against a real client site) — cheap
    // enough to always try, harmless if the site isn't WordPress or blocks it.
    $wpJson = lpc_detect_fetch(rtrim($url, '/') . '/wp-json/', 8);
    if ($wpJson !== null) {
        $data = json_decode($wpJson, true);
        if (is_array($data) && !empty($data['name'])) {
            $out['business_name'] = trim((string) $data['name']);
        }
    }

    $jsonLd = lpc_detect_json_ld_nodes($html);

    foreach ($jsonLd as $node) {
        $type = strtolower((string) ($node['@type'] ?? ''));
        if ($out['business_name'] === null && in_array($type, ['organization', 'localbusiness'], true) && !empty($node['name'])) {
            $out['business_name'] = trim((string) $node['name']);
        }
        if ($out['logo_url'] === null && !empty($node['logo'])) {
            $logo = $node['logo'];
            $out['logo_url'] = is_array($logo) ? ($logo['url'] ?? null) : (is_string($logo) ? $logo : null);
        }
    }
    $addr = lpc_detect_find_postal_address($jsonLd);
    if ($addr) { $out['address'] = $addr; }

    if ($out['business_name'] === null && preg_match('~<title[^>]*>([^<]+)</title>~i', $html, $m)) {
        $title = trim(html_entity_decode($m[1]));
        $title = preg_split('~\s*[\|\-–]\s*~', $title)[0] ?? $title;
        if ($title !== '') { $out['business_name'] = $title; }
    }

    if ($out['logo_url'] === null && preg_match('~<img[^>]+(?:class|alt|src)="[^"]*logo[^"]*"[^>]*src="([^"]+)"~i', $html, $m)) {
        $out['logo_url'] = lpc_detect_absolute_url($m[1], $url);
    } elseif ($out['logo_url'] !== null) {
        $out['logo_url'] = lpc_detect_absolute_url($out['logo_url'], $url);
    }

    // Kadence/full-site-editing theme.json CSS custom properties — common
    // enough across modern WP themes to be worth a dedicated pattern; no
    // generic cross-theme color-detection attempted beyond this and the
    // meta theme-color fallback, to avoid guessing from noisy inline CSS.
    // Exactly 6 hex digits only — the branding form's <input type="color">
    // silently rejects 3-digit shorthand or 8-digit alpha values.
    if (preg_match('~--global-palette1:\s*(#[0-9a-fA-F]{6})~', $html, $m)) { $out['primary_color'] = $m[1]; }
    if (preg_match('~--global-palette2:\s*(#[0-9a-fA-F]{6})~', $html, $m)) { $out['accent_color'] = $m[1]; }
    if ($out['primary_color'] === null && preg_match('~<meta[^>]+name="theme-color"[^>]+content="(#[0-9a-fA-F]{6})"~i', $html, $m)) {
        $out['primary_color'] = $m[1];
    }

    if (preg_match('~fonts\.googleapis\.com/css2?\?family=([^:&"\']+)~i', $html, $m)) {
        $out['font'] = trim(str_replace('+', ' ', urldecode($m[1])));
    } elseif (preg_match_all('~font-family:\s*[\'"]?([A-Za-z][A-Za-z0-9 ]*)[\'"]?~i', $html, $mAll)) {
        $generic = ['sans-serif', 'serif', 'monospace', 'inherit', 'initial', 'system-ui', 'ui-sans-serif', 'ui-serif'];
        foreach ($mAll[1] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && !in_array(strtolower($candidate), $generic, true)) {
                $out['font'] = $candidate;
                break;
            }
        }
    }

    if (preg_match_all('~tel:([0-9+()\-.\s]{7,20})~i', $html, $mAll)) {
        foreach ($mAll[1] as $candidate) {
            $digits = preg_replace('~\D~', '', $candidate);
            if (strlen($digits) >= 10) { $out['phone'] = trim($candidate); break; }
        }
    }

    if ($out['address']['city'] === null) {
        $text = trim(preg_replace('~\s+~', ' ', strip_tags($html)));
        if (preg_match('~Address\s*:?\s*([A-Z][a-zA-Z\s]+),\s*([A-Z]{2})\b~', $text, $m)) {
            $out['address']['city'] = trim($m[1]);
            $out['address']['state'] = trim($m[2]);
        }
    }

    return $out;
}

function lpc_detect_fetch(string $url, int $timeout = 12): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'SiteFactory-LandingPageClients/1.0 (branding auto-detect)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 400) return null;
    return $body;
}

/** Flattens every object found in every <script type="application/ld+json"> block, @graph included. */
function lpc_detect_json_ld_nodes(string $html): array {
    $nodes = [];
    if (!preg_match_all('~<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>~is', $html, $mAll)) {
        return $nodes;
    }
    foreach ($mAll[1] as $raw) {
        $data = json_decode(trim($raw), true);
        if (!is_array($data)) continue;
        $graph = $data['@graph'] ?? [$data];
        foreach ($graph as $node) {
            if (is_array($node)) $nodes[] = $node;
        }
    }
    return $nodes;
}

function lpc_detect_find_postal_address(array $nodes): ?array {
    foreach ($nodes as $node) {
        $candidate = $node['address'] ?? (strtolower((string) ($node['@type'] ?? '')) === 'postaladdress' ? $node : null);
        if (is_array($candidate) && !empty($candidate['addressLocality'])) {
            return [
                'street' => $candidate['streetAddress'] ?? null,
                'city'   => $candidate['addressLocality'] ?? null,
                'state'  => $candidate['addressRegion'] ?? null,
                'zip'    => $candidate['postalCode'] ?? null,
            ];
        }
    }
    return null;
}

function lpc_detect_absolute_url(string $maybeRelative, string $baseUrl): string {
    if (preg_match('~^https?://~i', $maybeRelative)) return $maybeRelative;
    $parts = parse_url($baseUrl);
    $root = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    return $maybeRelative[0] === '/' ? $root . $maybeRelative : $root . '/' . $maybeRelative;
}
