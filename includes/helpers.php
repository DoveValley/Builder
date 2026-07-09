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
 * Strip executable content from an uploaded SVG before saving it. Returns false
 * if the file doesn't parse as valid XML (rejects rather than saving unverified).
 *
 * Removes: <script>, <use>, <image>, <foreignObject>, <animate>, <set>, <animateTransform>
 * Removes: on* handlers, href/xlink:href/src with javascript:/data:/vbscript:,
 *          style attributes containing url(javascript:), expression()
 * Does NOT use LIBXML_NOENT (avoids XXE via SYSTEM entity expansion).
 */
function sanitize_svg(string $svg): string|false {
    if (trim($svg) === '') return false;
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok  = $doc->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    if (!$ok) return false;

    $xpath = new DOMXPath($doc);

    // Collect dangerous elements first to avoid modifying the tree while iterating
    $dangerousTags = ['script', 'use', 'image', 'foreignobject', 'animate',
                      'set', 'animatetransform', 'animatemotion', 'discard', 'handler'];
    $toRemove = [];
    foreach ($xpath->query('//*') as $node) {
        if (in_array(strtolower($node->localName ?? ''), $dangerousTags, true)) {
            $toRemove[] = $node;
        }
    }
    foreach ($toRemove as $node) {
        if ($node->parentNode) $node->parentNode->removeChild($node);
    }

    // Collect dangerous attributes before removing them
    $hrefAttrs   = ['href', 'xlink:href', 'src', 'action'];
    $dangerScheme = '/^\s*(javascript|data|vbscript)\s*:/i';
    $toRemoveAttrs = [];
    foreach ($xpath->query('//@*') as $attr) {
        $name  = strtolower($attr->nodeName);
        $value = trim($attr->nodeValue);
        $remove = false;
        if (str_starts_with($name, 'on')) {
            $remove = true;
        } elseif (in_array($name, $hrefAttrs, true)) {
            if (preg_match($dangerScheme, $value)) $remove = true;
        } elseif ($name === 'style') {
            if (preg_match('/url\s*\(\s*["\']?\s*(javascript|data|vbscript)\s*:/i', $value)) $remove = true;
            if (stripos($value, 'expression(') !== false) $remove = true;
        }
        if ($remove) $toRemoveAttrs[] = $attr;
    }
    foreach ($toRemoveAttrs as $attr) {
        $attr->ownerElement->removeAttribute($attr->nodeName);
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
    $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><a><span><blockquote><h2><h3>');
    // Strip all attributes from block-level tags
    $html = preg_replace('/<(p|br|strong|b|em|i|ul|ol|li|blockquote|h2|h3)\b[^>]*>/i', '<$1>', $html);
    // Sanitize <a> — keep only href + target, validate URL
    $html = preg_replace_callback('/<a\b([^>]*)>/i', function($m) {
        if (preg_match('/\bhref=["\']([^"\']*)["\']/', $m[1], $href)) {
            $url = sanitize_url(html_entity_decode($href[1], ENT_QUOTES, 'UTF-8'));
            $ext = $url && preg_match('/^https?:\/\//i', $url) ? ' rel="noopener"' : '';
            return $url ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' : '<a>';
        }
        return '<a>';
    }, $html);
    // Sanitize <span> — keep only safe inline style properties (color, background-color, font-weight, font-style, text-decoration)
    $html = preg_replace_callback('/<span\b([^>]*)>/i', function($m) {
        if (preg_match('/\bstyle=["\']([^"\']*)["\']/', $m[1], $sm)) {
            $safe = [];
            foreach (explode(';', $sm[1]) as $decl) {
                $decl = trim($decl);
                if ($decl !== '' && preg_match('/^(color|background-color|font-weight|font-style|text-decoration)\s*:\s*[^;<>]{1,80}$/i', $decl)) {
                    $safe[] = $decl;
                }
            }
            if ($safe) return '<span style="' . htmlspecialchars(implode(';', $safe), ENT_QUOTES) . '">';
        }
        return '<span>';
    }, $html);
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

function tab_header(string $title, string $description, string $docAnchor): void {
    echo '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">';
    echo '<div style="flex:1;">';
    echo '<h2 style="margin:0 0 5px;font-size:1.15rem;font-weight:700;color:#1e3a5f;">' . h($title) . '</h2>';
    echo '<p style="margin:0;font-size:0.875rem;color:#475569;line-height:1.5;">' . h($description) . '</p>';
    echo '</div>';
    echo '<a href="/admin/docs.php#' . h($docAnchor) . '" target="_blank" '
       . 'style="flex-shrink:0;width:24px;height:24px;border-radius:50%;background:#1e40af;color:#fff;'
       . 'font-size:0.78rem;font-weight:700;text-decoration:none;display:flex;align-items:center;'
       . 'justify-content:center;margin-top:2px;" title="View documentation">?</a>';
    echo '</div>';
}

/* Browser URL for an active-site upload (site-relative path like "uploads/x.png").
   Maps to the site's real web path — sites/{id}/uploads/... on multisite,
   /uploads/... on a root single-site — so admin thumbnails actually resolve. */
function admin_upload_url(string $path): string {
    $path = ltrim($path, '/');
    if ($path === '') return '';
    if (defined('UPLOAD_URL') && strncmp($path, 'uploads/', 8) === 0) {
        return '/' . preg_replace('#^uploads/#', UPLOAD_URL, $path);
    }
    return '/' . $path;
}
