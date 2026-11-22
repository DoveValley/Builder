<?php
/**
 * Auto-detects a connected site's brand colors (accent + dark) from its real
 * homepage HTML, server-side — no headless browser, no iframe/getComputedStyle.
 * Each detector is a small, independent function tried in order; adding
 * support for a new theme or a smarter method later means adding one entry
 * to pluscities_color_detectors(), never touching the others or the caller.
 *
 * Deliberately NOT pixel-sampling a screenshot — this codebase already has a
 * real lesson on file that color-run sampling from a rendered image produces
 * false reads (anti-aliasing/photo content), so every detector here reads
 * real CSS text instead of inferring from pixels.
 */

require_once __DIR__ . '/../admin/infra/lib/http.php'; // infra_http() — shared HTTP client, per CLAUDE.md's "one module per service"

/**
 * Ordered list of detectors. Each is fn(string $html): array{accent?:string, dark?:string} —
 * returns only the keys it actually found. Tried in order; the first detector
 * to supply a given key wins, later ones never overwrite it. Add a new theme
 * or method by adding one line here.
 */
function pluscities_color_detectors(): array
{
    return [
        'meta_theme_color' => 'pluscities_color_detect_meta_theme_color',
        'wp_block_theme'   => 'pluscities_color_detect_wp_block_theme',
        'astra'            => 'pluscities_color_detect_astra',
        'kadence'          => 'pluscities_color_detect_kadence',
    ];
}

/** `<meta name="theme-color" content="#hex">` — common, zero-ambiguity when present. */
function pluscities_color_detect_meta_theme_color(string $html): array
{
    if (preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+content=["\'](#[0-9a-fA-F]{3,6})["\']/i', $html, $m)) {
        return ['accent' => $m[1]];
    }
    return [];
}

/** WP block themes (Twenty Twenty-Three/Four/Five, etc.) emit their palette as
 *  plain inline CSS custom properties — no rendering needed, direct regex. */
function pluscities_color_detect_wp_block_theme(string $html): array
{
    $out = [];
    if (preg_match('/--wp--preset--color--primary:\s*(#[0-9a-fA-F]{3,6})/i', $html, $m)) $out['accent'] = $m[1];
    if (preg_match('/--wp--preset--color--contrast:\s*(#[0-9a-fA-F]{3,6})/i', $html, $m)) $out['dark'] = $m[1];
    return $out;
}

/** Astra writes its customizer palette as --ast-global-color-N custom properties. */
function pluscities_color_detect_astra(string $html): array
{
    $out = [];
    if (preg_match('/--ast-global-color-0:\s*(#[0-9a-fA-F]{3,6})/i', $html, $m)) $out['accent'] = $m[1];
    if (preg_match('/--ast-global-color-3:\s*(#[0-9a-fA-F]{3,6})/i', $html, $m)) $out['dark'] = $m[1];
    return $out;
}

/** Kadence writes its palette as --global-palette1..9 custom properties.
 *  Verified against a real site (2026-11-22): palette1/2 are the accent
 *  pair, palette3/4 are the actual dark/contrast shades, palette7-9 are
 *  light grays/white (background, NOT dark) — confirmed by reading the real
 *  rendered values, not assumed from the variable name alone. */
function pluscities_color_detect_kadence(string $html): array
{
    $out = [];
    if (preg_match('/--global-palette1:\s*(#[0-9a-fA-F]{3,6})/i', $html, $m)) $out['accent'] = $m[1];
    if (preg_match('/--global-palette3:\s*(#[0-9a-fA-F]{3,6})/i', $html, $m)) $out['dark'] = $m[1];
    return $out;
}

/** Relative luminance (WCAG formula, simplified sRGB gamma) — used only to
 *  reject an obviously-wrong "dark" candidate (e.g. a theme's own variable
 *  naming convention turning out to mean something else, as the Kadence
 *  bug above did before it was corrected). Not a full contrast checker. */
function pluscities_color_luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (strlen($hex) !== 6) return 1.0; // malformed — treat as "light", safest default to reject
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
}

/**
 * The one public entry point. Fetches the domain's homepage once, runs every
 * detector in order, merges results (first detector to find a given key
 * wins). A key nothing found stays null — callers fall back to whatever's
 * already stored, never guess from anything but real CSS text. A "dark"
 * candidate that isn't actually dark (luminance too high — the exact failure
 * mode the Kadence detector had before the fix above) is discarded rather
 * than trusted, so a future wrong-variable guess fails safe instead of
 * silently shipping unreadable text.
 *
 * @return array{accent:?string, dark:?string, method_accent:?string, method_dark:?string}
 */
function pluscities_detect_colors(string $domain): array
{
    $url  = 'https://' . $domain . '/';
    $resp = infra_http('GET', $url, ['timeout' => 10, 'verify' => false]);
    $html = (string) ($resp['raw'] ?? '');

    $found = ['accent' => null, 'dark' => null, 'method_accent' => null, 'method_dark' => null];
    if ($html === '') return $found;

    foreach (pluscities_color_detectors() as $name => $fn) {
        $result = $fn($html);
        foreach (['accent', 'dark'] as $key) {
            if ($found[$key] !== null || empty($result[$key])) continue;
            if ($key === 'dark' && pluscities_color_luminance($result[$key]) > 0.4) continue; // not actually dark — reject
            $found[$key] = $result[$key];
            $found['method_' . $key] = $name;
        }
        if ($found['accent'] !== null && $found['dark'] !== null) break; // both found, stop early
    }

    return $found;
}

/**
 * Resolves the two colors a page should actually render with — the one
 * function pluscities_sync.php calls, so "override beats detected beats
 * fallback" lives in exactly one place. Never reaches out to the network
 * itself (that only happens via the explicit "Detect Now" action) — this is
 * a pure read of whatever's already stored on the client record.
 */
function pluscities_client_colors(array $client): array
{
    $b = $client['branding'] ?? [];
    $firstNonEmpty = function (array $candidates): ?string {
        foreach ($candidates as $c) {
            if (!empty($c)) return $c;
        }
        return null;
    };
    return [
        'accent' => $firstNonEmpty([$b['accent_override'] ?? null, $b['detected_accent'] ?? null, $b['brand_color'] ?? null]) ?? '#1C2B45',
        'dark'   => $firstNonEmpty([$b['dark_override'] ?? null, $b['detected_dark'] ?? null]) ?? '#1C2B45',
    ];
}
