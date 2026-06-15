<?php
/**
 * scrape_images.php — Download all images from a site into the media library
 *
 * Usage:
 *   php scrape_images.php https://katypestpros.com
 *   php scrape_images.php https://katypestpros.com --dry-run
 */

define('MEDIA_DIR',  __DIR__ . '/uploads/media/');
define('MEDIA_JSON', __DIR__ . '/data/media.json');
define('MAX_WIDTH',  1600);
define('MAX_BYTES',  20 * 1024 * 1024); // 20 MB source limit

$dry_run = in_array('--dry-run', $argv);
$base_url = null;
foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--dry-run') continue;
    if (filter_var($arg, FILTER_VALIDATE_URL)) { $base_url = rtrim($arg, '/'); break; }
}
if (!$base_url) {
    echo "Usage: php scrape_images.php [--dry-run] https://example.com\n";
    exit(1);
}

// ── helpers ──────────────────────────────────────────────────────────────────

function fetch(string $url): string {
    $ctx = stream_context_create(['http' => [
        'timeout'         => 20,
        'follow_location' => true,
        'header'          => "User-Agent: Mozilla/5.0 (compatible; media-scraper/1.0)\r\n",
    ]]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

function load_media(): array {
    if (!file_exists(MEDIA_JSON)) return [];
    $data = json_decode(file_get_contents(MEDIA_JSON), true);
    return is_array($data) ? $data : [];
}

function save_media(array $items): void {
    file_put_contents(MEDIA_JSON, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function already_scraped(array $items, string $source_url): bool {
    foreach ($items as $item) {
        if (($item['source_url'] ?? '') === $source_url) return true;
    }
    return false;
}

function optimize_and_save(string $tmp, string $dest, string $mime): bool {
    if (!extension_loaded('gd')) {
        return copy($tmp, $dest);
    }
    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        default      => false,
    };
    if (!$src) return copy($tmp, $dest);

    $ow = imagesx($src);
    $oh = imagesy($src);
    if ($ow > MAX_WIDTH) {
        $nw  = MAX_WIDTH;
        $nh  = (int) round($oh * MAX_WIDTH / $ow);
        $dst = imagecreatetruecolor($nw, $nh);
        // preserve transparency
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $trans);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
        imagedestroy($src);
        $src = $dst;
        $ow = $nw; $oh = $nh;
    }

    $ok = imagewebp($src, $dest, 82);
    imagedestroy($src);
    return $ok;
}

// ── discover pages ────────────────────────────────────────────────────────────

echo "Discovering pages on $base_url …\n";

$host = parse_url($base_url, PHP_URL_HOST);
$visited_pages  = [];
$queue          = [$base_url . '/'];
$image_urls     = [];

// Try sitemap first
$sitemap_xml = fetch($base_url . '/sitemap.xml');
if ($sitemap_xml) {
    preg_match_all('/<loc>(https?:\/\/[^<]+)<\/loc>/i', $sitemap_xml, $m);
    foreach ($m[1] as $loc) {
        $loc = trim($loc);
        if (parse_url($loc, PHP_URL_HOST) === $host) {
            $queue[] = $loc;
        }
    }
    echo "  Sitemap: found " . count($queue) . " URLs\n";
}

$queue = array_unique($queue);

foreach ($queue as $page_url) {
    if (isset($visited_pages[$page_url])) continue;
    $visited_pages[$page_url] = true;

    $html = fetch($page_url);
    if (!$html) continue;

    // Extract img src and srcset
    preg_match_all('/\ssrc=["\']([^"\']+)["\']/', $html, $m1);
    preg_match_all('/\ssrcset=["\']([^"\']+)["\']/', $html, $m2);
    preg_match_all('/data-bg-src=["\']([^"\']+)["\']/', $html, $m3);

    $srcs = array_merge($m1[1], $m3[1]);
    foreach ($srcs as $src) {
        $src = trim($src);
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $src)) {
            if (str_starts_with($src, '//'))  $src = 'https:' . $src;
            if (str_starts_with($src, '/'))   $src = $base_url . $src;
            if (!str_starts_with($src, 'http')) continue;
            $image_urls[$src] = true;
        }
    }
    // srcset has multiple "url width" pairs
    foreach ($m2[1] as $srcset) {
        foreach (explode(',', $srcset) as $part) {
            $u = trim(preg_split('/\s+/', trim($part))[0]);
            if (preg_match('/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i', $u)) {
                if (str_starts_with($u, '//')) $u = 'https:' . $u;
                if (str_starts_with($u, '/'))  $u = $base_url . $u;
                if (!str_starts_with($u, 'http')) continue;
                $image_urls[$u] = true;
            }
        }
    }
}

$image_urls = array_keys($image_urls);
echo "  Found " . count($image_urls) . " unique image URLs\n\n";

// ── download images ───────────────────────────────────────────────────────────

if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);

$media  = load_media();
$added  = 0;
$skip   = 0;
$failed = 0;

foreach ($image_urls as $img_url) {
    // Skip already imported
    if (already_scraped($media, $img_url)) { $skip++; continue; }

    // Skip SVG (not raster)
    if (preg_match('/\.svg(\?.*)?$/i', $img_url)) { $skip++; continue; }

    if ($dry_run) {
        echo "  [DRY] $img_url\n";
        $added++;
        continue;
    }

    // Download
    $raw = fetch($img_url);
    if (!$raw || strlen($raw) < 100) { echo "  SKIP (empty): $img_url\n"; $failed++; continue; }
    if (strlen($raw) > MAX_BYTES)    { echo "  SKIP (too big): $img_url\n"; $skip++; continue; }

    // Detect MIME
    $tmp = tempnam(sys_get_temp_dir(), 'img_');
    file_put_contents($tmp, $raw);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed)) {
        @unlink($tmp);
        echo "  SKIP (not image, got $mime): $img_url\n";
        $skip++;
        continue;
    }

    // Build filename from source URL path
    $path    = parse_url($img_url, PHP_URL_PATH);
    $base    = pathinfo($path, PATHINFO_FILENAME);
    $base    = preg_replace('/[^a-z0-9_-]/i', '-', $base);
    $base    = strtolower(trim($base, '-'));
    $filename = $base . '_' . substr(md5($img_url), 0, 6) . '.webp';
    $dest     = MEDIA_DIR . $filename;

    if (!optimize_and_save($tmp, $dest, $mime)) {
        @unlink($tmp);
        echo "  FAIL (save): $img_url\n";
        $failed++;
        continue;
    }
    @unlink($tmp);

    [$w, $h] = @getimagesize($dest) ?: [0, 0];

    $media[] = [
        'filename'   => $filename,
        'url'        => 'uploads/media/' . $filename,
        'width'      => $w,
        'height'     => $h,
        'size'       => filesize($dest),
        'alt'        => '',
        'tags'       => [],
        'source_url' => $img_url,
        'added_at'   => date('Y-m-d H:i:s'),
    ];

    save_media($media);
    $added++;
    echo "  OK: $filename  ({$w}x{$h})  <- $img_url\n";
}

echo "\nDone. Added: $added  Skipped: $skip  Failed: $failed\n";
