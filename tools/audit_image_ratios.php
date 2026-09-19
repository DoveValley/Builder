<?php
/**
 * Ratio-drift audit — flags photo slots whose CURRENT stored image no longer matches
 * the fixed aspect ratio its block type's CSS actually renders it at.
 *
 * Why this can happen: img_fit_to() (includes/media_lib.php) crops a NEW upload to
 * match whatever pixel size the slot's CURRENT image already is — not to the block
 * type's designed ratio. If a block's CSS ratio was ever changed after some images
 * were already in place, or an image was ever written into a slot by something other
 * than Pic Drop (a direct site.json/template edit, an import), the stored file can
 * silently be the wrong shape — object-fit:cover then crops it further at render time,
 * in a way nothing in the admin surfaces.
 *
 * This is read-only. It reports; it does not recrop anything (re-cropping requires a
 * human decision about which part of the image to keep, made through Pic Drop's
 * adjuster, not a script).
 *
 * Deliberately scoped to block types with a CSS-enforced FIXED ratio (object-fit:cover
 * + aspect-ratio or a fixed w×h crop target). map_info/wide_banner/hero_grid/hero/
 * hero_split's bg-photo mode have no fixed ratio by design — see docs/content-blocks.md
 * discussion in the picdrop crop hints — so there's nothing to "drift" from and they
 * are intentionally NOT checked here. That is stated below, not silent.
 *
 * Usage:  php tools/audit_image_ratios.php            (every site under sites/)
 *         php tools/audit_image_ratios.php water-site  (one site)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

define('BASE', dirname(__DIR__));

// Fixed-ratio block types: block_type.leaf_field => target aspect ratio (w/h).
// Source of truth is style.src.css's aspect-ratio/object-fit rules for each class —
// see includes/picdrop.php's picdrop_crop_hint() for the same mapping in prose.
const FIXED_RATIOS = [
    'hero_split.hs_photo'     => 4 / 3,
    'feature_split.fs_photo'  => 4 / 3,
    'image_features.if_photo' => 4 / 3,
    'gallery.photo'           => 4 / 3,
];

// image_left/image_right/image_text don't have one fixed ratio in CSS — the block's
// own photo_ratio/it_ratio FIELD is the target, read per-block below.
const RATIO_FIELD_BLOCKS = [
    'image_left'  => ['photo', 'photo_ratio'],
    'image_right' => ['photo', 'photo_ratio'],
    'image_text'  => ['it_photo', 'it_ratio'],
];

const RATIO_KEYWORDS = ['landscape' => 2.0, 'square' => 1.0, 'portrait' => 3 / 4];

const TOLERANCE = 0.12; // 12% — well above img_fit_to()'s own 2%, so this only flags real drift, not rounding.

function resolve_photo(string $site, string $value): ?string {
    $v = trim($value);
    if ($v === '' || str_contains($v, '{') || preg_match('#^(https?:)?//#i', $v)) return null;
    $v = ltrim($v, '/');
    if (str_contains($v, '..')) return null;
    $candidates = [BASE . '/' . $v];
    if (str_starts_with($v, 'uploads/')) $candidates[] = BASE . '/sites/' . $site . '/' . $v;
    foreach ($candidates as $p) if (is_file($p)) return $p;
    return null;
}

function check_block(string $site, string $pageLabel, array $block, array &$findings, array &$checkedCount): void {
    $type = (string) ($block['type'] ?? '');

    foreach (FIXED_RATIOS as $key => $targetRatio) {
        [$bt, $field] = explode('.', $key, 2);
        if ($bt !== $type) continue;
        audit_one($site, $pageLabel, $type, $field, (string) ($block[$field] ?? ''), $targetRatio, $findings, $checkedCount);
    }

    if (isset(RATIO_FIELD_BLOCKS[$type])) {
        [$field, $ratioField] = RATIO_FIELD_BLOCKS[$type];
        $ratioVal = $block[$ratioField] ?? 'landscape';
        if ($ratioVal === 'auto' || !isset(RATIO_KEYWORDS[$ratioVal])) return; // no fixed shape to drift from
        audit_one($site, $pageLabel, $type, $field, (string) ($block[$field] ?? ''), RATIO_KEYWORDS[$ratioVal], $findings, $checkedCount);
    }

    // gallery_images is a repeater — the FIXED_RATIOS 'gallery.photo' entry above
    // matches the block type, but the image lives one level down.
    if ($type === 'gallery') {
        foreach (($block['gallery_images'] ?? []) as $img) {
            audit_one($site, $pageLabel, 'gallery', 'photo', (string) ($img['photo'] ?? ''), FIXED_RATIOS['gallery.photo'], $findings, $checkedCount);
        }
    }
}

function audit_one(string $site, string $pageLabel, string $type, string $field, string $value, float $targetRatio, array &$findings, array &$checkedCount): void {
    if ($value === '') return;
    $fs = resolve_photo($site, $value);
    if ($fs === null) return; // missing file is a different problem, not ratio drift
    $sz = @getimagesize($fs);
    if (!$sz || empty($sz[0]) || empty($sz[1])) return;
    $checkedCount[0]++;
    $actual = $sz[0] / $sz[1];
    $diff   = abs($actual - $targetRatio) / $targetRatio;
    if ($diff > TOLERANCE) {
        $findings[] = sprintf(
            "%-14s %-40s %-16s %-10s stored %dx%d (%.2f) vs expected %.2f — %.0f%% off",
            $site, $pageLabel, $type, $field, $sz[0], $sz[1], $actual, $targetRatio, $diff * 100
        );
    }
}

$onlySite = $argv[1] ?? '';
$sites = $onlySite !== ''
    ? [$onlySite]
    : array_values(array_filter(array_map('basename', glob(BASE . '/sites/*')), fn($s) => is_dir(BASE . '/sites/' . $s . '/data')));

echo "Checking: " . implode(', ', array_keys(FIXED_RATIOS)) . ", gallery.photo, image_left/image_right/image_text (own ratio field)\n";
echo "NOT checked (no fixed ratio by design): hero, hero_split.hs_bg_photo, hero_grid, wide_banner, map_info, tab_services\n\n";

$findings = [];
$checked  = [0];

foreach ($sites as $site) {
    $siteJsonPath = BASE . "/sites/$site/data/site.json";
    if (!is_file($siteJsonPath)) continue;
    $siteData = json_decode((string) file_get_contents($siteJsonPath), true) ?: [];

    foreach (($siteData['content_blocks'] ?? []) as $block) {
        check_block($site, 'home', $block, $findings, $checked);
    }
    foreach (($siteData['pages'] ?? []) as $page) {
        $label = 'core:' . ($page['slug'] ?? ($page['id'] ?? '?'));
        foreach (($page['content_blocks'] ?? []) as $block) {
            check_block($site, $label, $block, $findings, $checked);
        }
    }

    foreach (glob(BASE . "/sites/$site/data/pages/*.json") as $pf) {
        if (str_ends_with($pf, '.bak')) continue;
        $page = json_decode((string) file_get_contents($pf), true);
        if (!is_array($page)) continue;
        $label = 'landing:' . ($page['slug'] ?? basename($pf));
        foreach (($page['content_blocks'] ?? []) as $block) {
            check_block($site, $label, $block, $findings, $checked);
        }
    }
}

echo $checked[0] . " fixed-ratio slot(s) checked across " . count($sites) . " site(s).\n\n";

if (!$findings) {
    echo "No ratio drift found.\n";
    exit(0);
}

echo count($findings) . " slot(s) drifted from their block type's designed ratio:\n\n";
foreach ($findings as $f) echo "  $f\n";
exit(1);
