<?php
/**
 * Inject a params row's factual identity into a cloned working site (Phase 0d).
 *
 * Only site_vars + the header business name — the master supplies all structure
 * and content. AI copy (Phase 2) and per-site favicon/theme/images (Phase 5) are
 * layered in by later steps; this is deliberately just the factual identity.
 *
 * Requires slugify() (includes/helpers.php, loaded via functions.php).
 */
function inject_params_into_working_dir(string $workingDir, array $params): void {
    $file = $workingDir . '/data/site.json';
    if (!file_exists($file)) {
        throw new RuntimeException("Working site.json not found: {$file}");
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException("Working site.json is not valid JSON: {$file}");
    }

    $sv = $data['site_vars'] ?? [];

    // Direct site_vars mappings — only overwrite when the param is a non-empty string.
    // 'email' is included here too — build_one.php computes it as info@{bare-domain}
    // before this runs, so every domain gets a working address with no per-row data entry.
    foreach (['business', 'phone', 'tel', 'email', 'city', 'state', 'SS', 'zip', 'address',
              'years_in_business', 'mission_statement'] as $k) {
        if (isset($params[$k]) && is_string($params[$k]) && $params[$k] !== '') {
            $sv[$k] = $params[$k];
        }
    }

    // years_in_business / mission_statement (admin/tabs/header.php's "Trust facts" card) and
    // zip are real facts specific to the MASTER's own business/location — never anything
    // else's. The "only overwrite when non-empty" rule above means a row that doesn't supply
    // its own value left the master's actual facts (e.g. water-site's real "20" years, or its
    // real Lufkin, TX zip 75901) sitting in $sv untouched, so every domain silently inherited
    // and stated another business's — or another CITY's — real facts as its own (found on
    // baileyrestoration.com: Santa Clarita, CA's schema listing postalCode 75901, a Texas zip).
    // The about_story archetype is explicitly built to write around a blank fact rather than
    // invent one (see multisite/ai/archetypes.json); no per-city zip lookup exists at all, so
    // blank is the only correct default here too — a row without its own value must start
    // blank, not inherit a fact that belongs to a different city entirely.
    foreach (['years_in_business', 'mission_statement', 'zip'] as $k) {
        if (empty($params[$k])) $sv[$k] = '';
    }

    // A row's own 'tel' is meant to be optional: resolve_shortcodes() (includes/shortcodes.php)
    // derives {tel} from 'phone' whenever site_vars.tel is blank. But the "only overwrite when
    // non-empty" rule above means a row with a real phone and no tel column left the MASTER's
    // own tel — tied to the MASTER's phone — sitting in $sv untouched, so every domain's tel:
    // links silently kept dialing the master's number while the visible text showed the
    // domain's real one. A blank tel column means "derive it," not "inherit the master's."
    if (isset($params['phone']) && is_string($params['phone']) && $params['phone'] !== '' && empty($params['tel'])) {
        $sv['tel'] = '';
    }

    // Derived fields.
    if (!empty($params['domain'])) {
        // website is always https://{bare-domain}
        $sv['website'] = 'https://' . preg_replace('#^https?://#i', '', rtrim($params['domain'], '/'));
    }
    if (!empty($params['city'])) {
        // city + STATE, not city alone. The landing pages are slugged "{city}-{ss}"
        // (landing.php) and the masters store city_slug the same way, but this line used to
        // write city-only — so every clone's service URLs resolved to /x-lufkin/ while the
        // pages it linked to were /x-lufkin-tx/. The Services Links grid then dropped all 26
        // links as "not found", leaving every landing page orphaned from the homepage with no
        // visible error. The master was right; injection broke it on the way past.
        // ms_slug_city() is the one definition, shared with the image and landing paths.
        $sv['city_slug'] = function_exists('ms_slug_city')
            ? ms_slug_city((string) $params['city'], (string) ($params['SS'] ?? ''))
            : (function_exists('slugify') ? slugify($params['city']) : strtolower(trim($params['city'])));
    }

    $data['site_vars'] = $sv;

    // Header business name mirrors the business.
    if (!empty($params['business'])) {
        $data['header']['site_name'] = $params['business'];
    }

    // sitemap.xml's <lastmod> reads this ONE top-level field for every URL (see
    // build_static_site() in includes/static_build.php) — it never gets touched by
    // anything else in the multisite pipeline, so every domain cloned from this master
    // permanently reported the master's last hand-edit date (e.g. water-site: frozen at
    // 2026-08-25) regardless of when THAT domain was actually generated, telling Google
    // there was nothing new to recrawl on content that may be brand new. Stamp it to the
    // real build date here, once per domain build.
    $data['last_modified'] = date('Y-m-d');

    $tmp = $file . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        throw new RuntimeException("Failed to write injected site.json");
    }
    rename($tmp, $file);
}
