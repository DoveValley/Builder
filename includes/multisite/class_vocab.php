<?php
/**
 * Class vocabulary variance — the same layout and the same CSS rules under different class
 * names, so two generated sites don't share a markup signature.
 *
 * Runs on the BUILT OUTPUT, after render, as a rename pass over that site's own copy of the
 * CSS and HTML. The alternative — parallel template sets, one per vocabulary — is the
 * "infrastructure to manage infrastructure" trap the brief warns about: every future block
 * would have to be written N times.
 *
 * WHAT IT WILL NOT RENAME, and why it matters more than what it will:
 *   - any class referenced from JavaScript. The pages drive accordions, tabs, a sticky header
 *     and flip cards from inline <script>, by selector string and by classList. Renaming the
 *     class in the markup but not in the script leaves a page that looks right and does
 *     nothing. These are DETECTED per build, not hardcoded, so new JS is covered by itself.
 *   - any class with no CSS rule. Nothing to keep in sync, and it may be a hook for something
 *     outside this pass.
 *   - anything in the reserved list — framework/third-party names that may be matched by code
 *     we can't see.
 *
 * Deterministic: the same domain always produces the same names, so a rebuild does not churn.
 */

/** Class names never touched, whatever else is true. */
function ms_class_vocab_reserved(): array
{
    return ['active', 'open', 'visible', 'hidden', 'show', 'hide', 'is-open', 'is-active',
            'sr-only', 'clearfix', 'container', 'row', 'col'];
}

/**
 * Find every quoted-string span in a chunk of CSS as [start, end) byte offsets — a
 * hand-rolled scan, not a regex. A regex quote-matcher like `"(?:[^"\\]|\\.)*"` is
 * exactly the kind of pattern that exhausts PCRE's JIT stack on a large file — this
 * codebase has one: style.src.css, a debug/unminified stylesheet copy some builds
 * carry, at 140KB+. `preg_split`/`preg_replace_callback` with that pattern silently
 * returned null/false on it (confirmed — not a hypothetical), which crashed the
 * whole batch row with a TypeError. A byte-by-byte scan has no backtracking at all,
 * so it can't hit that limit regardless of input size.
 */
function ms_class_vocab_quoted_spans(string $css): array
{
    $spans = [];
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        $ch = $css[$i];
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $start = $i++;
            while ($i < $len) {
                if ($css[$i] === '\\' && $i + 1 < $len) { $i += 2; continue; }
                if ($css[$i] === $quote) { $i++; break; }
                $i++;
            }
            $spans[] = [$start, $i];
            continue;
        }
        $i++;
    }
    return $spans;
}

/**
 * Blank out every quoted string in a chunk of CSS — used purely for scanning (which
 * class names have a real rule), never for anything written back to disk, so exact
 * length doesn't matter here. A CSS class selector never appears inside a quoted
 * string, but plenty of legitimate CSS does put ".-like" substrings there:
 * url("data:...") for an SVG mask/background-image, content:"...", attribute
 * selectors [x="y.z"], font-family names. Scanning without this strip finds fake
 * "classes" like `.org` or `.w3` out of a mask URL's own `www.w3.org` — and once
 * anything named `w3`/`org` is (wrongly) in the eligible-class map, the REWRITE pass
 * corrupts that same URL by replacing those substrings, breaking the icon it draws.
 */
function ms_class_vocab_blank_quoted(string $css): string
{
    $out = '';
    $pos = 0;
    foreach (ms_class_vocab_quoted_spans($css) as [$start, $end]) {
        $out .= substr($css, $pos, $start - $pos) . str_repeat(' ', $end - $start);
        $pos = $end;
    }
    return $out . substr($css, $pos);
}

/** Every class name that has at least one CSS rule, across a site's stylesheets. */
function ms_class_vocab_css_classes(array $cssFiles): array
{
    $out = [];
    foreach ($cssFiles as $f) {
        $css = (string) @file_get_contents($f);
        // Strip comments first — a commented-out selector is not a rule.
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $css = ms_class_vocab_blank_quoted($css);
        if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $css, $m)) {
            foreach ($m[1] as $c) $out[$c] = true;
        }
    }
    return $out;
}

/** Same as above, but for CSS embedded inline in <style> blocks within HTML files —
 * e.g. theme.head_extra ("Custom head code"), which is never a standalone .css file. */
function ms_class_vocab_inline_css_classes(array $htmlFiles): array
{
    $out = [];
    foreach ($htmlFiles as $f) {
        $html = (string) @file_get_contents($f);
        if (!preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $m)) continue;
        foreach ($m[1] as $css) {
            $css = preg_replace('~/\*.*?\*/~s', '', $css);
            $css = ms_class_vocab_blank_quoted($css);
            if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $css, $mm)) {
                foreach ($mm[1] as $c) $out[$c] = true;
            }
        }
    }
    return $out;
}

/**
 * Class names any script touches. Covers the three shapes the built pages actually use:
 * a selector string ('.ts-tab'), classList.add/remove/toggle/contains('x'), and
 * className comparisons. Deliberately greedy — a false exclusion costs one unrenamed class,
 * a false inclusion costs a broken page.
 */
function ms_class_vocab_js_classes(array $htmlFiles, array $jsFiles = []): array
{
    $out = [];
    $scan = function (string $js) use (&$out) {
        if (preg_match_all('/[\'"]\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $js, $m)) {
            foreach ($m[1] as $c) $out[$c] = true;
        }
        if (preg_match_all('/classList\s*\.\s*[a-z]+\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_-]*)/', $js, $m)) {
            foreach ($m[1] as $c) $out[$c] = true;
        }
        if (preg_match_all('/className\s*[=!]==?\s*[\'"]([A-Za-z_][A-Za-z0-9_-]*)/', $js, $m)) {
            foreach ($m[1] as $c) $out[$c] = true;
        }
    };
    foreach ($htmlFiles as $f) {
        $html = (string) @file_get_contents($f);
        if (preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $html, $m)) {
            foreach ($m[1] as $js) $scan($js);
        }
    }
    foreach ($jsFiles as $f) $scan((string) @file_get_contents($f));
    return $out;
}

/**
 * The rename map for one domain: old class => new class.
 * Names are opaque and short, the way a CSS minifier's are — two sites then share zero class
 * names, which a readable per-site prefix would not achieve (a shared suffix is still a shared
 * signature). Seeded by domain + class, so it is stable across rebuilds.
 */
function ms_class_vocab_map(array $classes, string $domain): array
{
    $prefix = 'c' . substr(md5('vocab-prefix|' . strtolower($domain)), 0, 2);
    $map = $seen = [];
    foreach (array_keys($classes) as $c) {
        $n = 4;
        do {
            $new = $prefix . substr(md5('vocab|' . strtolower($domain) . '|' . $c), 0, $n);
            $n++;
        } while (isset($seen[$new]) && $n <= 12);      // collision — lengthen, never reuse
        $seen[$new] = true;
        $map[$c] = $new;
    }
    return $map;
}

/** Rewrite class names inside every class="..." attribute of an HTML string. */
function ms_class_vocab_rewrite_html(string $html, array $map): string
{
    return preg_replace_callback(
        '~\bclass\s*=\s*(["\'])(.*?)\1~is',
        function ($m) use ($map) {
            $parts = preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
            $out = array_map(fn($c) => $map[$c] ?? $c, $parts);
            return 'class=' . $m[1] . implode(' ', $out) . $m[1];
        },
        $html
    );
}

/**
 * Rewrite class SELECTORS in a stylesheet — only where a dot precedes the name, and
 * only outside a quoted string. Quoted content (url("data:...") for an SVG mask,
 * content:"...", attribute-selector values) is passed through byte-for-byte
 * unchanged — see ms_class_vocab_blank_quoted() for why: a mask URL's own
 * "www.w3.org" is not a class selector, and rewriting it corrupts the icon it draws.
 *
 * Uses ms_class_vocab_quoted_spans() (a manual scan, not a regex) to find the quoted
 * spans, then runs the simple classname regex only on the unquoted segments between
 * them. A regex-based quote-splitter (preg_split with a quote-matching pattern) was
 * tried first and confirmed to return null/false on style.src.css (a 140KB+
 * debug/unminified stylesheet copy some builds carry) — PCRE's JIT stack exhausted,
 * silently, which crashed the whole batch row with a TypeError. The classname regex
 * alone is fine at any size actually seen in this codebase; it's specifically a
 * regex tasked with finding quote boundaries in a large string that isn't.
 */
function ms_class_vocab_rewrite_css(string $css, array $map): string
{
    $rewriteChunk = fn($chunk) => preg_replace_callback(
        '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/',
        fn($m) => isset($map[$m[1]]) ? '.' . $map[$m[1]] : $m[0],
        $chunk
    ) ?? $chunk;

    $out = '';
    $pos = 0;
    foreach (ms_class_vocab_quoted_spans($css) as [$start, $end]) {
        $out .= $rewriteChunk(substr($css, $pos, $start - $pos));
        $out .= substr($css, $start, $end - $start); // quoted span — untouched
        $pos = $end;
    }
    return $out . $rewriteChunk(substr($css, $pos));
}

/**
 * Rewrite class SELECTORS inside any inline <style>...</style> blocks in an HTML string.
 * External .css files aren't the only place a class-based rule can live — a site's
 * theme.head_extra ("Custom head code") field is a whole <style> block embedded directly
 * in <head>, and theme_css_vars() emits another. Without this, ms_class_vocab_apply()
 * renames the class in every stylesheet and every class="..." attribute but leaves any
 * inline <style> block's selectors under the OLD name — the elements it targets have
 * already been renamed by ms_class_vocab_rewrite_html(), so the rule now matches nothing.
 */
function ms_class_vocab_rewrite_inline_styles(string $html, array $map): string
{
    return preg_replace_callback(
        '~(<style\b[^>]*>)(.*?)(</style>)~is',
        fn($m) => $m[1] . ms_class_vocab_rewrite_css($m[2], $map) . $m[3],
        $html
    );
}

/**
 * Apply the whole pass to one built site.
 * @return array{renamed:int,skipped_js:int,skipped_nocss:int,files:int,orphans:array}
 *   orphans = classes still in the HTML that have a CSS rule under their OLD name. Always
 *   empty when the pass is correct; non-empty means a half-rename, which is worse than none.
 */
function ms_class_vocab_apply(string $outputDir, string $domain): array
{
    $res = ['renamed' => 0, 'skipped_js' => 0, 'skipped_nocss' => 0, 'files' => 0, 'orphans' => []];
    if (!is_dir($outputDir)) return $res;

    $htmlFiles = $cssFiles = $jsFiles = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if ($ext === 'html') $htmlFiles[] = $f->getPathname();
        elseif ($ext === 'css') $cssFiles[] = $f->getPathname();
        elseif ($ext === 'js')  $jsFiles[]  = $f->getPathname();
    }
    if (!$htmlFiles || !$cssFiles) return $res;

    $cssClasses = ms_class_vocab_css_classes($cssFiles) + ms_class_vocab_inline_css_classes($htmlFiles);
    $jsClasses  = ms_class_vocab_js_classes($htmlFiles, $jsFiles);
    foreach (ms_class_vocab_reserved() as $c) $jsClasses[$c] = true;

    $eligible = [];
    foreach ($cssClasses as $c => $_) {
        if (isset($jsClasses[$c])) { $res['skipped_js']++; continue; }
        $eligible[$c] = true;
    }
    if (!$eligible) return $res;

    $map = ms_class_vocab_map($eligible, $domain);
    $res['renamed'] = count($map);

    foreach ($cssFiles as $f) {
        $css = (string) @file_get_contents($f);
        @file_put_contents($f, ms_class_vocab_rewrite_css($css, $map));
        $res['files']++;
    }
    foreach ($htmlFiles as $f) {
        $html = (string) @file_get_contents($f);
        $html = ms_class_vocab_rewrite_html($html, $map);
        $html = ms_class_vocab_rewrite_inline_styles($html, $map);
        @file_put_contents($f, $html);
        $res['files']++;
    }

    // Verify: no class left in the markup (or in an inline <style> selector) that still
    // matches an OLD name we renamed. If one survives, its rule is now under a new name
    // and the element — or the rule — has lost the other half of the pair.
    foreach ($htmlFiles as $f) {
        $html = (string) @file_get_contents($f);
        if (preg_match_all('~\bclass\s*=\s*(["\'])(.*?)\1~is', $html, $m)) {
            foreach ($m[2] as $attr) {
                foreach (preg_split('/\s+/', trim($attr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    if (isset($map[$c])) $res['orphans'][$c] = true;
                }
            }
        }
        if (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $sm)) {
            foreach ($sm[1] as $css) {
                if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $css, $cm)) {
                    foreach ($cm[1] as $c) {
                        if (isset($map[$c])) $res['orphans'][$c] = true;
                    }
                }
            }
        }
    }
    $res['orphans'] = array_keys($res['orphans']);
    return $res;
}
