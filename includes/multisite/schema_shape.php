<?php
/**
 * Schema shape variance — the same JSON-LD facts, arranged differently per site.
 *
 * Every generated page carries ~10KB of structured data, and today it is byte-identical in
 * shape across every site in a batch: same keys in the same order, same node order, same
 * indentation. That is the single largest identical block on the page.
 *
 * This reorders it. It does NOT touch a single value.
 *
 * BE CLEAR ABOUT WHAT THIS BUYS. Google parses JSON-LD into a graph and discards key order,
 * node order and whitespace entirely, so this has no SEO effect in either direction. What it
 * defeats is a byte-level fingerprint — someone diffing the raw HTML across sites, or hashing
 * the JSON-LD block to group them. Anti-fingerprint only, and it should never be described as
 * anything more.
 *
 * Deterministic per domain: the same site always gets the same arrangement, so a rebuild
 * doesn't churn.
 */

/** A stable shuffle: same seed, same order, every time. Not Fisher-Yates with rand(). */
function ms_schema_seeded_order(array $items, string $seed): array
{
    $keyed = [];
    foreach ($items as $i => $item) {
        // The index is in the hash so duplicate values still get distinct positions.
        $keyed[] = [md5($seed . '|' . $i . '|' . (is_string($item) ? $item : '')), $item];
    }
    usort($keyed, fn($a, $b) => strcmp($a[0], $b[0]));
    return array_column($keyed, 1);
}

/**
 * Reorder the keys of every object in the tree, recursively. Values are untouched, and lists
 * keep their order — a reordered `mainEntity` would reorder the FAQs as they appear to a
 * consumer, which is a content change, not a shape change.
 */
function ms_schema_reorder_keys($node, string $seed, string $path = '')
{
    if (is_array($node) && $node !== [] && array_keys($node) !== range(0, count($node) - 1)) {
        $keys = array_keys($node);
        // @context stays first. It is legal anywhere, but every parser and every human
        // expects it at the top, and there is no fingerprint value in moving one key.
        $hasContext = in_array('@context', $keys, true);
        $keys = array_values(array_filter($keys, fn($k) => $k !== '@context'));
        $keys = ms_schema_seeded_order($keys, $seed . '|keys|' . $path);
        if ($hasContext) array_unshift($keys, '@context');

        $out = [];
        foreach ($keys as $k) $out[$k] = ms_schema_reorder_keys($node[$k], $seed, $path . '/' . $k);
        return $out;
    }
    if (is_array($node)) {
        $out = [];
        foreach ($node as $i => $v) $out[$i] = ms_schema_reorder_keys($v, $seed, $path . '/*');
        return $out;
    }
    return $node;
}

/** How this domain serialises its JSON-LD. Whitespace is the biggest byte-level difference
 *  of the three and the cheapest to vary. */
function ms_schema_encode(array $data, string $domain): string
{
    $styles = [
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,                       // minified
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,                            // escaped slashes
        JSON_UNESCAPED_UNICODE,
    ];
    $pick = crc32('schema-style|' . strtolower($domain)) % count($styles);
    $json = json_encode($data, $styles[$pick]);
    // Two of the four styles are pretty-printed at PHP's fixed 4 spaces; re-indent one of them
    // to 2 so the pretty variants aren't identical to each other either.
    if ($pick === 2) {
        $json = preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    }
    return $json;
}

/**
 * Rewrite every JSON-LD block in a built site.
 * @return array{blocks:int,files:int,unchanged:int,errors:array}
 *   errors is non-empty only if a block's FACTS changed, which must never happen.
 */
function ms_schema_shape_apply(string $outputDir, string $domain): array
{
    $res = ['blocks' => 0, 'files' => 0, 'unchanged' => 0, 'errors' => []];
    if (!is_dir($outputDir)) return $res;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') continue;
        $path = $f->getPathname();
        $html = (string) @file_get_contents($path);
        $touched = false;

        $new = preg_replace_callback(
            '~(<script[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)~is',
            function ($m) use ($domain, &$res, &$touched, $path) {
                $data = json_decode(trim($m[2]), true);
                if (!is_array($data)) return $m[0];          // not JSON — leave it exactly alone
                $res['blocks']++;

                $shaped = ms_schema_reorder_keys($data, 'schema|' . strtolower($domain));
                if (isset($shaped['@graph']) && is_array($shaped['@graph'])) {
                    $shaped['@graph'] = ms_schema_seeded_order(
                        $shaped['@graph'], 'schema|' . strtolower($domain) . '|graph');
                }
                $json = ms_schema_encode($shaped, $domain);

                // The facts must be identical. Compare with keys sorted all the way down, so
                // only a real value change can trip this.
                $before = $data; $after = json_decode($json, true);
                $norm = function ($n) use (&$norm) {
                    if (is_array($n)) {
                        $out = [];
                        foreach ($n as $k => $v) $out[$k] = $norm($v);
                        if (array_keys($out) !== range(0, count($out) - 1)) ksort($out);
                        else sort($out);            // node order within @graph is what we changed
                        return $out;
                    }
                    return $n;
                };
                if ($norm($before) !== $norm($after)) {
                    $res['errors'][] = basename(dirname($path)) . ': schema values changed — not applied';
                    return $m[0];
                }
                if (trim($m[2]) === $json) { $res['unchanged']++; return $m[0]; }
                $touched = true;
                return $m[1] . $json . $m[3];
            },
            $html
        );

        if ($touched && $new !== null) { @file_put_contents($path, $new); $res['files']++; }
    }
    return $res;
}
