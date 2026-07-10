<?php
/**
 * Per-site visual identity for the multisite build (coordinated).
 *
 * One step, run after ms_differentiate_working_dir() and before the 4c image
 * prune, so anything it generates exists and is referenced:
 *   1. Pick a Theme Preset  — `theme_preset` CSV column (id or name),
 *      else a deterministic hash rotate off the domain (ms_variant, salt 'theme').
 *   2. Apply it             — merge preset.theme→data['theme'], preset.header→data['header'].
 *   3. Generate logo        — (added next) raster wordmark from {business} in preset colors.
 *   4. Generate favicon      — (added next) monogram tile in preset colors.
 *
 * Presets live per-niche at sites/{master}/multisite/theme_presets.json.
 * Everything is deterministic per domain so rebuilds stay byte-identical.
 */

require_once __DIR__ . '/../layout_variations.php';   // ms_variant()
if (!function_exists('ms_convert_bin')) require_once __DIR__ . '/image_overlay.php'; // ImageMagick helpers

/** Load this master's Theme Presets (array, or []). */
function ms_load_theme_presets(string $masterId): array {
    $file = BASE_DIR . '/sites/' . $masterId . '/multisite/theme_presets.json';
    $pd = @json_decode((string)@file_get_contents($file), true);
    return is_array($pd['presets'] ?? null) ? array_values($pd['presets']) : [];
}

/**
 * Choose the preset for this row. Explicit `theme_preset` (matches an id or a
 * name, case-insensitive; a bare number is treated as a 1-based index) wins;
 * blank falls back to a deterministic hash rotate off the domain.
 */
function ms_pick_theme_preset(string $masterId, array $params): ?array {
    $presets = ms_load_theme_presets($masterId);
    if (!$presets) return null;

    $sel = trim((string)($params['theme_preset'] ?? ''));
    if ($sel !== '') {
        foreach ($presets as $p) {
            if ((string)($p['id'] ?? '') === $sel)                 return $p;
            if (strcasecmp((string)($p['name'] ?? ''), $sel) === 0) return $p;
        }
        if (ctype_digit($sel)) { $i = (int)$sel - 1; if (isset($presets[$i])) return $presets[$i]; }
        // Unrecognised value → fall through to the hash rotate rather than fail the row.
    }

    // Auto-assign: rotate deterministically across only the presets flagged for the
    // multisite rotation pool (in_rotation !== false). If none are flagged, fall back
    // to all so a build never fails for lack of a pool.
    $pool = array_values(array_filter($presets, fn($p) => ($p['in_rotation'] ?? true) !== false));
    if (!$pool) $pool = $presets;
    $domain = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $idx = ms_variant($domain, count($pool), 'theme');
    return $pool[$idx] ?? $pool[0];
}

/** Perceived-luminance test — true if the color is light (→ use dark text on it). */
function ms_is_light_color(string $hex): bool {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return true;
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    return (0.299*$r + 0.587*$g + 0.114*$b) > 150;
}

/**
 * Compact wordmark lockup: first word on line 1, the remaining words on line 2
 * (like the master's "KATY / PEST PROS"), left-justified. One-word names stay a
 * single line. Returns the text with an embedded newline.
 */
function ms_wordmark_lines(string $business): string {
    $words = preg_split('/\s+/', trim($business));
    if (count($words) < 2) return trim($business);
    return $words[0] . "\n" . implode(' ', array_slice($words, 1));
}

/** Run a convert pipeline (array of args). Returns true on success + output file. */
function ms_convert_run(array $cmd, string $out): bool {
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $o, $rc);
    return $rc === 0 && is_file($out) && filesize($out) > 0;
}

/**
 * Render a bug icon as an ACCENT-colored silhouette centered on a DARK rounded tile
 * (both preset colors). $iconPath = an SVG in the master's multisite/icons/. Used for
 * the logo mark + the favicon. Returns true on success.
 */
function ms_render_bug_tile(string $iconPath, string $accent, string $dark, int $size, string $out): bool {
    if (ms_convert_bin() === null || !is_file($iconPath)) return false;
    $bin = ms_convert_bin();
    $r   = max(6, (int)round($size * 0.22));   // corner radius
    $bug = (int)round($size * 0.64);           // bug fills ~64% of the tile
    $tmpBug = $out . '.bug.png';
    // 1. bug SVG → solid accent silhouette (alpha preserved)
    if (!ms_convert_run([$bin, '-background', 'none', $iconPath, '-resize', $bug . 'x' . $bug,
                         '-channel', 'RGB', '-fill', $accent, '-colorize', '100', '+channel', $tmpBug], $tmpBug)) {
        return false;
    }
    // 2. dark rounded tile + composite the bug centered
    $ok = ms_convert_run([$bin, '-size', $size . 'x' . $size, 'xc:none',
                          '-fill', $dark, '-draw', 'roundrectangle 0,0,' . ($size - 1) . ',' . ($size - 1) . ',' . $r . ',' . $r,
                          $tmpBug, '-gravity', 'center', '-composite', '-strip', $out], $out);
    @unlink($tmpBug);
    return $ok;
}

/**
 * Generate the per-site logo (+ favicon) in the applied preset's colors:
 *   • two-tone wordmark — first word (line 1) in the ACCENT color, remaining words
 *     (line 2) in the DARK color, left-justified, like the master's "KATY / PEST PROS"
 *   • a bug mark (accent silhouette on a dark tile) to the LEFT of the wordmark
 *   • the same bug tile written as the favicon (128px)
 * Sets header.logo (+ header.favicon). Returns the logo path or null.
 * Each file is inherently unique per site (name + colors + bug); a seeded pointsize
 * jitter adds byte/dimension variance. $iconPath null → wordmark only.
 */
function ms_generate_logo(array &$data, string $workingDir, string $business, string $seed, ?string $iconPath = null): ?string {
    $business = trim($business);
    if ($business === '' || ms_convert_bin() === null) return null;
    $bin  = ms_convert_bin();
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    if (!is_file($font)) return null;

    $theme  = $data['theme'];
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['accent_color'] ?? '') ? $theme['accent_color'] : '#fd783b';
    // Dark brand color for line 2 + tile: prefer heading_color (dedicated dark text),
    // then footer_bg, then header_bg. (header_bg now follows the nav/accent at render,
    // so it's the least reliable source for "the dark color".)
    $dark = '#120575';
    foreach (['heading_color', 'footer_bg', 'header_bg'] as $f) {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $theme[$f] ?? '')) { $dark = $theme[$f]; break; }
    }
    // Wordmark: line 1 = accent; line 2 = dark on a light top bar, else white.
    $topBg      = (string)($theme['header_top_bg'] ?? '#ffffff');
    $line1Color = $accent;
    $line2Color = ms_is_light_color($topBg) ? $dark : '#ffffff';

    $pointsize = 68 + ms_seed_int($seed . '|logo_size', 9);   // 68..76

    ms_materialize_uploads($workingDir);   // uploads/ may be a symlink to the shared master
    $upl = $workingDir . '/uploads';
    @mkdir($upl, 0775, true);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($business)), '-') ?: 'wordmark';
    $rel  = 'uploads/logo_' . $slug . '.png';
    $out  = $workingDir . '/' . $rel;
    $tmp  = $upl . '/_lg_' . getmypid();

    // ── two-tone wordmark (line 1 accent, line 2 dark), left-justified ──
    $words = preg_split('/\s+/', $business);
    $line1 = $words[0];
    $line2 = count($words) > 1 ? implode(' ', array_slice($words, 1)) : '';
    $wm    = $tmp . '_wm.png';
    if ($line2 !== '') {
        $l1 = $tmp . '_l1.png'; $l2 = $tmp . '_l2.png';
        ms_convert_run([$bin, '-background', 'none', '-fill', $line1Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line1, $l1], $l1);
        ms_convert_run([$bin, '-background', 'none', '-fill', $line2Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line2, $l2], $l2);
        ms_convert_run([$bin, $l1, $l2, '-background', 'none', '-gravity', 'west', '-append', $wm], $wm);
        @unlink($l1); @unlink($l2);
    } else {
        ms_convert_run([$bin, '-background', 'none', '-fill', $line1Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line1, $wm], $wm);
    }
    if (!is_file($wm)) return null;
    $wmDim = getimagesize($wm); $wmH = (int)($wmDim[1] ?? $pointsize);

    // ── bug mark + favicon (if the preset supplies an icon) ──
    $composited = false;
    if ($iconPath && is_file($iconPath)) {
        $tile = $tmp . '_tile.png';
        if (ms_render_bug_tile($iconPath, $accent, $dark, $wmH, $tile)) {
            $gap = (int)round($wmH * 0.16);
            // tile (padded on the right by the gap) + wordmark, appended left→right
            $composited = ms_convert_run([$bin, $tile, '-background', 'none', '-gravity', 'west', '-extent', ($wmH + $gap) . 'x' . $wmH,
                                          $wm, '-background', 'none', '-gravity', 'west', '+append', '-strip', $out], $out);
            @unlink($tile);
            $favRel = 'uploads/favicon_' . $slug . '.png';
            if (ms_render_bug_tile($iconPath, $accent, $dark, 128, $workingDir . '/' . $favRel)) {
                $data['header']['favicon'] = $favRel;
            }
        }
    }
    if (!$composited) {                       // wordmark-only fallback
        if (is_file($out)) @unlink($out);
        rename($wm, $out);
    } else {
        @unlink($wm);
    }

    $data['header']['logo'] = $rel;
    return $rel;
}

/** Merge a preset's theme + header fragments into the site data (in place). */
function ms_apply_theme_preset(array &$data, array $preset): void {
    foreach (($preset['theme'] ?? []) as $k => $v) {
        if ($k === 'skins' && is_array($v)) {
            $data['theme']['skins'] = array_replace_recursive($data['theme']['skins'] ?? [], $v);
        } else {
            $data['theme'][$k] = $v;
        }
    }
    foreach (($preset['header'] ?? []) as $k => $v) {
        $data['header'][$k] = $v;
    }
}

/**
 * Apply the coordinated visual identity to a working-dir site.
 * Returns ['applied'=>bool, 'preset'=>string label].
 */
function ms_apply_visual_identity(string $workingDir, array $params, string $masterId): array {
    $sf = $workingDir . '/data/site.json';
    if (!is_file($sf)) return ['applied' => false, 'preset' => ''];
    $data = json_decode((string)file_get_contents($sf), true);
    if (!is_array($data)) return ['applied' => false, 'preset' => ''];

    $preset = ms_pick_theme_preset($masterId, $params);
    if (!$preset) return ['applied' => false, 'preset' => ''];

    // 2. Theme.
    ms_apply_theme_preset($data, $preset);

    // 3-4. Logo (two-tone wordmark + bug mark) + favicon, in the preset's colors.
    $business = trim((string)($params['business'] ?? ($data['site_vars']['business'] ?? '')));
    $domain   = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $iconFile = trim((string)($preset['icon'] ?? ''));
    $iconPath = $iconFile !== '' ? BASE_DIR . '/sites/' . $masterId . '/multisite/icons/' . basename($iconFile) : null;
    $logoRel  = ms_generate_logo($data, $workingDir, $business, $domain, $iconPath);

    $tmp = $sf . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $sf);

    return [
        'applied' => true,
        'preset'  => (string)($preset['name'] ?? ('#' . ($preset['id'] ?? '?'))),
        'logo'    => $logoRel,
    ];
}
