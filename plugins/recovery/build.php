<?php
/**
 * Recovery plugin — static build (matrix pages) + URL enumerator.
 *
 * Keeps ALL matrix build logic in the plugin. It REUSES the factory's static-build
 * helpers (gen_write, gen_reset_shortcode_globals) and site-template by CALLING them —
 * includes/static_build.php is never modified. The CLI runner (build_cli.php):
 *   1. empties the output dir (clean build → the factory prune is a no-op),
 *   2. calls the factory build_static_site() for the base (home, legal, assets, uploads),
 *   3. calls recovery_build_static() here for the nested matrix pages.
 */

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/pages.php';

/**
 * Every publishable matrix URL (no leading slash), honoring the phase gate in config.json:
 *   - publish_city_company=false  → skip the city × company level entirely
 *   - min_city_population > 0      → city × company only for cities at/above the threshold
 */
function recovery_enumerate_urls(): array {
    $cfg       = recovery_config();
    $publishCC = !empty($cfg['phasing']['publish_city_company']);
    $minPop    = (int) ($cfg['phasing']['min_city_population'] ?? 0);

    $urls = ['insurance'];                                              // hub
    foreach (recovery_carriers() as $c) $urls[] = 'insurance/' . $c['slug'];   // company_national

    foreach (recovery_states() as $s) {
        $st = $s['slug'];
        $urls[] = $st;                                                 // state hub
        foreach (recovery_carriers() as $c) $urls[] = "$st/{$c['slug']}"; // state × company

        foreach (recovery_cities() as $ci) {
            if (($ci['state'] ?? '') !== $st) continue;
            $urls[] = "$st/{$ci['slug']}";                            // city hub
            if ($publishCC && (int) ($ci['population'] ?? 0) >= $minPop) {
                foreach (recovery_carriers() as $c) {
                    $urls[] = "$st/{$ci['slug']}/{$c['slug']}";       // city × company (gated)
                }
            }
        }
    }
    return $urls;
}

/**
 * Coverage of the per-intersection AI layer: how many publishable state×carrier and
 * city×carrier pages have a unique bundle in ai_intersections.json vs. still falling
 * back to composed fragments. Drives the panel's "Per-page AI content" card.
 */
function recovery_intersection_coverage(): array {
    $cfg       = recovery_config();
    $publishCC = !empty($cfg['phasing']['publish_city_company']);
    $minPop    = (int) ($cfg['phasing']['min_city_population'] ?? 0);
    $ai = _recovery_load_json('ai_intersections.json');

    $keys = [];
    foreach (recovery_states() as $s) {
        foreach (recovery_carriers() as $c) $keys[] = "{$s['slug']}/{$c['slug']}";   // state × company
    }
    if ($publishCC) foreach (recovery_cities() as $ci) {
        if ((int) ($ci['population'] ?? 0) < $minPop) continue;
        foreach (recovery_carriers() as $c) $keys[] = "{$ci['state']}/{$ci['slug']}/{$c['slug']}"; // city × company
    }
    $need = count($keys); $have = 0;
    foreach ($keys as $k) if (!empty($ai[$k]['intro_html'])) $have++;
    return ['need' => $need, 'have' => $have, 'missing' => $need - $have];
}

/** Count publishable URLs by type (for the deploy manifest / preview). */
function recovery_url_breakdown(): array {
    $b = ['hub'=>0,'company_national'=>0,'state'=>0,'city'=>0,'state_company'=>0,'city_company'=>0];
    foreach (recovery_enumerate_urls() as $p) {
        $m = recovery_match_route($p);
        if ($m) $b[$m['type']]++;
    }
    return $b;
}

/** Recursive dir delete (no exec dependency) — for a clean build. */
function recovery_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

/**
 * Full gated static build: clean output → factory build_static_site() (base) → plugin
 * matrix build → complete sitemap.xml + _manifest.txt. Reused by build_cli.php and the
 * panel Build/Deploy action. Caller sets the progress sink. Returns build summary.
 */
function recovery_full_build(string $outputBase, string $canonical = 'https://r.q111.xyz'): array {
    if (!function_exists('build_static_site')) require_once BASE_DIR . '/includes/static_build.php';
    $out = rtrim($outputBase, '/') . '/';
    recovery_rrmdir(rtrim($out, '/'));           // clean → factory prune is a no-op
    @mkdir($out, 0755, true);

    $base   = build_static_site($out, $canonical, '');   // factory: home/legal/assets/uploads (UNCHANGED)
    $matrix = recovery_build_static($out, load_data());  // plugin: matrix pages

    // complete sitemap + manifest from what's actually on disk
    $locs = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getFilename() !== 'index.html') continue;
        $rel = trim(substr($f->getPath(), strlen(rtrim($out, '/'))), '/');
        $locs[] = $rel === '' ? '/' : '/' . $rel . '/';
    }
    sort($locs);
    $sm = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($locs as $l) $sm .= '  <url><loc>' . htmlspecialchars(rtrim($canonical, '/') . $l) . '</loc></url>' . "\n";
    $sm .= '</urlset>' . "\n";
    file_put_contents($out . 'sitemap.xml', $sm);

    $bd = recovery_url_breakdown();
    $man = "Recovery — build manifest\nTotal pages: " . count($locs) . "\nMatrix: " . array_sum($bd)
         . "\n" . implode("\n", array_map(fn($k, $v) => "  $k: $v", array_keys($bd), array_values($bd))) . "\n";
    file_put_contents($out . '_manifest.txt', $man);

    return ['pages' => count($locs), 'matrix' => $matrix['pages'], 'base' => $base, 'breakdown' => $bd];
}

/**
 * Render every matrix page to $outputBase/{path}/index.html, reusing the factory's
 * gen_write() (trailing-slash + upload-path rewriting) and site-template. Mirrors the
 * per-page global setup build_static_site() uses. Returns ['pages'=>int,'urls'=>[...]].
 */
function recovery_build_static(string $outputBase, array $siteData): array {
    global $data;                                    // resolve_shortcodes() reads global $data
    if (!function_exists('gen_write')) require_once BASE_DIR . '/includes/static_build.php';
    $outputBase = rtrim($outputBase, '/') . '/';

    $built = [];
    foreach (recovery_enumerate_urls() as $path) {
        $m = recovery_match_route($path);
        if ($m === null) continue;
        $routed = recovery_render_route($m, $siteData, $path);

        gen_reset_shortcode_globals();
        $data = $siteData;
        if (!empty($routed['site_vars']) && is_array($routed['site_vars'])) {
            $data['site_vars'] = array_merge($data['site_vars'] ?? [], $routed['site_vars']);
        }
        $contentBlocks   = $routed['content_blocks'] ?? [];
        $seo             = is_array($routed['seo'] ?? null) ? $routed['seo'] : [];
        $pageTitle       = !empty($seo['seo_title']) ? $seo['seo_title']
                             : (($routed['title'] ?? '') !== '' ? $routed['title'] : SITE_TITLE);
        $assetPathPrefix = '/';
        $homeUrl         = '/';
        $slug            = $path;
        $bcItems         = $routed['breadcrumbs'] ?? [['name' => 'Home', 'url' => '/']];
        $_SERVER['REQUEST_URI'] = '/' . $path;

        ob_start();
        require BASE_DIR . '/includes/site-template.php';
        $html = ob_get_clean();

        gen_write($outputBase . $path . '/index.html', $html);
        $built[] = '/' . $path . '/';
    }
    return ['pages' => count($built), 'urls' => $built];
}
