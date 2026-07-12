<?php
/**
 * Recovery plugin — static-build URL enumerator (SCAFFOLD stub).
 *
 * Deploy is a static HTML build, so we can't rely on request-time routing. This
 * walks the matrix and returns every publishable URL; the static builder renders
 * each via page.php's route_request seam and writes slug/index.html.
 *
 * PHASING LEVER: this is where growth is gated. Right now it emits the full
 * cross-product of whatever is in states/cities/carriers.json. To phase, filter
 * HERE (e.g. only cities above a population threshold, or skip city_company until a
 * state has proven indexing) — never in core. Log anything dropped.
 *
 * Returns a flat list of paths (no leading slash), e.g. "texas/houston/aetna".
 */

require_once __DIR__ . '/data.php';

function recovery_enumerate_urls(): array {
    $urls = ['insurance'];                                              // hub
    foreach (recovery_carriers() as $c) $urls[] = 'insurance/' . $c['slug'];   // company_national

    foreach (recovery_states() as $s) {
        $st = $s['slug'];
        $urls[] = $st;                                                  // state hub
        foreach (recovery_carriers() as $c) $urls[] = "$st/{$c['slug']}"; // state × company

        foreach (recovery_cities() as $ci) {
            if (($ci['state'] ?? '') !== $st) continue;
            $urls[] = "$st/{$ci['slug']}";                             // city hub
            foreach (recovery_carriers() as $c) {
                $urls[] = "$st/{$ci['slug']}/{$c['slug']}";            // city × company  << PHASE GATE
            }
        }
    }
    return $urls;
}
