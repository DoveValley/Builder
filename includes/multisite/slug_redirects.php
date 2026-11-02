<?php
/**
 * Fleet-wide 301s for a changed landing-page slug pattern.
 *
 * A master's slug_pattern (templates.json) is the only thing that decides a live page's
 * URL — but once a domain is built and deployed, visitors/Google already have the OLD
 * URL. Renaming slug_pattern alone 404s every one of those on the next rebuild unless
 * something also tells Apache where the old path moved to.
 *
 * sites/{masterId}/multisite/slug_redirects.json holds that "something": a flat list of
 * {from, to} pairs, each still carrying a literal {city_slug} token (same convention as
 * every other shortcode in this codebase). Most masters have no such file at all — this
 * is for the rare case a slug actually changes after going live, not routine churn.
 *
 * ms_apply_slug_redirects() resolves {city_slug} per this domain's own real landing
 * cities (the exact same values generate_city_pages() uses to build the new URLs, so a
 * redirect's "from"/"to" can never drift from what was actually built) and merges the
 * literal results into the working dir's own data/redirects.json — the file
 * static_build.php:795 already reads to write `Redirect 301` lines into .htaccess for
 * single-site builds. Reusing that existing mechanism rather than teaching
 * static_build.php a second, parallel way to write a redirect.
 */

/**
 * @param array $landingCities Rows from ms_parse_landing_cities() — same list
 *   build_one.php just used to generate this domain's actual landing pages, so the
 *   {city_slug} values here are guaranteed to match the slugs really on disk.
 * @return int number of literal redirect pairs written (0 — including when this master
 *   has no slug_redirects.json at all — is the normal case for every other niche).
 */
function ms_apply_slug_redirects(string $workingDir, string $masterId, array $landingCities): int {
    $rulesFile = BASE_DIR . '/sites/' . $masterId . '/multisite/slug_redirects.json';
    if (!is_file($rulesFile)) return 0;
    $rules = json_decode((string) file_get_contents($rulesFile), true);
    if (!is_array($rules) || !$rules) return 0;

    $redirects = [];
    foreach ($landingCities as $c) {
        $citySlug = trim((string) ($c['city_slug'] ?? ''));
        if ($citySlug === '') continue;
        foreach ($rules as $r) {
            $from = str_replace('{city_slug}', $citySlug, (string) ($r['from'] ?? ''));
            $to   = str_replace('{city_slug}', $citySlug, (string) ($r['to']   ?? ''));
            if ($from === '' || $to === '' || $from === $to) continue;
            $redirects[] = ['from' => '/' . ltrim($from, '/'), 'to' => '/' . ltrim($to, '/')];
        }
    }
    if (!$redirects) return 0;

    // Merge, don't overwrite — a future master could also carry hand-entered
    // single-site redirects in the same file, and this must not clobber those.
    $redirFile = $workingDir . '/data/redirects.json';
    $existing  = is_file($redirFile) ? json_decode((string) file_get_contents($redirFile), true) : [];
    if (!is_array($existing)) $existing = [];
    $merged = array_merge($existing, $redirects);

    file_put_contents(
        $redirFile,
        json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    return count($redirects);
}
