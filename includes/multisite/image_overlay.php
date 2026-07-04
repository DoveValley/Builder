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
function ms_stamp_blocks(array &$blocks, string $keyword, string $cityLine, string $workingDir, string $pageKey, string $domainHash, array $styleOverride): int {
    $done = 0;
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

        $dir  = trim(dirname($rel), '.');
        $stem = pathinfo($rel, PATHINFO_FILENAME);
        $ext  = pathinfo($rel, PATHINFO_EXTENSION) ?: 'png';
        $outRel  = ($dir !== '' ? $dir . '/' : '') . $stem . '__' . $pageKey . '_' . $domainHash . '.' . $ext;
        $outFile = $workingDir . '/' . $outRel;

        $o = ms_hero_style($W, $H, $styleOverride) + ['line1' => $line1, 'line2' => $line2, 'W' => $W, 'H' => $H];
        $r = ms_hero_overlay_render($srcFile, $outFile, $o);
        if (!empty($r['ok'])) { $b[$field] = $outRel; $done++; }
    }
    unset($b);
    return $done;
}

/**
 * Stamp hero images across the whole working site: homepage + core pages (site.json)
 * and each generated landing page (data/pages/*.json). Returns count stamped.
 * $styleOverride can pin pos/sizes/colours (else scaled defaults).
 */
function ms_stamp_hero_images(string $workingDir, array $params, array $styleOverride = []): int {
    if (ms_convert_bin() === null) return 0;

    $city = trim($params['city'] ?? '');
    $ss   = trim($params['SS'] ?? '');
    $siteCityLine = $city !== '' ? ($ss !== '' ? "{$city}, {$ss}" : $city) : '';

    if (!ms_materialize_uploads($workingDir)) return 0;

    $domain     = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $domainHash = substr(sha1($domain !== '' ? $domain : $workingDir), 0, 8);
    $count = 0;

    // 1) site.json — homepage content_blocks + core pages
    $sf   = $workingDir . '/data/site.json';
    $data = json_decode((string)@file_get_contents($sf), true);
    if (is_array($data)) {
        $changed = false;
        $kwHome = $data['seo']['primary_keyword'] ?? '';
        if (isset($data['content_blocks']) && is_array($data['content_blocks'])) {
            $n = ms_stamp_blocks($data['content_blocks'], $kwHome, $siteCityLine, $workingDir, 'home', $domainHash, $styleOverride);
            if ($n > 0) { $count += $n; $changed = true; }
        }
        foreach (($data['pages'] ?? []) as $i => &$pg) {
            if (!is_array($pg) || !isset($pg['content_blocks'])) continue;
            $kw = $pg['seo']['primary_keyword'] ?? '';
            $n = ms_stamp_blocks($pg['content_blocks'], $kw, $siteCityLine, $workingDir, 'p' . $i, $domainHash, $styleOverride);
            if ($n > 0) { $count += $n; $changed = true; }
        }
        unset($pg);
        if ($changed) ms_overlay_write_json($sf, $data);
    }

    // 2) generated landing pages — each carries its own city_vars
    foreach (glob($workingDir . '/data/pages/*.json') ?: [] as $pf) {
        $pd = json_decode((string)@file_get_contents($pf), true);
        if (!is_array($pd) || !isset($pd['content_blocks'])) continue;
        $cv    = $pd['city_vars'] ?? [];
        $pcity = trim($cv['city'] ?? $city);
        $pss   = trim($cv['SS'] ?? $ss);
        $cityLine = $pcity !== '' ? ($pss !== '' ? "{$pcity}, {$pss}" : $pcity) : $siteCityLine;
        $kw = $pd['seo']['primary_keyword'] ?? '';
        $n = ms_stamp_blocks($pd['content_blocks'], $kw, $cityLine, $workingDir, basename($pf, '.json'), $domainHash, $styleOverride);
        if ($n > 0) { ms_overlay_write_json($pf, $pd); $count += $n; }
    }

    return $count;
}
