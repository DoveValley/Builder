<?php
/**
 * Per-site visual identity for the multisite build (coordinated).
 *
 * One step, run after ms_differentiate_working_dir() and before the 4c image
 * prune, so anything it generates exists and is referenced. TWO independent
 * rotation axes, picked separately so color variety and logo variety don't
 * move in lockstep:
 *   1. Pick a Theme Preset  — `theme_preset` CSV column (id or name), else a
 *      deterministic hash rotate off the domain (ms_variant, salt 'theme').
 *      Apply it — merge preset.theme→data['theme'], preset.header→data['header'].
 *   2. Pick a Logo Config   — `logo_config` CSV column, else a hash rotate off
 *      the domain (ms_variant, salt 'logo'). Resolves line1/line2 text from
 *      {business}/{city}/custom and an icon, independent of which theme preset
 *      this domain got — see ms_pick_logo_config()/ms_resolve_logo_lines().
 *   3. Generate logo + favicon — two-tone wordmark (+ bug-tile mark/favicon if
 *      the Logo Config has an icon) in the Theme Preset's colors.
 *
 * Presets live per-niche at sites/{master}/multisite/theme_presets.json;
 * Logo Configs at sites/{master}/multisite/logo_configs.json.
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

/** Load this master's Logo Library (array, or []). */
function ms_load_logo_configs(string $masterId): array {
    $file = BASE_DIR . '/sites/' . $masterId . '/multisite/logo_configs.json';
    $ld = @json_decode((string)@file_get_contents($file), true);
    return is_array($ld['logos'] ?? null) ? array_values($ld['logos']) : [];
}

/**
 * Choose the Logo Config for this row — same shape of logic as
 * ms_pick_theme_preset(), a fully independent rotation pool (a domain's logo
 * arrangement and its color theme vary on separate axes on purpose). Explicit
 * `logo_config` (matches an id or a name, case-insensitive; a bare number is a
 * 1-based index) wins; blank falls back to a deterministic hash rotate off the
 * domain, salted differently ('logo' vs theme's 'theme') so the two rotations
 * don't move in lockstep. Null means "no library yet" — callers fall back to
 * the zero-config default (see ms_resolve_logo_lines()).
 */
function ms_pick_logo_config(string $masterId, array $params): ?array {
    $logos = ms_load_logo_configs($masterId);
    if (!$logos) return null;

    $sel = trim((string)($params['logo_config'] ?? ''));
    if ($sel !== '') {
        foreach ($logos as $l) {
            if ((string)($l['id'] ?? '') === $sel)                 return $l;
            if (strcasecmp((string)($l['name'] ?? ''), $sel) === 0) return $l;
        }
        if (ctype_digit($sel)) { $i = (int)$sel - 1; if (isset($logos[$i])) return $logos[$i]; }
    }

    $pool = array_values(array_filter($logos, fn($l) => ($l['in_rotation'] ?? true) !== false));
    if (!$pool) $pool = $logos;
    $domain = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $idx = ms_variant($domain, count($pool), 'logo');
    return $pool[$idx] ?? $pool[0];
}

/** {business}/{city}/custom → the literal text for one logo line. Custom text
 *  gets a small, self-contained token substitution against the SAME $siteVars
 *  passed in — not includes/shortcodes.php's resolve_shortcodes(), which reads
 *  a global $data and would silently resolve against the wrong site during a
 *  multisite batch build (each domain has its own, non-global site_vars here). */
function ms_resolve_logo_text(string $source, string $custom, array $siteVars): string {
    if ($source === 'city')     return (string)($siteVars['city'] ?? '');
    if ($source === 'business') return (string)($siteVars['business'] ?? '');
    if (strpos($custom, '{') === false) return $custom;
    $city = (string)($siteVars['city'] ?? ''); $state = (string)($siteVars['state'] ?? ''); $ss = (string)($siteVars['SS'] ?? '');
    return strtr($custom, [
        '{business}'   => (string)($siteVars['business'] ?? ''),
        '{city}'       => $city,
        '{state}'      => $state,
        '{SS}'         => $ss,
        '{city_state}' => $city && $ss ? "{$city}, {$ss}" : $city . $ss,
    ]);
}

/**
 * Resolve a Logo Config (or null, meaning "no library set up yet") into the
 * concrete line1/line2 strings + absolute icon path ms_generate_logo() needs.
 * The single place both single-site and multisite call sites go through, so
 * the zero-config fallback (line1=business, line2=city, no icon — the closest
 * honest replacement for the old auto-split, since it's the one arrangement
 * that's exactly right whenever the business name doesn't already start with
 * the city) is defined exactly once.
 */
function ms_resolve_logo_lines(?array $config, array $siteVars, string $masterId): array {
    $line1Source = (string)($config['line1_source'] ?? 'business');
    $line2Source = (string)($config['line2_source'] ?? 'city');
    $line1Custom = (string)($config['line1_custom'] ?? '');
    $line2Custom = (string)($config['line2_custom'] ?? '');
    $icon = trim((string)($config['icon'] ?? ''));
    $iconPath = $icon !== '' ? BASE_DIR . '/sites/' . $masterId . '/multisite/icons/' . basename($icon) : null;
    if ($iconPath && !is_file($iconPath)) $iconPath = null;
    return [
        'line1'    => ms_resolve_logo_text($line1Source, $line1Custom, $siteVars),
        'line2'    => ms_resolve_logo_text($line2Source, $line2Custom, $siteVars),
        'iconPath' => $iconPath,
    ];
}

/** Perceived-luminance test — true if the color is light (→ use dark text on it). */
function ms_is_light_color(string $hex): bool {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return true;
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    return (0.299*$r + 0.587*$g + 0.114*$b) > 150;
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
 *   • two-tone wordmark — $line1 in the ACCENT color, $line2 in the DARK color,
 *     left-justified. What text ends up on each line is the CALLER's decision
 *     (see ms_resolve_logo_lines() — a Logo Config's line1/line2 source, or the
 *     zero-config default) — this function only renders, it never derives text
 *     from a business name itself anymore.
 *   • a bug mark (accent silhouette on a dark tile) to the LEFT of the wordmark
 *   • the same bug tile written as the favicon (128px)
 * Sets header.logo (+ header.favicon). Returns the logo path or null.
 * Each file is inherently unique per site (text + colors + bug); a seeded
 * pointsize jitter adds byte/dimension variance. $iconPath null → wordmark only.
 */
function ms_generate_logo(array &$data, string $workingDir, string $line1, string $line2, string $seed, ?string $iconPath = null): ?string {
    $line1 = trim($line1); $line2 = trim($line2);
    if ($line1 === '' && $line2 === '') return null;
    if (ms_convert_bin() === null) return null;
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
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($line1 . ' ' . $line2))), '-') ?: 'wordmark';
    $rel  = 'uploads/logo_' . $slug . '.png';
    $out  = $workingDir . '/' . $rel;
    $tmp  = $upl . '/_lg_' . getmypid();

    // ── two-tone wordmark (line 1 accent, line 2 dark), left-justified. Color
    // follows the LOGICAL line (1 or 2), not position — if one line is blank,
    // the other still renders in its own color, not the blank line's slot. ──
    $wm = $tmp . '_wm.png';
    if ($line1 !== '' && $line2 !== '') {
        $l1 = $tmp . '_l1.png'; $l2 = $tmp . '_l2.png';
        ms_convert_run([$bin, '-background', 'none', '-fill', $line1Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line1, $l1], $l1);
        ms_convert_run([$bin, '-background', 'none', '-fill', $line2Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line2, $l2], $l2);
        ms_convert_run([$bin, $l1, $l2, '-background', 'none', '-gravity', 'west', '-append', $wm], $wm);
        @unlink($l1); @unlink($l2);
    } elseif ($line1 !== '') {
        ms_convert_run([$bin, '-background', 'none', '-fill', $line1Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line1, $wm], $wm);
    } else {
        ms_convert_run([$bin, '-background', 'none', '-fill', $line2Color, '-font', $font, '-pointsize', (string)$pointsize, '-gravity', 'west', 'label:' . $line2, $wm], $wm);
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

    // 3-4. Logo (two-tone wordmark + bug mark) + favicon, in the preset's colors
    // but with text/icon from this domain's Logo Config — a fully independent
    // rotation axis from the theme preset (see ms_pick_logo_config()). A theme
    // preset's own `icon` field no longer drives the real logo; it's kept only
    // for that preset's own mini-preview in the Visual Identity library.
    $domain   = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $siteVars = [
        'business' => trim((string)($params['business'] ?? ($data['site_vars']['business'] ?? ''))),
        'city'     => trim((string)($params['city']     ?? ($data['site_vars']['city']     ?? ''))),
        'state'    => trim((string)($params['state']    ?? ($data['site_vars']['state']    ?? ''))),
        'SS'       => trim((string)($params['SS']       ?? ($data['site_vars']['SS']       ?? ''))),
    ];
    $logoConfig = ms_pick_logo_config($masterId, $params);
    $lines      = ms_resolve_logo_lines($logoConfig, $siteVars, $masterId);
    $logoRel    = ms_generate_logo($data, $workingDir, $lines['line1'], $lines['line2'], $domain, $lines['iconPath']);

    $tmp = $sf . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $sf);

    return [
        'applied' => true,
        'preset'  => (string)($preset['name'] ?? ('#' . ($preset['id'] ?? '?'))),
        'logo'    => $logoRel,
    ];
}
