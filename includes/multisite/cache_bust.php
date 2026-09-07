<?php
/**
 * Fix the CSS cache-busting version on a built multisite clone.
 *
 * includes/site-template.php stamps `style.css?v=<n>` using filemtime() of the SHARED
 * project-level assets/css/style.css — the master template's own copy, which barely ever
 * changes. But the CSS actually SHIPPED for a clone is a different file: this domain's own
 * class-vocabulary-renamed copy in the output dir, rewritten by ms_class_vocab_apply() AFTER
 * the page already has its `?v=` baked in. Every clone of every domain got the SAME `?v=`
 * number regardless of its own CSS content, so a browser that cached style.css once kept
 * serving that same cached copy across every later redeploy, mismatched against whatever
 * fresh HTML the new deploy shipped — real mismatched classes/styling on a real live site,
 * not a build defect. `max-age=31536000` (one year) on that URL makes it silent and long-lived.
 *
 * Fix: after the build (assets copied, classes renamed) has produced the FINAL css/*.css,
 * stamp `?v=` with a hash of that actual file's content — same content, same value, so a
 * rebuild that changes nothing still caches correctly; any real content change gets a new
 * URL and busts the cache immediately, no manual hard-refresh required.
 */
function ms_cache_bust_apply(string $outputDir): array {
    $res = ['files' => 0, 'rewritten' => 0];
    $cssFile = rtrim($outputDir, '/') . '/assets/css/style.css';
    if (!is_file($cssFile)) return $res;
    $hash = substr(md5_file($cssFile), 0, 10);

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') continue;
        $path = $f->getPathname();
        $html = (string) @file_get_contents($path);
        $new  = preg_replace('~(assets/css/style\.css)\?v=\d+~', '$1?v=' . $hash, $html, -1, $count);
        if ($count > 0 && $new !== null) {
            @file_put_contents($path, $new);
            $res['rewritten'] += $count;
            $res['files']++;
        }
    }
    return $res;
}
