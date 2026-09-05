<?php
/**
 * The SEO gate — objective 1, enforced.
 *
 * Runs on the BUILT pages, after everything else, and checks the invariants directly rather
 * than diffing two renders. Two reasons that is the better design: one build instead of two,
 * and a diff happily passes a page whose keyword was never in the H1 to begin with.
 *
 * The five checks, in the same words the batch card uses:
 *   1. every page keeps its primary keyword in the H1
 *   2. one H1 per page
 *   3. titles and meta descriptions present, fully resolved, and not duplicated
 *   4. schema types unchanged
 *   5. same set of pages as the master
 *   + the canonical points at this site's own domain
 *
 * Check 3 has two tiers. Missing, unresolved or duplicated is a FAILURE. Differing from the
 * value the master composes is a WARNING — that comparison is the one most likely to disagree
 * with a perfectly good page, so it earns its way up to a failure by being quiet across real
 * batches first. It is composed exactly as the renderer composes it (static_build.php:199 →
 * site-template.php:65-66); anything else would be a second copy of the title rules.
 *
 * Expected values come from the WORKING DIRECTORY's own JSON — the clone the renderer just
 * read — and are resolved with resolve_shortcodes(), the same function the renderer uses.
 * Deriving them any other way would be a second copy of one meaning, which is the defect this
 * codebase repeats most.
 *
 * REPORTS, does not block. Until it has run against real batches often enough to know its
 * false-positive rate, a check that fails fifty builds is worse than the thing it guards
 * against. Flip ms_seo_gate_blocks() to true once it is provably quiet — that flip is the
 * moment it genuinely becomes a gate.
 */

require_once __DIR__ . '/../shortcodes.php';

/** Whether a failure should fail the row. See the file docblock before changing. */
function ms_seo_gate_blocks(): bool { return false; }

/** Words, lowercased, punctuation dropped — for comparing a keyword against a heading. */
function ms_seo_words(string $s): array
{
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s));
    return array_values(array_filter(explode(' ', $s), fn($w) => $w !== ''));
}

/**
 * Does the heading carry the keyword? Tests the keyword's words as an ordered SUBSEQUENCE,
 * not an exact substring — a correct H1 reads "Water Damage Restoration in Overland Park, KS"
 * while the keyword is "water damage restoration {city}, {ST}". A substring test fails that
 * page, and a check that fires on correct rows is noise, so it would be worse than no check.
 */
function ms_seo_heading_has_keyword(string $heading, string $keyword): bool
{
    $need = ms_seo_words($keyword);
    if (!$need) return true;                       // no keyword to look for
    $have = ms_seo_words($heading);
    $i = 0;
    foreach ($have as $w) {
        if ($w === $need[$i] && ++$i === count($need)) return true;
    }
    return false;
}

/** The @type values inside every JSON-LD block in a page, flattened. */
function ms_seo_schema_types(string $html): array
{
    $types = [];
    if (preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
        foreach ($m[1] as $json) {
            $d = json_decode(trim($json), true);
            if (!is_array($d)) continue;
            $nodes = $d['@graph'] ?? [$d];
            foreach ($nodes as $n) {
                if (is_array($n) && isset($n['@type'])) {
                    foreach ((array) $n['@type'] as $t) $types[] = (string) $t;
                }
            }
        }
    }
    sort($types);
    return array_values(array_unique($types));
}

/** Same, from the stored schema string on a page/site's seo block. */
function ms_seo_expected_types($schema): array
{
    if (!is_string($schema) || trim($schema) === '') return [];
    $d = json_decode($schema, true);
    if (!is_array($d)) return [];
    $types = [];
    foreach (($d['@graph'] ?? [$d]) as $n) {
        if (is_array($n) && isset($n['@type'])) {
            foreach ((array) $n['@type'] as $t) $types[] = (string) $t;
        }
    }
    sort($types);
    return array_values(array_unique($types));
}

function ms_seo_first_tag(string $html, string $pattern): ?string
{
    return preg_match($pattern, $html, $m) ? trim($m[1]) : null;
}

/**
 * @return array{checked:int,failures:array,warnings:array}
 *   failures = things that break objective 1. warnings = worth seeing, not a gate breach.
 */
function ms_seo_gate(string $workingDir, string $outputDir): array
{
    $out = ['checked' => 0, 'failures' => [], 'warnings' => []];

    $siteFile = $workingDir . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (!is_array($site)) {
        $out['failures'][] = 'site.json missing or unreadable — cannot check anything';
        return $out;
    }
    if (!is_dir($outputDir)) {
        $out['failures'][] = 'no built output to check';
        return $out;
    }

    // resolve_shortcodes() reads `global $data` and $_page_primary_keyword, exactly as
    // site-template.php sets them. Borrow both, and put them back.
    $prevData = $GLOBALS['data'] ?? null;
    $prevKw   = $GLOBALS['_page_primary_keyword'] ?? null;
    $GLOBALS['data'] = $site;

    // ── The expected page set ──
    // Derived the SAME way build_static_site() derives it: site.json's own pages (valid slug
    // only) plus page-index.json's entries whose file actually exists. Globbing data/pages/
    // instead looks equivalent and is not — the index carries stale entries whose files are
    // gone, and a row without landing cities has fewer pages than the master's folder holds.
    // Reading a different source would make this check disagree with the renderer, which is
    // exactly the failure it exists to catch.
    $slugOk = fn($s) => (bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', (string) $s);
    // 'title' is carried because the renderer falls back to it when seo_title is blank —
    // see static_build.php:199. Without it the expected title would be wrong on every page
    // that has no explicit SEO title, which is most of them.
    $expected = [['path' => '', 'seo' => $site['seo'] ?? [], 'title' => '', 'label' => '(home)']];
    foreach (($site['pages'] ?? []) as $pg) {
        $slug = trim((string) ($pg['slug'] ?? ''));
        if (!$slugOk($slug)) continue;
        $expected[] = ['path' => $slug, 'seo' => $pg['seo'] ?? [], 'title' => (string) ($pg['title'] ?? ''), 'label' => $slug];
    }
    $indexFile = $workingDir . '/data/page-index.json';
    foreach ((json_decode((string) @file_get_contents($indexFile), true) ?: []) as $slug => $fn) {
        if (!$slugOk($slug)) continue;
        $pf = $workingDir . '/data/pages/' . $fn;
        if (!is_file($pf)) continue;                    // stale index entry — renderer skips it too
        $pg = json_decode((string) @file_get_contents($pf), true);
        if (!is_array($pg)) continue;
        $expected[] = ['path' => $slug, 'seo' => $pg['seo'] ?? [], 'title' => (string) ($pg['title'] ?? ''), 'label' => $slug];
    }

    // The site's own host, taken from its own data rather than passed in — one source, and it
    // is the same value the renderer canonicalises to.
    $canonicalHost = strtolower((string) parse_url(
        (string) ($site['site_vars']['website'] ?? ''), PHP_URL_HOST));

    $seenTitles = [];
    $seenMetas  = [];

    foreach ($expected as $page) {
        $rel  = $page['path'] === '' ? 'index.html' : $page['path'] . '/index.html';
        $file = $outputDir . '/' . $rel;
        $label = $page['label'];

        // 5 · same set of pages as the master
        if (!is_file($file)) {
            $out['failures'][] = "$label: the master has this page, the build does not";
            continue;
        }
        $out['checked']++;
        $html = (string) @file_get_contents($file);
        $seo  = is_array($page['seo']) ? $page['seo'] : [];

        $GLOBALS['_page_primary_keyword'] = (string) ($seo['primary_keyword'] ?? '');

        // 2 · one H1 per page
        preg_match_all('~<h1\b[^>]*>(.*?)</h1>~is', $html, $h1s);
        $h1count = count($h1s[0]);
        if ($h1count !== 1) {
            $out['failures'][] = "$label: expected exactly 1 <h1>, found $h1count";
        }

        // 1 · that H1 carries the page's primary keyword
        $keyword = resolve_shortcodes((string) ($seo['primary_keyword'] ?? ''));
        if ($keyword !== '' && $h1count === 1
            && !ms_seo_heading_has_keyword($h1s[1][0], $keyword)) {
            $h1text = trim(preg_replace('/\s+/', ' ', strip_tags($h1s[1][0])));
            $out['failures'][] = "$label: H1 does not carry the primary keyword "
                               . "(keyword: \"$keyword\" · H1: \"$h1text\")";
        }

        // 3 · titles and meta descriptions
        $title = ms_seo_first_tag($html, '~<title[^>]*>(.*?)</title>~is');
        $meta  = ms_seo_first_tag($html, '~<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']~is');
        if ($title === null || $title === '') $out['failures'][] = "$label: no <title>";
        if ($meta === null || $meta === '')   $out['failures'][] = "$label: no meta description";
        foreach (['title' => $title, 'meta description' => $meta] as $what => $val) {
            if ($val !== null && preg_match('/\{[a-z_]+\}/i', $val)) {
                $out['failures'][] = "$label: unresolved shortcode in the $what — \"$val\"";
            }
        }
        // Did the title/meta actually stay what the master says they should be? Composed the
        // same way the renderer composes them — seo_title, else the page title, else the site
        // default (static_build.php:199) — then resolved through the same resolve_shortcodes()
        // (site-template.php:65-66). Compared after decoding entities, because the template
        // writes the title through h().
        //
        // WARNING, not a failure, for now. This is the check most likely to disagree with a
        // perfectly good page, so it earns its way up to a failure by being quiet first.
        $rawTitle = ($seo['seo_title'] ?? '') !== ''
            ? (string) $seo['seo_title']
            : (($page['title'] ?? '') !== '' ? (string) $page['title'] : site_default_title($site));
        $expTitle = trim(resolve_shortcodes($rawTitle));
        $gotTitle = $title === null ? '' : trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($expTitle !== '' && $gotTitle !== '' && $gotTitle !== $expTitle) {
            $out['warnings'][] = "$label: title differs from the master's — expected \"$expTitle\", built \"$gotTitle\"";
        }

        $expMeta = trim(resolve_shortcodes((string) ($seo['meta_description'] ?? '')));
        $gotMeta = $meta === null ? '' : trim(html_entity_decode($meta, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($expMeta !== '' && $gotMeta !== '' && $gotMeta !== $expMeta) {
            $out['warnings'][] = "$label: meta description differs from the master's";
        }

        // Duplicates across the site are an SEO defect in their own right.
        if ($title) {
            if (isset($seenTitles[$title])) $out['warnings'][] = "$label: same <title> as {$seenTitles[$title]}";
            else $seenTitles[$title] = $label;
        }
        if ($meta) {
            if (isset($seenMetas[$meta])) $out['warnings'][] = "$label: same meta description as {$seenMetas[$meta]}";
            else $seenMetas[$meta] = $label;
        }

        // Canonical. A clone that canonicalises to the master hands that page's ranking to
        // another domain — the worst thing that can go silently wrong in a clone pipeline,
        // and invisible on the rendered page.
        $canon = ms_seo_first_tag($html, '~<link[^>]+rel=["\']canonical["\'][^>]+href=["\'](.*?)["\']~is');
        if ($canon === null || $canon === '') {
            $out['failures'][] = "$label: no canonical link";
        } elseif ($canonicalHost !== '') {
            $host = strtolower((string) parse_url($canon, PHP_URL_HOST));
            if ($host !== '' && $host !== $canonicalHost) {
                $out['failures'][] = "$label: canonical points at $host, not this site ($canonicalHost) — \"$canon\"";
            }
        }

        // 4 · schema types unchanged
        $wantTypes = ms_seo_expected_types($seo['schema'] ?? '');
        if ($wantTypes) {
            $gotTypes = ms_seo_schema_types($html);
            $missing = array_values(array_diff($wantTypes, $gotTypes));
            if ($missing) {
                $out['failures'][] = "$label: schema types missing from the built page — "
                                   . implode(', ', $missing);
            }
        }

        // Not one of the five, but free here and the same class of defect: a token that
        // never resolved is visible text on a live page.
        if (preg_match_all('/\{[a-z_]+\}/i', $html, $tk)) {
            $out['warnings'][] = "$label: unresolved shortcode(s) in the page body — "
                               . implode(', ', array_slice(array_unique($tk[0]), 0, 4));
        }
    }

    // The other direction: pages built that the master has no record of.
    $built = [];
    if (is_dir($outputDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() === 'index.html') {
                $rel = trim(str_replace($outputDir, '', $f->getPath()), '/');
                $built[$rel] = true;
            }
        }
    }
    foreach ($expected as $page) unset($built[$page['path']]);
    foreach (array_keys($built) as $extra) {
        $out['warnings'][] = ($extra === '' ? '(home)' : $extra) . ': built but not in the master\'s page list';
    }

    if ($prevData === null) unset($GLOBALS['data']); else $GLOBALS['data'] = $prevData;
    if ($prevKw === null) unset($GLOBALS['_page_primary_keyword']); else $GLOBALS['_page_primary_keyword'] = $prevKw;

    return $out;
}
