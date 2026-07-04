<?php
/**
 * Per-site hero text overlay — multisite item 4c.
 *
 * Bakes two lines (line 1 = page keyword, line 2 = "City, ST") onto each page's
 * hero image, so every generated site gets a genuinely different hero file
 * (defeats duplicate-image detection). Deterministic per domain; format-preserving.
 *
 * ms_hero_overlay_render() is the shared render core — the admin Test Lab
 * (admin/hero_overlay.php) uses it too, so the preview matches production.
 *
 * Called from multisite/build_one.php after AI generation, before the static build.
 */

/** Locate the ImageMagick binary by absolute path (the web SAPI's exec PATH is minimal). */
function ms_convert_bin(): ?string {
    static $bin = false;
    if ($bin !== false) return $bin;
    foreach (['/usr/bin/convert', '/usr/local/bin/convert', '/bin/convert'] as $c) {
        if (@is_executable($c)) return $bin = $c;
    }
    $found = trim((string)@shell_exec('command -v convert 2>/dev/null'));
    return $bin = ($found !== '' ? $found : null);
}

/**
 * Render up to 3 text lines onto $src → $out (output format taken from $out's
 * extension). Returns ['ok'=>bool, 'error'=>string, 'cmd'=>string].
 * $o keys: line1,line2,line3, pos(bl|bc|tl), c1,c2, s1,s2, scrim, font, W,H.
 */
function ms_hero_overlay_render(string $src, string $out, array $o): array {
    $bin = ms_convert_bin();
    if ($bin === null) return ['ok' => false, 'error' => 'ImageMagick (convert) not found', 'cmd' => ''];

    $font = $o['font'] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $pos  = in_array($o['pos'] ?? 'bl', ['bl', 'bc', 'tl'], true) ? $o['pos'] : 'bl';
    $c1   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($o['c1'] ?? '')) ? $o['c1'] : '#ffffff';
    $c2   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($o['c2'] ?? '')) ? $o['c2'] : '#ffffff';
    $s1   = max(8, (int)($o['s1'] ?? 44));
    $s2   = max(8, (int)($o['s2'] ?? 40));

    $W = (int)($o['W'] ?? 0); $H = (int)($o['H'] ?? 0);
    if ($W < 1 || $H < 1) {
        $g = @getimagesize($src);
        if (!$g) return ['ok' => false, 'error' => 'source not a readable image', 'cmd' => ''];
        $W = (int)$g[0]; $H = (int)$g[1];
    }
    $scrim = max(0, min($H, (int)($o['scrim'] ?? round($H * 0.5))));

    $lines = [];
    if (($o['line1'] ?? '') !== '') $lines[] = ['t' => (string)$o['line1'], 's' => $s1, 'c' => $c1];
    if (($o['line2'] ?? '') !== '') $lines[] = ['t' => (string)$o['line2'], 's' => $s2, 'c' => $c2];
    if (($o['line3'] ?? '') !== '') $lines[] = ['t' => (string)$o['line3'], 's' => $s2, 'c' => $c2];
    if (!$lines) return ['ok' => false, 'error' => 'no text to render', 'cmd' => ''];

    $pad = max(12, (int)round($W * 0.03));
    $gap = (int)round($s2 * 0.30);
    if ($pos === 'tl')      { $grav = 'northwest'; $sg = 'north'; $grad = 'gradient:black-none'; $top = true;  $x = $pad; }
    elseif ($pos === 'bc')  { $grav = 'south';     $sg = 'south'; $grad = 'gradient:none-black'; $top = false; $x = 0;    }
    else                    { $grav = 'southwest'; $sg = 'south'; $grad = 'gradient:none-black'; $top = false; $x = $pad; }

    $n = count($lines); $y = array_fill(0, $n, $pad);
    if ($top) { $yy = $pad; for ($i = 0; $i < $n; $i++) { $y[$i] = $yy; $yy += $lines[$i]['s'] + $gap; } }
    else      { $yy = $pad; for ($i = $n - 1; $i >= 0; $i--) { $y[$i] = $yy; $yy += $lines[$i]['s'] + $gap; } }

    $cmd = [$bin, $src, '-strip'];   // -strip: no metadata → byte-reproducible rebuilds
    if ($scrim > 0) $cmd = array_merge($cmd, ['(', '-size', "{$W}x{$scrim}", $grad, ')', '-gravity', $sg, '-composite']);
    $cmd = array_merge($cmd, ['-font', $font, '-gravity', $grav]);
    foreach ($lines as $i => $ln) {
        $at = '+' . $x . '+' . $y[$i];
        $cmd = array_merge($cmd, [
            '-pointsize', (string)$ln['s'],
            '-strokewidth', '3', '-stroke', 'rgba(0,0,0,0.55)', '-fill', 'rgba(0,0,0,0.55)', '-annotate', $at, $ln['t'],
            '-strokewidth', '0', '-stroke', 'none', '-fill', $ln['c'], '-annotate', $at, $ln['t'],
        ]);
    }
    $cmd[] = $out;

    $shell = implode(' ', array_map('escapeshellarg', $cmd));
    exec($shell . ' 2>&1', $outp, $rc);
    if ($rc !== 0 || !is_file($out) || filesize($out) < 1) {
        return ['ok' => false, 'error' => implode("\n", array_slice($outp, 0, 5)), 'cmd' => $shell];
    }
    return ['ok' => true, 'error' => '', 'cmd' => $shell];
}

/** The primary hero image field of a hero-type block, or null. Prefers the main
 *  photo over a background (matches what the Test Lab previews). */
function ms_hero_image_field(array $block): ?string {
    if (strncmp($block['type'] ?? '', 'hero', 4) !== 0) return null;
    $imgs = [];
    foreach ($block as $k => $v) {
        if (is_string($v) && preg_match('/\.(jpe?g|png|webp)$/i', $v) && stripos($v, 'uploads') !== false) $imgs[$k] = $v;
    }
    if (!$imgs) return null;
    foreach ($imgs as $k => $v) { if (stripos($k, 'bg') === false) return $k; } // main photo first
    return array_key_first($imgs);                                              // else the background
}

/** Build-time overlay style. If a locked style is given (from the Test Lab, with
 *  ref_w/ref_h), its point sizes are scaled from the reference image to THIS hero's
 *  size so the look stays consistent across heroes of any dimension. With no locked
 *  style, sizes fall back to a fraction of image width. Colours default to legible
 *  white on the dark fade. */
function ms_hero_style(int $W, int $H, array $locked = []): array {
    $refW = (int)($locked['ref_w'] ?? 0);
    $refH = (int)($locked['ref_h'] ?? 0);
    $scaleW = $refW > 0 ? $W / $refW : null;
    $scaleH = $refH > 0 ? $H / $refH : null;

    $s1 = isset($locked['s1']) ? ($scaleW ? (int)round($locked['s1'] * $scaleW) : (int)$locked['s1']) : max(20, (int)round($W * 0.055));
    $s2 = isset($locked['s2']) ? ($scaleW ? (int)round($locked['s2'] * $scaleW) : (int)$locked['s2']) : max(16, (int)round($W * 0.048));
    $scrim = isset($locked['scrim']) ? ($scaleH ? (int)round($locked['scrim'] * $scaleH) : (int)$locked['scrim']) : (int)round($H * 0.55);

    return [
        'pos'   => in_array($locked['pos'] ?? 'bl', ['bl', 'bc', 'tl'], true) ? $locked['pos'] : 'bl',
        's1'    => max(8, $s1),
        's2'    => max(8, $s2),
        'scrim' => max(0, min($H, $scrim)),
        'c1'    => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($locked['c1'] ?? '')) ? $locked['c1'] : '#ffffff',
        'c2'    => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($locked['c2'] ?? '')) ? $locked['c2'] : '#ffffff',
    ];
}

/** Atomic JSON write (tmp + rename). */
function ms_overlay_write_json(string $file, array $data): void {
    $tmp = $file . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

/** Replace the working dir's symlinked uploads/ with a real per-site directory
 *  (hardlink farm — cheap) so we can add stamped files without touching the shared
 *  snapshot. No-op if it's already a real directory. */
function ms_materialize_uploads(string $workingDir): bool {
    $up = $workingDir . '/uploads';
    if (is_link($up)) {
        $target = readlink($up);
        if ($target === false || !is_dir($target)) $target = realpath($up) ?: '';
        @unlink($up);
        if (!is_dir($up)) mkdir($up, 0775, true);
        if ($target !== '' && is_dir($target)) ms_overlay_hardlink_dir($target, $up);
    }
    return is_dir($up);
}

function ms_overlay_hardlink_dir(string $src, string $dst): void {
    if (!is_dir($dst)) mkdir($dst, 0775, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $s = "{$src}/{$e}"; $d = "{$dst}/{$e}";
        if (is_dir($s)) ms_overlay_hardlink_dir($s, $d);
        elseif (!@link($s, $d)) @copy($s, $d);
    }
}

/** Stamp keyword + "City, ST" onto every hero image in a block list (by ref).
 *  Writes a per-page/per-domain output file and repoints the block field to it.
 *  Returns the number of images stamped. */
function ms_stamp_blocks(array &$blocks, string $keyword, string $cityLine, string $workingDir, string $pageKey, string $siteCitySlug, string $masterCitySlug, array $styleOverride): array {
    $out = [];
    foreach ($blocks as &$b) {
        if (!is_array($b)) continue;
        $field = ms_hero_image_field($b);
        if ($field === null) continue;
        $rel = $b[$field];
        $srcFile = $workingDir . '/' . ltrim($rel, '/');
        if (!is_file($srcFile)) continue;

        $line1 = trim($keyword);
        $line2 = $cityLine;
        if ($line1 === '' && $line2 === '') continue;

        $g = @getimagesize($srcFile);
        if (!$g) continue;
        $W = (int)$g[0]; $H = (int)$g[1];

        // city-renamed (master city stripped) + a per-page suffix for uniqueness
        $cityRel = ms_city_image_path($rel, $siteCitySlug, $masterCitySlug);
        $outRel  = preg_replace('/(\.[^.\/]+)$/', '__' . $pageKey . '$1', $cityRel);
        $outFile = $workingDir . '/' . $outRel;

        $o = ms_hero_style($W, $H, $styleOverride) + ['line1' => $line1, 'line2' => $line2, 'W' => $W, 'H' => $H];
        $r = ms_hero_overlay_render($srcFile, $outFile, $o);
        if (!empty($r['ok'])) { $b[$field] = $outRel; $out[] = $outRel; }
    }
    unset($b);
    return $out;
}

// ── Image byte + filename differentiation (the "vary every image" pass) ───────

/** "City, ST" → "city-st" slug. Uses slugify() when available. */
function ms_slug_city(string $city, string $ss): string {
    if ($city === '') return '';
    $s = trim($city . ' ' . $ss);
    if (function_exists('slugify')) return slugify($s);
    return trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($s)), '-');
}

/** Deterministic small int in [0,$mod) from a string seed. */
function ms_seed_int(string $seed, int $mod): int {
    return $mod > 0 ? (int)(hexdec(substr(md5($seed), 0, 8)) % $mod) : 0;
}

/** True if a value is a content photo we should differentiate (raster in uploads,
 *  not a logo / icon / favicon / badge — those stay identical across a brand). */
function ms_is_content_image($val): bool {
    if (!is_string($val) || !preg_match('/\.(jpe?g|png|webp)$/i', $val)) return false;
    if (stripos($val, 'uploads') === false) return false;
    $base = strtolower(basename($val));
    foreach (['logo', 'icon', 'favicon', 'badge', 'sprite'] as $ex) if (strpos($base, $ex) !== false) return false;
    return true;
}

/** Re-encode $src → $out with subtle, seed-deterministic perturbation: strip
 *  metadata, off-centre crop ~1-2%, ±2% tone, re-compress. Visually a non-event;
 *  changes every byte and shifts the perceptual hash. Format from $out extension. */
function ms_perturb_image(string $src, string $out, string $seed): bool {
    $bin = ms_convert_bin();
    if ($bin === null) return false;
    $g = @getimagesize($src);
    if (!$g) return false;
    $W = (int)$g[0]; $H = (int)$g[1];

    $dx = max(2, (int)round($W * (0.010 + ms_seed_int($seed . 'dx', 10) / 1000)));  // 1.0–1.9%
    $dy = max(2, (int)round($H * (0.010 + ms_seed_int($seed . 'dy', 10) / 1000)));
    $ox = ms_seed_int($seed . 'ox', $dx + 1);
    $oy = ms_seed_int($seed . 'oy', $dy + 1);
    $cw = max(1, $W - $dx); $ch = max(1, $H - $dy);
    $b  = 98 + ms_seed_int($seed . 'b', 5);   // 98–102
    $s  = 98 + ms_seed_int($seed . 's', 5);   // 98–102
    $q  = 80 + ms_seed_int($seed . 'q', 9);   // 80–88

    $cmd = [$bin, $src, '-strip', '-crop', "{$cw}x{$ch}+{$ox}+{$oy}", '+repage',
            '-modulate', "{$b},{$s}", '-quality', (string)$q, $out];
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $o, $rc);
    return $rc === 0 && is_file($out) && filesize($out) > 0;
}

/** Rebuild an uploads-relative path with the site city appended (and the master
 *  city stripped if present). Keeps the directory + extension. */
function ms_city_image_path(string $rel, string $siteCitySlug, string $masterCitySlug = ''): string {
    $dir  = trim(dirname($rel), '.');
    $ext  = pathinfo($rel, PATHINFO_EXTENSION);
    $stem = pathinfo($rel, PATHINFO_FILENAME);
    if ($masterCitySlug !== '') {
        foreach (array_unique([$masterCitySlug, explode('-', $masterCitySlug)[0]]) as $tok) {
            if ($tok === '') continue;
            $stem = preg_replace('/[-_]?' . preg_quote($tok, '/') . '(?=[-_]|$)/i', '', $stem);
        }
    }
    $stem = trim(preg_replace('/[-_]{2,}/', '-', $stem), '-_');
    if ($stem === '') $stem = 'img';
    $suffix = $siteCitySlug !== '' ? '-' . $siteCitySlug : '';
    return ($dir !== '' ? $dir . '/' : '') . $stem . $suffix . ($ext !== '' ? '.' . $ext : '');
}

/** Perturb one image and give it a city filename. Idempotent (skips if the target
 *  already exists). Leaves the source in place. Returns the new relative path, the
 *  original if unchanged, or null on failure. */
function ms_vary_one(string $baseDir, string $rel, string $seed, string $siteCitySlug, string $masterCitySlug): ?string {
    $newRel = ms_city_image_path($rel, $siteCitySlug, $masterCitySlug);
    if ($newRel === $rel) return $rel;
    $newFile = $baseDir . '/' . $newRel;
    if (is_file($newFile)) return $newRel;                 // already produced by another page — repoint
    $srcFile = $baseDir . '/' . $rel;
    if (!is_file($srcFile)) return null;
    $tmp = $newFile . '.tmp.' . getmypid();
    if (!ms_perturb_image($srcFile, $tmp, $seed . '|' . $rel)) { @unlink($tmp); return null; }
    if (!@rename($tmp, $newFile)) { @unlink($tmp); return null; }
    // Leave the source in place — other city pages may still need it. The
    // now-unreferenced original is removed later by ms_prune_unreferenced_uploads().
    return $newRel;
}

/**
 * Delete raster images in the working uploads/ that no page references (the
 * master-named originals we replaced, plus the master's unused media library).
 * Scans the raw JSON so it catches refs anywhere (blocks, theme, inline HTML/CSS).
 * Multisite-only cleanup — never run against a real editable site. Returns count.
 */
function ms_prune_unreferenced_uploads(string $workingDir): int {
    $referenced = [];
    $collect = function (string $file) use (&$referenced) {
        $s = @file_get_contents($file);
        if ($s === false) return;
        if (preg_match_all('#uploads/[A-Za-z0-9._/\-]+\.(?:jpe?g|png|webp|gif|svg|ico)#i', $s, $m)) {
            foreach ($m[0] as $p) $referenced[ltrim($p, '/')] = true;
        }
    };
    $collect($workingDir . '/data/site.json');
    foreach (glob($workingDir . '/data/pages/*.json') ?: [] as $pf) $collect($pf);

    $base = $workingDir . '/uploads';
    if (!is_dir($base)) return 0;
    $removed = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $path = $f->getPathname();
        if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $path)) continue;   // only prune raster; keep svg/ico/css/etc.
        $rel = 'uploads/' . ltrim(substr($path, strlen($base)), '/\\');
        if (isset($referenced[$rel])) continue;
        if (@unlink($path)) $removed++;
    }
    return $removed;
}

/** Walk a block tree (by ref); perturb + rename every content image, repointing
 *  the field. $skip holds paths to leave alone (e.g. already-stamped heroes).
 *  $rename memoises so a shared image is processed once. Returns true if changed. */
function ms_vary_walk(&$node, string $baseDir, string $seed, string $siteCitySlug, string $masterCitySlug, array &$rename, array $skip, int &$count): bool {
    if (!is_array($node)) return false;
    $changed = false;
    foreach ($node as $k => &$v) {
        if (is_array($v)) {
            if (ms_vary_walk($v, $baseDir, $seed, $siteCitySlug, $masterCitySlug, $rename, $skip, $count)) $changed = true;
            continue;
        }
        if (!ms_is_content_image($v)) continue;
        $rel = ltrim((string)$v, '/');
        if (isset($skip[$rel])) continue;
        if (!array_key_exists($rel, $rename)) {
            $new = ms_vary_one($baseDir, $rel, $seed, $siteCitySlug, $masterCitySlug);
            $rename[$rel] = ($new !== null && $new !== $rel) ? $new : null;
            if ($rename[$rel] !== null) $count++;
        }
        if (!empty($rename[$rel])) { $v = $rename[$rel]; $changed = true; }
    }
    unset($v);
    return $changed;
}

/**
 * Reusable per-page image differentiation — stamp hero(s) + vary every other
 * content image — on ONE block list (mutated by ref). This is the shared entry
 * point for BOTH the multisite build and (later) the single-site landing-page
 * generator: pass whatever context you have.
 *
 * $ctx: site_dir (dir holding uploads/, required) · seed (determinism string,
 * required) · city, ss · keyword (hero line 1) · master_city_slug · style ·
 * page_key (hero output naming) · stamp_hero (bool, default true) · vary_images
 * (bool, default true). Returns ['stamped'=>[paths], 'varied'=>int, 'changed'=>bool].
 */
function ms_process_blocks_images(array &$blocks, array $ctx): array {
    $baseDir = $ctx['site_dir'] ?? '';
    $seed    = (string)($ctx['seed'] ?? $baseDir);
    $city    = trim((string)($ctx['city'] ?? ''));
    $ss      = trim((string)($ctx['ss'] ?? ''));
    $cityLine = $city !== '' ? ($ss !== '' ? "{$city}, {$ss}" : $city) : '';
    $citySlug = ms_slug_city($city, $ss);
    $mcs     = (string)($ctx['master_city_slug'] ?? '');
    $style   = $ctx['style'] ?? [];
    $pageKey = (string)($ctx['page_key'] ?? 'p');
    $keyword = trim((string)($ctx['keyword'] ?? ''));
    $doHero  = $ctx['stamp_hero']  ?? true;
    $doVary  = $ctx['vary_images'] ?? true;

    $stamped = [];
    if ($doHero) {
        $stamped = ms_stamp_blocks($blocks, $keyword, $cityLine, $baseDir, $pageKey, $citySlug, $mcs, $style);
    }
    $varied = 0;
    if ($doVary && $citySlug !== '') {
        $skip = [];
        foreach ($stamped as $p) $skip[ltrim($p, '/')] = true;
        $rename = [];
        ms_vary_walk($blocks, $baseDir, $seed, $citySlug, $mcs, $rename, $skip, $varied);
    }
    return ['stamped' => $stamped, 'varied' => $varied, 'changed' => ($stamped || $varied > 0)];
}

/**
 * Raw-text sweep for images the typed walk can't reach — paths hardcoded inside
 * custom_html / rich content. Reads the written JSON, perturbs + city-renames any
 * remaining content image, and string-replaces the path. Guards skip already-
 * city-named files, hero outputs, and brand assets. Returns count.
 */
function ms_vary_raw_image_refs(string $file, string $baseDir, string $seed, string $siteCitySlug, string $masterCitySlug, array $skip): int {
    if ($siteCitySlug === '') return 0;
    $txt = @file_get_contents($file);
    if ($txt === false || !preg_match_all('#uploads/[A-Za-z0-9._/\-]+\.(?:jpe?g|png|webp)#i', $txt, $m)) return 0;
    $suffix = '-' . $siteCitySlug;
    $count = 0; $seen = [];
    foreach (array_unique($m[0]) as $raw) {
        $rel = ltrim($raw, '/');
        if (isset($skip[$rel]) || isset($seen[$rel])) continue;
        $seen[$rel] = true;
        if (strpos($rel, '__') !== false) continue;                                  // hero output
        $stem = pathinfo($rel, PATHINFO_FILENAME);
        if (substr($stem, -strlen($suffix)) === $suffix) continue;                    // already city-named
        $bn = strtolower(basename($rel)); $brand = false;
        foreach (['logo', 'icon', 'favicon', 'badge', 'sprite'] as $ex) if (strpos($bn, $ex) !== false) { $brand = true; break; }
        if ($brand) continue;
        $new = ms_vary_one($baseDir, $rel, $seed, $siteCitySlug, $masterCitySlug);
        if ($new && $new !== $rel) { $txt = str_replace($raw, $new, $txt); $count++; }
    }
    if ($count > 0) file_put_contents($file, $txt);
    return $count;
}

/**
 * Multisite orchestrator: differentiate images across the whole working site —
 * homepage + core pages (site.json) and each generated landing page (own
 * city_vars). Runs the reusable per-block core, then a raw-text sweep for
 * HTML-embedded refs, then prunes unreferenced files. Returns totals.
 */
function ms_differentiate_site_images(string $workingDir, array $params, string $masterCitySlug = '', array $style = []): array {
    if (ms_convert_bin() === null) return ['stamped' => 0, 'varied' => 0, 'pruned' => 0];
    if (!ms_materialize_uploads($workingDir)) return ['stamped' => 0, 'varied' => 0, 'pruned' => 0];

    $domain = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $seed   = $domain !== '' ? $domain : $workingDir;
    $city   = trim($params['city'] ?? '');
    $ss     = trim($params['SS'] ?? '');
    $tot    = ['stamped' => 0, 'varied' => 0, 'pruned' => 0];

    // One file = one city (site.json uses the site's city; a landing page its own).
    $processFile = function (string $file, string $fcity, string $fss) use ($workingDir, $seed, $masterCitySlug, $style, &$tot) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) return;
        $stamped = [];
        $run = function (array &$blocks, string $keyword, string $pageKey) use ($workingDir, $seed, $fcity, $fss, $masterCitySlug, $style, &$tot, &$stamped) {
            $r = ms_process_blocks_images($blocks, [
                'site_dir' => $workingDir, 'seed' => $seed, 'city' => $fcity, 'ss' => $fss,
                'keyword' => $keyword, 'master_city_slug' => $masterCitySlug, 'style' => $style, 'page_key' => $pageKey,
            ]);
            $tot['stamped'] += count($r['stamped']);
            $tot['varied']  += $r['varied'];
            $stamped = array_merge($stamped, $r['stamped']);
            return $r['changed'];
        };
        $changed = false;
        if (isset($data['content_blocks']) && is_array($data['content_blocks'])) {
            if ($run($data['content_blocks'], $data['seo']['primary_keyword'] ?? '', 'home')) $changed = true;
        }
        foreach (($data['pages'] ?? []) as $i => &$pg) {
            if (!is_array($pg) || !isset($pg['content_blocks'])) continue;
            if ($run($pg['content_blocks'], $pg['seo']['primary_keyword'] ?? '', 'p' . $i)) $changed = true;
        }
        unset($pg);
        if ($changed) ms_overlay_write_json($file, $data);

        // raw-text sweep for anything embedded in HTML the typed walk missed
        $skip = [];
        foreach ($stamped as $p) $skip[ltrim($p, '/')] = true;
        $tot['varied'] += ms_vary_raw_image_refs($file, $workingDir, $seed, ms_slug_city($fcity, $fss), $masterCitySlug, $skip);
    };

    $processFile($workingDir . '/data/site.json', $city, $ss);
    foreach (glob($workingDir . '/data/pages/*.json') ?: [] as $pf) {
        $cv = (json_decode((string)@file_get_contents($pf), true)['city_vars'] ?? []);
        $processFile($pf, trim($cv['city'] ?? $city), trim($cv['SS'] ?? $ss));
    }

    // Drop every image no page references — the master-named originals we replaced
    // plus the master's unused media library (dead weight + a footprint on deploy).
    $tot['pruned'] = ms_prune_unreferenced_uploads($workingDir);

    return $tot;
}
