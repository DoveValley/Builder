<?php
/**
 * One-time setup tool: builds a pool of 10 visually-similar-but-distinct variants
 * for every hero background image a master actually uses (home's hero_bg_image /
 * hero_split's hs_bg_photo, wherever one is set in site.json or templates.json),
 * so ms_hero_bg_pool_apply() has something to pick from per domain.
 *
 * The fine-grained per-domain crop/tone jitter already applied to every content
 * image at build time (ms_perturb_image()) is subtle on purpose — a 1-2% crop, not
 * something you could look at and call different. This is the other, deliberately
 * visible layer: a small curated set, inspectable before any build happens (see
 * the "Hero background pool" section in the Test Lab), not just a hash nobody can
 * preview. The two layers stack — this axis narrows which TEXTURE a domain gets,
 * the existing per-domain jitter still perturbs whichever one it lands on.
 *
 * Variant 0 is always the current, already-live file, unchanged — so a master
 * with a pool is a pure superset of what's already shipping; no existing domain's
 * current background is invalidated by running this.
 *
 * Transforms are plain crop/zoom + brightness/contrast + a few degrees of
 * rotation — no hue/saturation, because the real backgrounds this was built
 * against (appliance/mold/pest) are literally greyscale source files; a hue
 * shift on zero-chroma pixels is a no-op. Deterministic per (file, index), so
 * re-running this tool reproduces byte-identical variants rather than silently
 * re-rolling a master's whole pool.
 *
 * Shared, niche-agnostic — the same script runs against any master. Confirmed
 * empirically 2026-11-01: appliance's and pest's CURRENT background files are
 * byte-identical (same texture asset, reused across those two niches) — each
 * master's pool is salted by its own master_id, so their two pools diverge even
 * though they start from the same source bytes.
 *
 * Usage: php tools/generate_hero_bg_pool.php <master_id> [--dry-run] [--force] [--replace-original]
 *   --force regenerates variants even if a pool entry already exists for a file.
 *   --replace-original also generates variant 0 instead of keeping the literal
 *     current file — for the one real case this mattered (2026-11-01): appliance
 *     and pest started from a byte-identical shared texture, so even after each
 *     got its own 9 variants, the two pools' index-0 entries were still identical
 *     to each other. Normally leave this off — variant 0 being the untouched,
 *     already-live file is what makes adding a pool to an existing master a pure
 *     superset of what's already shipping.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';

$masterId = null; $dryRun = false; $force = false; $replaceOriginal = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--force') $force = true;
    elseif ($a === '--replace-original') $replaceOriginal = true;
    elseif ($masterId === null) $masterId = $a;
}
if (!$masterId || !preg_match('/^[a-z0-9_-]+$/', $masterId)) {
    fwrite(STDERR, "usage: php tools/generate_hero_bg_pool.php <master_id> [--dry-run] [--force] [--replace-original]\n");
    exit(2);
}
$masterDir = BASE_DIR . '/sites/' . $masterId;
if (!is_dir($masterDir)) { fwrite(STDERR, "No such master: $masterId\n"); exit(2); }

exec('which convert 2>/dev/null', $out, $rc);
if ($rc !== 0) { fwrite(STDERR, "ImageMagick 'convert' not found on PATH\n"); exit(2); }

const POOL_SIZE = 10;

/** Any string field on a hero-type block whose key names a background image. */
function bg_fields_in_block(array $block): array {
    if (strncmp($block['type'] ?? '', 'hero', 4) !== 0) return [];
    $found = [];
    foreach ($block as $k => $v) {
        if (!is_string($k) || stripos($k, 'bg') === false) continue;
        if (!is_string($v) || $v === '') continue;
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $v)) continue;
        $found[] = $v;
    }
    return $found;
}

/** Strip a leading "sites/{master}/" prefix — master files store the repo-relative
 *  form, but a clone's own copy (what the real build/pick sees) only ever has the
 *  bare "uploads/..." form. Pool keys must match the LATTER. */
function to_clone_relative(string $path, string $masterId): string {
    $prefix = 'sites/' . $masterId . '/';
    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
}

// ── Collect every distinct background file actually referenced ────────────────
$found = [];
$siteJson = json_decode((string) @file_get_contents($masterDir . '/data/site.json'), true) ?: [];
foreach ($siteJson['content_blocks'] ?? [] as $b) {
    if (!is_array($b)) continue;
    foreach (bg_fields_in_block($b) as $v) $found[$v] = true;
}
$templates = json_decode((string) @file_get_contents($masterDir . '/data/templates.json'), true) ?: [];
foreach ($templates as $t) {
    foreach ($t['content_blocks'] ?? [] as $b) {
        if (!is_array($b)) continue;
        foreach (bg_fields_in_block($b) as $v) $found[$v] = true;
    }
}

if (!$found) {
    echo "No hero background fields found for $masterId — nothing to do.\n";
    exit(0);
}

$poolFile = $masterDir . '/multisite/hero_bg_pool.json';
$pools = is_file($poolFile) ? (json_decode((string) @file_get_contents($poolFile), true) ?: []) : [];

$built = 0; $skipped = 0;

foreach (array_keys($found) as $origRaw) {
    // Resolve to a real file on disk — source may be stored repo-relative
    // ("sites/{master}/uploads/media/x.webp") or already bare ("uploads/media/x.webp").
    $srcAbs = is_file(BASE_DIR . '/' . $origRaw) ? BASE_DIR . '/' . $origRaw
        : (is_file($masterDir . '/' . $origRaw) ? $masterDir . '/' . $origRaw : null);
    if ($srcAbs === null) { fwrite(STDERR, "skip (file not found): $origRaw\n"); continue; }

    $origRel = to_clone_relative($origRaw, $masterId);   // the pool KEY — clone-relative form

    if (isset($pools[$origRel]) && !$force) { echo "skip (already pooled): $origRel\n"; $skipped++; continue; }

    $ext  = pathinfo($srcAbs, PATHINFO_EXTENSION);
    $stem = pathinfo($srcAbs, PATHINFO_FILENAME);
    $dir  = dirname($srcAbs);

    $pool = $replaceOriginal ? [] : [$origRel];   // variant 0 = the current file, unchanged (unless replacing it)
    for ($i = $replaceOriginal ? 0 : 1; $i < POOL_SIZE; $i++) {
        $seed = crc32($masterId . '|' . $origRel . '|' . $i);
        // Deterministic small jitters, each in a visibly-real-but-subtle band: 3-9%
        // zoom/crop (shifts which part of the texture shows, re-framing a wide thin
        // banner meaningfully without needing a dramatic change) + ±4% brightness +
        // ±3% contrast. No rotation: these banners are very wide (e.g. 1900x600,
        // ~3:1) — even a couple degrees of tilt pushes the rotated canvas's corners
        // outside the original frame, and a crop/extent back to WxH that doesn't
        // account for that leaves visible white wedges at the top/bottom edges
        // (found by actually looking at variant output, not assumed safe).
        $zoomPct   = 3 + ($seed % 601) / 100.0;                    // 3.0 .. 9.0
        $gravities = ['NorthWest', 'North', 'NorthEast', 'West', 'Center', 'East', 'SouthWest', 'South', 'SouthEast'];
        $gravity   = $gravities[($seed >> 4) % count($gravities)];
        $brightness = 100 + ((($seed >> 8) % 9) - 4);              // 96 .. 104
        $contrast   = 100 + ((($seed >> 12) % 7) - 3);             // 97 .. 103

        $outName = $stem . '-bgv' . $i . '.' . $ext;
        $outAbs  = $dir . '/' . $outName;
        $outRel  = 'uploads/media/' . $outName;

        if ($dryRun) {
            echo "[dry-run] would write $outRel (zoom={$zoomPct}% gravity=$gravity bright=$brightness contrast=$contrast)\n";
            $pool[] = $outRel;
            continue;
        }

        $g = @getimagesize($srcAbs);
        if (!$g) { fwrite(STDERR, "skip variant (unreadable image): $srcAbs\n"); continue; }
        [$W, $H] = $g;
        $cropW = (int) round($W * (1 - $zoomPct / 100));
        $cropH = (int) round($H * (1 - $zoomPct / 100));

        $cmd = sprintf(
            'convert %s -gravity %s -crop %dx%d+0+0 +repage -resize %dx%d! ' .
            '-brightness-contrast %d,%d %s 2>&1',
            escapeshellarg($srcAbs), escapeshellarg($gravity), $cropW, $cropH,
            $W, $H,
            (int) round($brightness - 100), (int) round(($contrast - 100) * 0.8),
            escapeshellarg($outAbs)
        );
        exec($cmd, $cmdOut, $cmdRc);
        if ($cmdRc !== 0 || !is_file($outAbs)) {
            fwrite(STDERR, "FAILED variant $i for $origRel: " . implode("\n", $cmdOut) . "\n");
            continue;
        }
        $pool[] = $outRel;
        echo "wrote $outRel\n";
    }

    if (!$dryRun) {
        $pools[$origRel] = $pool;
        $built++;
    }
}

if (!$dryRun) {
    if (!is_dir($masterDir . '/multisite')) mkdir($masterDir . '/multisite', 0775, true);
    file_put_contents($poolFile, json_encode($pools, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Pools written for $built file(s), $skipped skipped (already pooled) -> $poolFile\n";
} else {
    echo "[dry-run] no files written.\n";
}
