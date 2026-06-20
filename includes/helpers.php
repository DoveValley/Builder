<?php
/**
 * Sanitize a user-supplied URL (button links, menu links, canonical URLs, etc.)
 * Only allows http(s), tel, mailto, in-page anchors, and relative/site-internal paths.
 * Blocks javascript:, data:, vbscript:, and other dangerous schemes.
 */
function sanitize_url(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    // Allow https/http, tel, mailto, absolute paths, anchors, and relative upload paths
    return preg_match('/^(https?:\/\/|tel:|mailto:|\/|#|uploads\/)/i', $raw) ? $raw : '';
}

/**
 * Strip executable content from an uploaded SVG before saving it: <script> tags,
 * on*="..." event handlers, and javascript: URIs. Returns false if the file
 * doesn't parse as valid XML (rejects it rather than saving something unverified).
 */
function sanitize_svg(string $svg): string|false {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok  = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOENT);
    libxml_clear_errors();
    if (!$ok) return false;

    $xpath = new DOMXPath($doc);
    foreach ($xpath->query('//*[translate(local-name(),"SCRIPT","script")="script"]') as $node) {
        $node->parentNode->removeChild($node);
    }
    foreach ($xpath->query('//@*') as $attr) {
        $name = strtolower($attr->nodeName);
        $value = trim($attr->nodeValue);
        if (str_starts_with($name, 'on') || stripos($value, 'javascript:') === 0) {
            $attr->ownerElement->removeAttribute($attr->nodeName);
        }
    }
    return $doc->saveXML();
}

function slugify($text) {
    $text = strtolower((string) $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function reserved_slugs() {
    return ['', 'admin', 'assets', 'data', 'includes', 'uploads', 'index', 'page', 'home', 'blog'];
}

function unique_slug($slug, $pages, $excludeId = null, $skipReserved = false) {
    $slug = slugify($slug);
    // Blog posts live at /blog/{slug}, not top-level, so reserved top-level route names
    // don't conflict with them. Pass $skipReserved=true for post slugs.
    if (!$skipReserved && ($slug === '' || in_array($slug, reserved_slugs(), true))) $slug = 'page';
    if ($skipReserved && $slug === '') $slug = 'post';
    $base = $slug; $i = 2;
    while (true) {
        $collision = false;
        foreach ($pages as $pid => $page) {
            if ($excludeId !== null && $pid === $excludeId) continue;
            if (($page['slug'] ?? '') === $slug) { $collision = true; break; }
        }
        if (!$collision) return $slug;
        $slug = $base . '-' . $i; $i++;
    }
}

function find_page_by_slug($pages, $slug) {
    foreach ($pages as $pid => $page) {
        if (($page['slug'] ?? '') === $slug) return [$pid, $page];
    }
    return [null, null];
}

function find_post_by_slug($posts, $slug) {
    foreach ($posts as $pid => $post) {
        if (($post['slug'] ?? '') === $slug) return [$pid, $post];
    }
    return [null, null];
}

function upload_image($fieldName, $prefix) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return false;
    return save_uploaded_file($_FILES[$fieldName]['tmp_name'], $prefix);
}

function upload_image_indexed($fieldName, $index, $prefix) {
    if (!isset($_FILES[$fieldName]['error'][$index]) || $_FILES[$fieldName]['error'][$index] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$fieldName]['error'][$index] !== UPLOAD_ERR_OK) return false;
    return save_uploaded_file($_FILES[$fieldName]['tmp_name'][$index], $prefix);
}

function save_uploaded_file($tmpPath, $prefix) {
    // Raster types get converted → WebP. GIF and SVG are passed through unchanged.
    $raster    = ['image/jpeg', 'image/png', 'image/webp'];
    $passthru  = ['image/gif' => 'gif', 'image/svg+xml' => 'svg'];

    $maxBytes = 8 * 1024 * 1024;
    if (filesize($tmpPath) > $maxBytes) return false;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    if (!in_array($mime, $raster) && !isset($passthru[$mime])) return false;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);

    // GIF — move as-is, no processing
    if ($mime === 'image/gif') {
        $filename = $prefix . '_' . time() . '_' . substr(md5(mt_rand()), 0, 6) . '.gif';
        return move_uploaded_file($tmpPath, UPLOAD_DIR . $filename) ? UPLOAD_URL . $filename : false;
    }

    // SVG — strip scripts/event handlers before saving (SVGs can carry embedded JS)
    if ($mime === 'image/svg+xml') {
        $svg = sanitize_svg(file_get_contents($tmpPath));
        if ($svg === false) return false;
        $filename = $prefix . '_' . time() . '_' . substr(md5(mt_rand()), 0, 6) . '.svg';
        return file_put_contents(UPLOAD_DIR . $filename, $svg) !== false ? UPLOAD_URL . $filename : false;
    }

    // Raster — resize to max 1800px wide and convert to WebP via GD
    $filename = $prefix . '_' . time() . '_' . substr(md5(mt_rand()), 0, 6) . '.webp';
    $dest     = UPLOAD_DIR . $filename;

    if (extension_loaded('gd') && function_exists('imagewebp')) {
        $src = match($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            default      => false,
        };
        if ($src) {
            $ow = imagesx($src); $oh = imagesy($src);
            if ($ow > 1800) {
                $nw  = 1800;
                $nh  = (int) round($oh * 1800 / $ow);
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
                imagedestroy($src);
                $src = $dst;
            }
            $ok = imagewebp($src, $dest, 82);
            imagedestroy($src);
            if ($ok) return UPLOAD_URL . $filename;
        }
    }

    // GD fallback — move original file without processing
    $origExt  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $fallback = $prefix . '_' . time() . '_' . substr(md5(mt_rand()), 0, 6) . '.' . $origExt;
    return move_uploaded_file($tmpPath, UPLOAD_DIR . $fallback) ? UPLOAD_URL . $fallback : false;
}

function h($string) {
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function sanitize_rich_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><a><blockquote><h2><h3>');
    $html = preg_replace_callback('/<a\b([^>]*)>/i', function($m) {
        if (preg_match('/\bhref=["\']([^"\']*)["\']/', $m[1], $href)) {
            $url = sanitize_url(html_entity_decode($href[1], ENT_QUOTES, 'UTF-8'));
            $ext = $url && preg_match('/^https?:\/\//i', $url) ? ' rel="noopener"' : '';
            return $url ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' : '<a>';
        }
        return '<a>';
    }, $html);
    $html = preg_replace('/<(p|br|strong|b|em|i|ul|ol|li|blockquote|h2|h3)\b[^>]*>/i', '<$1>', $html);
    return $html;
}

function text_to_html($text) {
    $text = trim((string) $text);
    if ($text === '') return '';
    // If it already contains HTML tags, output as-is (rich editor content)
    if (preg_match('/<[a-z][\s\S]*>/i', $text)) {
        return $text;
    }
    // Plain text — wrap paragraphs
    $blocks = preg_split('/\n\s*\n/', $text);
    $html = '';
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $html .= '<p>' . nl2br(h($block)) . "</p>\n";
    }
    return $html;
}
