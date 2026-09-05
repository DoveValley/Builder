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

/** Rendered pixel width of $text at $font/$size — used only to justify a line
 *  relative to another (center/right); left-justified lines never call this. */
function ms_text_width(string $bin, string $font, int $size, string $text): int {
    if ($text === '') return 0;
    $cmd = [$bin, '-font', $font, '-pointsize', (string)$size, 'label:' . $text, '-format', '%w', 'info:'];
    $shell = implode(' ', array_map('escapeshellarg', $cmd));
    return max(0, (int)trim((string)@shell_exec($shell . ' 2>/dev/null')));
}

/**
 * Render up to 3 text lines onto $src → $out (output format taken from $out's
 * extension). Returns ['ok'=>bool, 'error'=>string, 'cmd'=>string].
 * $o keys: line1,line2,line3, x,y (% position of the text block's top-left
 * corner — free placement, not a preset), c1,c2, s1,s2,
 * j1 (left|center|right — line 1 justified relative to the image width, inset
 * by x on the side(s) it isn't anchored to), j2,j3 (left|center|right — line
 * 2/3 justified within line 1's own ACTUAL rendered horizontal span, tracking
 * wherever line 1 itself lands — not the raw x anchor),
 * bg_side(top|bottom|full|none), bg_height(% of image height, ignored for
 * full/none), bg_fade(bool), bg_opacity(0-100), font, W,H.
 */
function ms_hero_overlay_render(string $src, string $out, array $o): array {
    $bin = ms_convert_bin();
    if ($bin === null) return ['ok' => false, 'error' => 'ImageMagick (convert) not found', 'cmd' => ''];

    $font = $o['font'] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
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

    $j1 = in_array($o['j1'] ?? 'left', ['left', 'center', 'right'], true) ? ($o['j1'] ?? 'left') : 'left';
    $j2 = in_array($o['j2'] ?? 'left', ['left', 'center', 'right'], true) ? ($o['j2'] ?? 'left') : 'left';
    $j3 = in_array($o['j3'] ?? 'left', ['left', 'center', 'right'], true) ? ($o['j3'] ?? 'left') : 'left';

    $lines = [];
    if (($o['line1'] ?? '') !== '') $lines[] = ['t' => (string)$o['line1'], 's' => $s1, 'c' => $c1, 'j' => $j1];
    if (($o['line2'] ?? '') !== '') $lines[] = ['t' => (string)$o['line2'], 's' => $s2, 'c' => $c2, 'j' => $j2];
    if (($o['line3'] ?? '') !== '') $lines[] = ['t' => (string)$o['line3'], 's' => $s2, 'c' => $c2, 'j' => $j3];
    if (!$lines) return ['ok' => false, 'error' => 'no text to render', 'cmd' => ''];

    // Free placement: (x%,y%) is the top-left anchor of the whole text block.
    // Gravity is always northwest and lines always stack downward from there,
    // so the anchor math stays one simple case regardless of where it lands —
    // no separate top-anchored/bottom-anchored branches to keep in sync.
    $x = (int)round($W * max(0, min(100, (float)($o['x'] ?? 5))) / 100);
    $y = (int)round($H * max(0, min(100, (float)($o['y'] ?? 80))) / 100);

    // Shrink-to-fit: the SAME font size is baked onto every page's own
    // AI-written keyword, and those vary in length ("Flood Damage Restoration"
    // vs "Commercial Water Damage Restoration") — a size tuned against a short
    // sample can still overflow a longer one on a different page. Two limits,
    // whichever is tighter:
    //  1. The x-inset as a horizontal margin — whatever the admin configured.
    //  2. Every hero image on this system ends up in a hero_split block, whose
    //     .hs-image-wrap/.hs-image CSS forces it into a 4:3 box via
    //     object-fit:cover — a wider source has its outer edges cropped away
    //     on the live page, invisibly, at H*(4/3) px of the original width.
    //     Fitting text to only the surviving center avoids baking something
    //     that looks fine in the full file but is cut off once displayed.
    // Only ever shrinks a line, never grows one.
    $maxLineW = max(1, min($W - 2 * $x, (int)round($H * 4 / 3)));
    foreach ($lines as &$ln) {
        $lw = ms_text_width($bin, $font, $ln['s'], $ln['t']);
        if ($lw > $maxLineW && $lw > 0) $ln['s'] = max(8, (int)floor($ln['s'] * $maxLineW / $lw));
    }
    unset($ln);

    $gap = (int)round($s2 * 0.30);

    // A y% chosen against one hero can clip a differently-shaped one — a short,
    // wide image has far less vertical room at the same percentage as a tall
    // one. Clamp so the whole stacked block always fits inside the image,
    // pulling it up off the bottom edge rather than letting the last line run
    // off frame; falls back to the top edge only if the block is taller than
    // the image itself (nothing left to fit it into).
    $blockH = array_sum(array_column($lines, 's')) + $gap * (count($lines) - 1);
    $y = max(0, min($y, $H - $blockH));

    $cmd = [$bin, $src, '-strip'];   // -strip: no metadata → byte-reproducible rebuilds

    // Background band — independent of where the text sits, so text can be
    // centered while the band still hugs an edge (or vice versa).
    $bgSide = in_array($o['bg_side'] ?? 'bottom', ['top', 'bottom', 'full', 'none'], true) ? ($o['bg_side'] ?? 'bottom') : 'bottom';
    $alpha  = round(max(0, min(100, (float)($o['bg_opacity'] ?? 100))) / 100, 2);
    if ($bgSide !== 'none' && $alpha > 0) {
        if ($bgSide === 'full') {
            // A gradient fade makes no sense across the whole image — always flat.
            $cmd = array_merge($cmd, ['(', '-size', "{$W}x{$H}", "xc:rgba(0,0,0,{$alpha})", ')', '-gravity', 'center', '-composite']);
        } else {
            $bandH = max(1, (int)round($H * max(0, min(100, (float)($o['bg_height'] ?? 55))) / 100));
            $grav  = $bgSide === 'top' ? 'north' : 'south';
            $fade  = array_key_exists('bg_fade', $o) ? (bool)$o['bg_fade'] : true;
            $fill  = $fade
                ? ($bgSide === 'top' ? "gradient:rgba(0,0,0,{$alpha})-none" : "gradient:none-rgba(0,0,0,{$alpha})")
                : "xc:rgba(0,0,0,{$alpha})";
            $cmd = array_merge($cmd, ['(', '-size', "{$W}x{$bandH}", $fill, ')', '-gravity', $grav, '-composite']);
        }
    }

    $cmd = array_merge($cmd, ['-font', $font, '-gravity', 'northwest']);
    $yy = $y;
    $refW = null;   // line 1's rendered width — measured lazily, only if line 2/3 actually needs it
    $dx0  = 0;      // line 1's own horizontal offset — line 2/3 justify within line 1's ACTUAL
                     // rendered span (its real left edge is $x + $dx0), not the raw $x anchor,
                     // so they still track line 1 correctly when line 1 itself isn't left-justified.
    foreach ($lines as $i => $ln) {
        if ($i === 0) {
            $dx = 0;
            // Line 1 has no wider line above it to justify against, so it's measured
            // against the image itself: available width mirrors the left inset $x on
            // the opposite edge, keeping the margin symmetric when centered/right-aligned.
            if ($ln['j'] !== 'left') {
                $lw = ms_text_width($bin, $font, $ln['s'], $ln['t']);
                $avail = max(0, $W - 2 * $x);
                $dx = $ln['j'] === 'center' ? (int)round(($avail - $lw) / 2) : ($avail - $lw);
            }
            $dx0 = $dx;
        } else {
            $dx = $dx0;
            if ($ln['j'] !== 'left') {
                if ($refW === null) $refW = ms_text_width($bin, $font, $lines[0]['s'], $lines[0]['t']);
                $lw = ms_text_width($bin, $font, $ln['s'], $ln['t']);
                $dx += $ln['j'] === 'center' ? (int)round(($refW - $lw) / 2) : ($refW - $lw);
            }
        }
        $at = '+' . ($x + $dx) . '+' . $yy;
        $cmd = array_merge($cmd, [
            '-pointsize', (string)$ln['s'],
            '-strokewidth', '3', '-stroke', 'rgba(0,0,0,0.55)', '-fill', 'rgba(0,0,0,0.55)', '-annotate', $at, $ln['t'],
            '-strokewidth', '0', '-stroke', 'none', '-fill', $ln['c'], '-annotate', $at, $ln['t'],
        ]);
        $yy += $ln['s'] + $gap;
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
        if (is_string($k) && $k !== '' && $k[0] === '_') continue;   // skip metadata keys (e.g. _hs_photo_orig)
        if (is_string($v) && preg_match('/\.(jpe?g|png|webp)$/i', $v) && stripos($v, 'uploads') !== false) $imgs[$k] = $v;
    }
    if (!$imgs) return null;
    foreach ($imgs as $k => $v) { if (stripos($k, 'bg') === false) return $k; } // main photo first
    return array_key_first($imgs);                                              // else the background
}

/** Build-time overlay style. If a locked style is given (from the Test Lab, with
 *  ref_w), its point sizes are scaled from the reference image to THIS hero's
 *  size so the look stays consistent across heroes of any dimension. With no locked
 *  style, sizes fall back to a fraction of image width. Colours default to legible
 *  white on the dark band. Position and background are stored as percentages of
 *  the hero's own dimensions, so — unlike point sizes — they never need scaling. */
function ms_hero_style(int $W, int $H, array $locked = []): array {
    $refW = (int)($locked['ref_w'] ?? 0);
    $scaleW = $refW > 0 ? $W / $refW : null;

    $s1 = isset($locked['s1']) ? ($scaleW ? (int)round($locked['s1'] * $scaleW) : (int)$locked['s1']) : max(20, (int)round($W * 0.055));
    $s2 = isset($locked['s2']) ? ($scaleW ? (int)round($locked['s2'] * $scaleW) : (int)$locked['s2']) : max(16, (int)round($W * 0.048));

    $pct = fn($v, $d) => is_numeric($v) ? max(0, min(100, (float)$v)) : $d;

    return [
        'x'          => $pct($locked['x'] ?? null, 5),
        'y'          => $pct($locked['y'] ?? null, 80),
        's1'         => max(8, $s1),
        's2'         => max(8, $s2),
        'c1'         => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($locked['c1'] ?? '')) ? $locked['c1'] : '#ffffff',
        'c2'         => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($locked['c2'] ?? '')) ? $locked['c2'] : '#ffffff',
        'j1'         => in_array($locked['j1'] ?? 'left', ['left', 'center', 'right'], true) ? ($locked['j1'] ?? 'left') : 'left',
        'j2'         => in_array($locked['j2'] ?? 'left', ['left', 'center', 'right'], true) ? ($locked['j2'] ?? 'left') : 'left',
        'j3'         => in_array($locked['j3'] ?? 'left', ['left', 'center', 'right'], true) ? ($locked['j3'] ?? 'left') : 'left',
        'bg_side'    => in_array($locked['bg_side'] ?? 'bottom', ['top', 'bottom', 'full', 'none'], true) ? ($locked['bg_side'] ?? 'bottom') : 'bottom',
        'bg_height'  => $pct($locked['bg_height'] ?? null, 55),
        'bg_fade'    => array_key_exists('bg_fade', $locked) ? (bool)$locked['bg_fade'] : true,
        'bg_opacity' => $pct($locked['bg_opacity'] ?? null, 100),
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
        // Always overlay the ORIGINAL image, never a previously-stamped output: a
        // recorded original (_<field>_orig) wins over the current value, so a re-run
        // with a changed keyword re-renders from the original instead of burning text
        // over text. On first pass the current value IS the original.
        $origKey = '_' . $field . '_orig';
        $rel = (isset($b[$origKey]) && is_string($b[$origKey]) && $b[$origKey] !== '')
            ? $b[$origKey] : (string)$b[$field];
        $srcFile = $workingDir . '/' . ltrim($rel, '/');
        if (!is_file($srcFile)) continue;

        $line1 = trim($keyword);
        $line2 = $cityLine;
        if ($line1 === '' && $line2 === '') continue;

        $g = @getimagesize($srcFile);
        if (!$g) continue;
        $W = (int)$g[0]; $H = (int)$g[1];

        $o = ms_hero_style($W, $H, $styleOverride) + ['line1' => $line1, 'line2' => $line2, 'W' => $W, 'H' => $H];

        // city-renamed (master city stripped) + per-page suffix + a short hash of the
        // render inputs. The hash makes the filename change when the text/style changes,
        // so an existing file is a safe cache hit — cheap re-runs for both callers, while
        // a changed keyword/city/style regenerates under a new name.
        $sig     = substr(md5($line1 . '|' . $line2 . '|' . json_encode($o)), 0, 8);
        $cityRel = ms_city_image_path($rel, $siteCitySlug, $masterCitySlug);
        $outRel  = preg_replace('/(\.[^.\/]+)$/', '__' . $pageKey . '_' . $sig . '$1', $cityRel);
        $outFile = $workingDir . '/' . $outRel;

        if (is_file($outFile)) { $b[$field] = $outRel; $b[$origKey] = ltrim($rel, '/'); $out[] = $outRel; continue; }   // cache hit — skip render

        $r = ms_hero_overlay_render($srcFile, $outFile, $o);
        if (!empty($r['ok'])) { $b[$field] = $outRel; $b[$origKey] = ltrim($rel, '/'); $out[] = $outRel; }
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

/**
 * Where a Gen-Image settings file lives for ONE master, and which copy is really
 * in effect. `$name` is a bare filename — 'hero_style.json' or
 * 'image_variation.json'.
 *
 * The build's rule (multisite/build_one.php, includes/generation/engine.php) is
 * per-master-first, repo-global as fallback. This function is the ONE place that
 * rule is written down, so the admin can never show or write a different file
 * from the one the build reads — it used to: the Gen-Image tab read and wrote
 * only the global copy while calling itself a per-master setting, so a lock made
 * on one master silently applied to every master, and was ignored outright by any
 * master that had its own file.
 *
 * `$masterDir` is the site directory (ACTIVE_SITE_DIR, or BASE_DIR/sites/{id}).
 * Returns:
 *   master → absolute per-master path (always the WRITE target)
 *   global → absolute repo-global path (legacy/shared fallback, read-only now)
 *   path   → the file actually in effect, or null when neither exists
 *   scope  → 'master' | 'global' | 'none', matching `path`
 */
function ms_image_settings_locate(string $masterDir, string $name): array {
    $name   = basename($name);
    $master = rtrim($masterDir, '/') . '/multisite/' . $name;
    $global = BASE_DIR . '/multisite/' . $name;
    $path   = null; $scope = 'none';
    // Same order as build_one.php: the master's own copy wins outright.
    if (rtrim($masterDir, '/') !== '' && is_file($master)) { $path = $master; $scope = 'master'; }
    elseif (is_file($global))                              { $path = $global; $scope = 'global'; }
    return ['master' => $master, 'global' => $global, 'path' => $path, 'scope' => $scope];
}

/** Decode whichever copy is in effect for this master; [] when there is none. */
function ms_image_settings_read(string $masterDir, string $name): array {
    $loc = ms_image_settings_locate($masterDir, $name);
    if ($loc['path'] === null) return [];
    return json_decode((string) @file_get_contents($loc['path']), true) ?: [];
}

/**
 * Write a Gen-Image settings file for one master, atomically. Always writes the
 * PER-MASTER copy — the one the build prefers — so a save can only ever affect
 * the master it was made on. Shared by admin/hero_style_save.php and
 * admin/image_variation_save.php so the two cannot drift apart.
 * Returns ['ok'=>bool, 'error'=>string, 'path'=>string].
 */
function ms_image_settings_write(string $masterDir, string $name, array $payload): array {
    $masterDir = rtrim($masterDir, '/');
    // No active site means no master to attribute the setting to. Refuse rather
    // than fall back to the shared global file — writing that was the bug.
    if ($masterDir === '' || !is_dir($masterDir)) {
        return ['ok' => false, 'error' => 'No active site — open a site first.', 'path' => ''];
    }
    $loc = ms_image_settings_locate($masterDir, $name);
    $dir = dirname($loc['master']);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create ' . basename($dir) . '/ for this site.', 'path' => ''];
    }
    $tmp = $loc['master'] . '.tmp.' . getmypid();
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $loc['master'])) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Could not write ' . basename($name) . '.', 'path' => ''];
    }
    return ['ok' => true, 'error' => '', 'path' => $loc['master']];
}

/**
 * The tunable ranges ms_perturb_image() jitters within, and their fallback
 * values — the exact numbers this whole mechanism used to be hardcoded to,
 * before the Gen-Image tab existed. A missing/invalid key here always falls
 * back to that original number, never to zero.
 */
function ms_image_variation_defaults(): array {
    return [
        'crop_min'       => 1.0,  'crop_max'       => 1.9,    // % of width/height cropped off
        'brightness_min' => 98,   'brightness_max' => 102,    // % modulate
        'saturation_min' => 98,   'saturation_max' => 102,    // % modulate
        'quality_min'    => 80,   'quality_max'    => 88,     // re-encode quality
    ];
}

/** Clamp $ranges to sane, safe bounds and fill in anything missing from the
 *  defaults — the one place both the save endpoint and the build read from,
 *  so a hand-edited or half-written config file can't produce a broken crop. */
function ms_image_variation_ranges(array $ranges): array {
    $d = ms_image_variation_defaults();
    $num = fn($k, $lo, $hi) => is_numeric($ranges[$k] ?? null) ? max($lo, min($hi, (float)$ranges[$k])) : $d[$k];
    $r = [
        'crop_min'       => $num('crop_min', 0, 10),
        'crop_max'       => $num('crop_max', 0, 10),
        'brightness_min' => $num('brightness_min', 80, 120),
        'brightness_max' => $num('brightness_max', 80, 120),
        'saturation_min' => $num('saturation_min', 80, 120),
        'saturation_max' => $num('saturation_max', 80, 120),
        'quality_min'    => $num('quality_min', 40, 100),
        'quality_max'    => $num('quality_max', 40, 100),
    ];
    // A flipped pair (min > max) would make the seeded range() calls below throw —
    // swap rather than reject, since "82 to 80" obviously means "80 to 82".
    foreach (['crop', 'brightness', 'saturation', 'quality'] as $k) {
        if ($r["{$k}_min"] > $r["{$k}_max"]) [$r["{$k}_min"], $r["{$k}_max"]] = [$r["{$k}_max"], $r["{$k}_min"]];
    }
    return $r;
}

/** Re-encode $src → $out with subtle, seed-deterministic perturbation: strip
 *  metadata, off-centre crop, tone shift, re-compress. Visually a non-event at
 *  the default ranges; changes every byte and shifts the perceptual hash.
 *  Format from $out extension. $ranges — see ms_image_variation_defaults(). */
function ms_perturb_image(string $src, string $out, string $seed, array $ranges = []): bool {
    $bin = ms_convert_bin();
    if ($bin === null) return false;
    $g = @getimagesize($src);
    if (!$g) return false;
    $W = (int)$g[0]; $H = (int)$g[1];
    $r = ms_image_variation_ranges($ranges);

    // 10 discrete steps across the span (same granularity the hardcoded 1.0–1.9%
    // used) so that at the untouched default range this reproduces the exact same
    // crop for the exact same domain — changing the math here, even by a value
    // that stays inside the same range, would re-crop every already-built photo
    // on the next rebuild and force a full re-upload for no visible reason.
    $cropSpan = max(0, $r['crop_max'] - $r['crop_min']);
    $cropPctX = $r['crop_min'] + ($cropSpan > 0 ? ms_seed_int($seed . 'dx', 10) * $cropSpan / 9 : 0);
    $cropPctY = $r['crop_min'] + ($cropSpan > 0 ? ms_seed_int($seed . 'dy', 10) * $cropSpan / 9 : 0);
    $dx = max(2, (int)round($W * $cropPctX / 100));
    $dy = max(2, (int)round($H * $cropPctY / 100));
    $ox = ms_seed_int($seed . 'ox', $dx + 1);
    $oy = ms_seed_int($seed . 'oy', $dy + 1);
    $cw = max(1, $W - $dx); $ch = max(1, $H - $dy);
    $bSpan = max(0, $r['brightness_max'] - $r['brightness_min']);
    $sSpan = max(0, $r['saturation_max'] - $r['saturation_min']);
    $qSpan = max(0, $r['quality_max'] - $r['quality_min']);
    $b = (int)round($r['brightness_min'] + ($bSpan > 0 ? ms_seed_int($seed . 'b', (int)$bSpan + 1) : 0));
    $s = (int)round($r['saturation_min'] + ($sSpan > 0 ? ms_seed_int($seed . 's', (int)$sSpan + 1) : 0));
    $q = (int)round($r['quality_min']    + ($qSpan > 0 ? ms_seed_int($seed . 'q', (int)$qSpan + 1) : 0));

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
function ms_vary_one(string $baseDir, string $rel, string $seed, string $siteCitySlug, string $masterCitySlug, array $ranges = []): ?string {
    $newRel = ms_city_image_path($rel, $siteCitySlug, $masterCitySlug);
    if ($newRel === $rel) return $rel;
    $newFile = $baseDir . '/' . $newRel;
    if (is_file($newFile)) return $newRel;                 // already produced by another page — repoint
    $srcFile = $baseDir . '/' . $rel;
    if (!is_file($srcFile)) return null;
    $tmp = $newFile . '.tmp.' . getmypid();
    if (!ms_perturb_image($srcFile, $tmp, $seed . '|' . $rel, $ranges)) { @unlink($tmp); return null; }
    if (!@rename($tmp, $newFile)) { @unlink($tmp); return null; }
    // Leave the source in place — other city pages may still need it. The
    // now-unreferenced original is removed later by ms_prune_unreferenced_uploads().
    return $newRel;
}

/** Recursively strip _<field>_orig provenance keys from a decoded page/site array.
 *  Used by the multisite orchestrator so the throwaway build's JSON, prune, and
 *  raw-text sweep are unaffected by the single-site original-tracking invariant. */
function ms_unset_orig_keys(array &$node): void {
    foreach (array_keys($node) as $k) {
        if (is_string($k) && $k !== '' && $k[0] === '_' && substr($k, -5) === '_orig') { unset($node[$k]); continue; }
        if (isset($node[$k]) && is_array($node[$k])) ms_unset_orig_keys($node[$k]);
    }
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
function ms_vary_walk(&$node, string $baseDir, string $seed, string $siteCitySlug, string $masterCitySlug, array &$rename, array $skip, int &$count, array $ranges = []): bool {
    if (!is_array($node)) return false;
    $changed = false;
    $recordOrig = [];   // origKey => original path — applied after the loop (never mutate $node mid-foreach)
    foreach ($node as $k => &$v) {
        if (is_string($k) && $k !== '' && $k[0] === '_') continue;   // skip metadata keys (incl _<field>_orig)
        if (is_array($v)) {
            if (ms_vary_walk($v, $baseDir, $seed, $siteCitySlug, $masterCitySlug, $rename, $skip, $count, $ranges)) $changed = true;
            continue;
        }
        if (!ms_is_content_image($v)) continue;
        // Skip fields already handled this run (e.g. a hero the stamp pass produced) —
        // checked on the CURRENT value, before we consult _orig, so we never re-vary a
        // stamped hero back into a plain perturbed copy.
        if (isset($skip[ltrim((string)$v, '/')])) continue;
        // Derive from the ORIGINAL: a recorded original (_<key>_orig) wins over the
        // current (possibly already-varied) value, so a re-run never perturbs a
        // perturbed image. On first pass the current value IS the original.
        $origKey = is_string($k) ? '_' . $k . '_orig' : '';
        $src = ($origKey !== '' && isset($node[$origKey]) && ms_is_content_image($node[$origKey]))
            ? ltrim((string)$node[$origKey], '/') : ltrim((string)$v, '/');
        if (isset($skip[$src])) continue;
        if (!array_key_exists($src, $rename)) {
            $new = ms_vary_one($baseDir, $src, $seed, $siteCitySlug, $masterCitySlug, $ranges);
            $rename[$src] = ($new !== null && $new !== $src) ? $new : null;
            if ($rename[$src] !== null) $count++;
        }
        if (!empty($rename[$src])) {
            $v = $rename[$src];
            if ($origKey !== '') $recordOrig[$origKey] = $src;
            $changed = true;
        }
    }
    unset($v);
    foreach ($recordOrig as $ok => $ov) $node[$ok] = $ov;   // remember originals (structural invariant)
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
 * (bool, default true) · ranges (crop/tone/quality jitter, see
 * ms_image_variation_defaults()). Returns ['stamped'=>[paths], 'varied'=>int, 'changed'=>bool].
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
    $ranges  = $ctx['ranges'] ?? [];
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
        ms_vary_walk($blocks, $baseDir, $seed, $citySlug, $mcs, $rename, $skip, $varied, $ranges);
    }
    return ['stamped' => $stamped, 'varied' => $varied, 'changed' => ($stamped || $varied > 0)];
}

/**
 * Raw-text sweep for images the typed walk can't reach — paths hardcoded inside
 * custom_html / rich content. Reads the written JSON, perturbs + city-renames any
 * remaining content image, and string-replaces the path. Guards skip already-
 * city-named files, hero outputs, and brand assets. Returns count.
 */
function ms_vary_raw_image_refs(string $file, string $baseDir, string $seed, string $siteCitySlug, string $masterCitySlug, array $skip, array $ranges = []): int {
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
        $new = ms_vary_one($baseDir, $rel, $seed, $siteCitySlug, $masterCitySlug, $ranges);
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
function ms_differentiate_site_images(string $workingDir, array $params, string $masterCitySlug = '', array $style = [], array $ranges = []): array {
    if (ms_convert_bin() === null) return ['stamped' => 0, 'varied' => 0, 'pruned' => 0];
    if (!ms_materialize_uploads($workingDir)) return ['stamped' => 0, 'varied' => 0, 'pruned' => 0];

    $domain = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $seed   = $domain !== '' ? $domain : $workingDir;
    $city   = trim($params['city'] ?? '');
    $ss     = trim($params['SS'] ?? '');
    $tot    = ['stamped' => 0, 'varied' => 0, 'pruned' => 0];

    // One file = one city (site.json uses the site's city; a landing page its own).
    $processFile = function (string $file, string $fcity, string $fss) use ($workingDir, $seed, $masterCitySlug, $style, $ranges, &$tot) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) return;
        $stamped = [];
        $run = function (array &$blocks, string $keyword, string $pageKey) use ($workingDir, $seed, $fcity, $fss, $masterCitySlug, $style, $ranges, &$tot, &$stamped) {
            $r = ms_process_blocks_images($blocks, [
                'site_dir' => $workingDir, 'seed' => $seed, 'city' => $fcity, 'ss' => $fss,
                'keyword' => $keyword, 'master_city_slug' => $masterCitySlug, 'style' => $style,
                'ranges' => $ranges, 'page_key' => $pageKey,
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
        // Multisite build is a throwaway clone (fresh originals every build, one pass),
        // so the _<field>_orig provenance keys aren't needed here and would only pin the
        // originals against the prune and confuse the raw-text sweep. Strip them so the
        // written JSON is byte-for-byte what it was before this feature. (The single-site
        // path keeps _orig — it re-differentiates a persistent store.)
        ms_unset_orig_keys($data);
        if ($changed) ms_overlay_write_json($file, $data);

        // raw-text sweep for anything embedded in HTML the typed walk missed
        $skip = [];
        foreach ($stamped as $p) $skip[ltrim($p, '/')] = true;
        $tot['varied'] += ms_vary_raw_image_refs($file, $workingDir, $seed, ms_slug_city($fcity, $fss), $masterCitySlug, $skip, $ranges);
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

// ── Metadata stripping ────────────────────────────────────────────────────────
//
// Photos arrive carrying EXIF/XMP: camera make and model, editing software, sometimes a
// photographer's name, and sometimes GPS coordinates. Real example from water-site: two
// drone shots filed as "lufkin-tx" carried coordinates in Florida, ~900 miles from the city
// the page claims to serve. That is readable by anyone who downloads the file, and it
// contradicts the site's own locality claim.
//
// LOSSLESS ON PURPOSE. Re-encoding through GD to drop a few bytes of metadata would
// re-compress every photo in the library. These functions edit the CONTAINER instead: they
// remove the metadata chunks and leave the compressed image data byte-for-byte untouched.
// ICC colour profiles are deliberately KEPT — not identifying, and removing one can shift
// how an image renders.

/** Rebuild a WebP (RIFF) without its EXIF/XMP chunks. Null = nothing to remove. */
function ms_strip_webp_metadata(string $bytes): ?string
{
    if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') return null;
    $out = '';
    $i = 12;
    $dropped = false;
    $len = strlen($bytes);
    while ($i + 8 <= $len) {
        $fourcc = substr($bytes, $i, 4);
        $size = unpack('V', substr($bytes, $i + 4, 4))[1];
        if ($size < 0 || $i + 8 + $size > $len) return null;   // malformed — leave it alone
        $payload = substr($bytes, $i + 8, $size);
        $pad = $size & 1;
        if ($fourcc === 'EXIF' || $fourcc === 'XMP ') {
            $dropped = true;
        } else {
            // VP8X flags byte: .. I L E X A R — clear E (exif, 0x08) and X (xmp, 0x04) so the
            // header stops advertising metadata that is no longer in the file.
            if ($fourcc === 'VP8X' && strlen($payload) >= 1) {
                $payload[0] = chr(ord($payload[0]) & ~0x08 & ~0x04);
            }
            $out .= $fourcc . pack('V', strlen($payload)) . $payload . str_repeat("\0", $pad);
        }
        $i += 8 + $size + $pad;
    }
    if (!$dropped) return null;
    $body = 'WEBP' . $out;
    return 'RIFF' . pack('V', strlen($body)) . $body;
}

/** Rebuild a PNG without its text/time/EXIF chunks. Each chunk carries its own CRC, so
 *  dropping whole chunks needs no checksum recalculation. */
function ms_strip_png_metadata(string $bytes): ?string
{
    if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $drop = ['eXIf' => 1, 'tEXt' => 1, 'zTXt' => 1, 'iTXt' => 1, 'tIME' => 1];
    $out = substr($bytes, 0, 8);
    $i = 8;
    $dropped = false;
    $len = strlen($bytes);
    while ($i + 8 <= $len) {
        $chunkLen = unpack('N', substr($bytes, $i, 4))[1];
        $type = substr($bytes, $i + 4, 4);
        if ($i + 12 + $chunkLen > $len) return null;           // malformed — leave it alone
        if (isset($drop[$type])) $dropped = true;
        else $out .= substr($bytes, $i, 12 + $chunkLen);
        $i += 12 + $chunkLen;
        if ($type === 'IEND') break;
    }
    return $dropped ? $out : null;
}

/** Rebuild a JPEG without its APP1 (Exif / XMP) segments. APP2 — normally the ICC
 *  profile — is kept. */
function ms_strip_jpeg_metadata(string $bytes): ?string
{
    if (substr($bytes, 0, 2) !== "\xFF\xD8") return null;
    $out = "\xFF\xD8";
    $i = 2;
    $dropped = false;
    $len = strlen($bytes);
    while ($i + 4 <= $len) {
        if ($bytes[$i] !== "\xFF") return null;                // not a marker — leave it alone
        $marker = ord($bytes[$i + 1]);
        if ($marker === 0xDA) { $out .= substr($bytes, $i); break; }   // scan: copy the rest
        $segLen = unpack('n', substr($bytes, $i + 2, 2))[1];
        if ($i + 2 + $segLen > $len) return null;
        if ($marker === 0xE1) $dropped = true;
        else $out .= substr($bytes, $i, 2 + $segLen);
        $i += 2 + $segLen;
    }
    return $dropped ? $out : null;
}

/** True if this file still carries any metadata chunk — the verification half. */
function ms_image_has_metadata(string $bytes): bool
{
    return ms_strip_webp_metadata($bytes) !== null
        || ms_strip_png_metadata($bytes) !== null
        || ms_strip_jpeg_metadata($bytes) !== null;
}

/**
 * Strip metadata from every image under a built site's uploads/, then VERIFY none is left.
 * Returns ['scanned','stripped','failed','remaining'] — `remaining` is the check, and it
 * should always be 0. Anything else means a file was written that still carries metadata,
 * which the caller reports rather than swallowing.
 */
function ms_strip_uploads_metadata(string $workingDir): array
{
    $res = ['scanned' => 0, 'stripped' => 0, 'failed' => 0, 'remaining' => 0];
    $dir = $workingDir . '/uploads';
    if (!is_dir($dir)) return $res;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['webp', 'jpg', 'jpeg', 'png'], true)) continue;

        $path = $file->getPathname();
        $bytes = (string) @file_get_contents($path);
        if ($bytes === '') continue;
        $res['scanned']++;

        $clean = ms_strip_webp_metadata($bytes)
              ?? ms_strip_png_metadata($bytes)
              ?? ms_strip_jpeg_metadata($bytes);
        if ($clean === null) continue;                          // nothing to remove

        // A truncated write would corrupt the image, so write beside it and rename.
        $tmp = $path . '.strip';
        if (@file_put_contents($tmp, $clean) === false) { $res['failed']++; continue; }
        if (!@rename($tmp, $path)) { @unlink($tmp); $res['failed']++; continue; }
        $res['stripped']++;

        if (ms_image_has_metadata((string) @file_get_contents($path))) $res['remaining']++;
    }
    return $res;
}
