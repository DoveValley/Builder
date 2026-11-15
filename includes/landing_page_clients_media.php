<?php
/**
 * Landing Page Clients — service image sourcing (Phase 3 backlog item 3).
 *
 * Images are assigned per SERVICE, not per individual city page — mirrors the
 * niche fleet's own proven pattern (one photo per template, reused across
 * every city cloned from it). A photo of "water damage cleanup" doesn't
 * actually look different city to city the way AI-written text does, so
 * there's no real per-city variation to capture, and this keeps cost/count
 * bounded to (services), not (cities × services).
 *
 * Three-tier sourcing cascade, stored on each services[] entry:
 *   - image_real   — client's own uploaded photo (best: authentic, not generic)
 *   - image_ai     — AI-generated fallback via includes/openai_images.php
 *   - image_active — 'real' | 'ai' | '' (which one actually renders; switching
 *                    doesn't delete the other, same non-destructive toggle
 *                    idea Pic Drop proved out, just scoped to one flat field
 *                    pair instead of a whole slot-addressing system)
 * Falls back to a shared placeholder when a service has neither — no LPC page
 * ever ships with a missing image.
 *
 * Deliberately NOT built on includes/media_lib.php or Pic Drop — both are
 * wired to this codebase's active-site-selection model (UPLOAD_DIR/MEDIA_JSON
 * are request-global constants keyed off ACTIVE_SITE_DIR, and Pic Drop's
 * slot system resolves into site.json/TEMPLATES_FILE, neither of which exists
 * in a client.json/pages/*.json world). Reuses what's genuinely generic
 * instead: save_uploaded_file() (now accepts an explicit dir/url, see
 * includes/helpers.php) for the real validation/conversion logic, and
 * includes/openai_images.php as-is for generation (already a clean, stateless
 * API client with no site-scoped coupling).
 *
 * Files are written straight into LPC_DELIVERY_DATA_DIR (the same local
 * staging tree lpc_sync_delivery_to_remote() already rsyncs wholesale to
 * Box 1 on every publish) — no separate admin-side media folder and no new
 * sync code; a new image just rides along in the next existing sync.
 */

function lpc_service_image_dir($client_id) {
    return LPC_DELIVERY_DATA_DIR . 'clients/' . $client_id . '/images/';
}

/** Public URL the plugin/visitor's browser hits directly — served by
 *  delivery-server/public/api.php's `image` action, which is deliberately
 *  NOT API-key-gated (an image only ever gets attached to a page once that
 *  page is public anyway, so gating it behind the same secret used for
 *  draft-content fetches would add an exposed-in-markup secret for no real
 *  confidentiality benefit). */
function lpc_service_image_url($client_id, $filename) {
    return LPC_DELIVERY_PUBLIC_BASE . '?action=image&client=' . urlencode($client_id) . '&file=' . urlencode($filename);
}

/**
 * Validates + converts + saves one uploaded file as a service's real photo.
 * Returns ['filename'=>, 'width'=>, 'height'=>] (real pixel dimensions, read
 * back off the saved file — same "record the real dimensions once, read
 * them back later" pattern includes/media_lib.php's media_register() already
 * uses elsewhere in this codebase), or false on failure (bad type, too large).
 */
function lpc_upload_service_image($client_id, $tmpPath, $origName) {
    $dir = lpc_service_image_dir($client_id);
    $filename = save_uploaded_file($tmpPath, 'svc', $origName, $dir, '');
    if ($filename === false) return false;
    $dims = @getimagesize($dir . $filename);
    return ['filename' => $filename, 'width' => $dims[0] ?? null, 'height' => $dims[1] ?? null];
}

/**
 * Generates one AI photo for a service via the existing OpenAI Images module
 * — no new API client, see includes/openai_images.php's own "one module per
 * service" header. Real cost per call; callers gate this behind an explicit
 * confirm(), same as every other paid action in this feature.
 */
function lpc_generate_service_image($client_id, $service_label, $niche_brief) {
    require_once __DIR__ . '/openai_images.php';

    $descriptor = $niche_brief['business_descriptor'] ?? '';
    $prompt = "Professional, realistic photo representing \"{$service_label}\""
        . ($descriptor ? " for a business that does: {$descriptor}." : '.')
        . ' Real-world service/trade photography style, natural lighting, no visible text or logos, no people\'s faces in close-up.';

    $result = openai_images_generate($prompt, ['size' => '1024x1024', 'output_format' => 'webp']);
    if (!$result['ok']) return ['ok' => false, 'msg' => $result['error']];

    $dir = lpc_service_image_dir($client_id);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ext = $result['format'] ?: 'webp';
    $filename = 'svc_ai_' . slugify($service_label) . '_' . time() . '_' . substr(md5(mt_rand()), 0, 6) . '.' . $ext;
    if (file_put_contents($dir . $filename, $result['bytes']) === false) {
        return ['ok' => false, 'msg' => 'Generated image could not be saved to disk.'];
    }
    // Measured off the real saved file rather than trusting the requested
    // size — a provider is free to return something slightly different.
    $dims = @getimagesize($dir . $filename);
    return ['ok' => true, 'msg' => '', 'filename' => $filename, 'width' => $dims[0] ?? 1024, 'height' => $dims[1] ?? 1024];
}

/**
 * One placeholder, shared across every client — generated once with GD
 * (not a committed binary asset) and written to a reserved "_shared"
 * pseudo-client folder so delivery-server/public/api.php's same `image`
 * action can serve it with no special-casing beyond that one reserved id.
 * Idempotent: only regenerates if the file is missing.
 */
function lpc_ensure_placeholder_image() {
    $dir = LPC_DELIVERY_DATA_DIR . 'clients/_shared/images/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    // GD path — a real "Photo coming soon" graphic. Same extension_loaded()
    // guard save_uploaded_file() already uses elsewhere in this codebase; not
    // every environment has GD, and this placeholder isn't worth a hard
    // dependency on it.
    if (extension_loaded('gd') && function_exists('imagewebp')) {
        $file = $dir . 'placeholder.webp';
        if (is_file($file)) return $file;
        $w = 1024; $h = 683;
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 226, 232, 240)); // slate-200
        $textColor = imagecolorallocate($img, 100, 116, 139); // slate-500
        $text = 'Photo coming soon';
        $font = 5; // built-in GD font, no external .ttf dependency
        $tw = imagefontwidth($font) * strlen($text);
        imagestring($img, $font, (int) (($w - $tw) / 2), (int) ($h / 2) - 8, $text, $textColor);
        imagewebp($img, $file, 82);
        imagedestroy($img);
        return $file;
    }

    // No-GD fallback — a tiny, valid, hardcoded 1x1 PNG. Browsers stretch an
    // <img> to its CSS box regardless of the source file's own pixel
    // dimensions, so this still renders as a plain solid-color placeholder;
    // it just can't carry the "Photo coming soon" text without GD to draw it.
    $file = $dir . 'placeholder.png';
    if (is_file($file)) return $file;
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNiYGAAAAAbAAFAStCvAAAAAElFTkSuQmCC');
    file_put_contents($file, $png);
    return $file;
}

function lpc_placeholder_image_url() {
    $file = lpc_ensure_placeholder_image();
    return lpc_service_image_url('_shared', basename($file));
}

/** The one image to actually use for a service right now — active image if
 *  set, else the shared placeholder. Never returns empty. Returns
 *  ['url'=>,'width'=>,'height'=>] so callers can emit real <img width/height>
 *  attributes (a genuine Lighthouse/CLS finding on the first real live page,
 *  2026-11-15) instead of leaving them unset. The no-GD placeholder fallback
 *  is a literal 1x1 file (stretched via CSS regardless), so its declared
 *  dimensions use the GD version's own intended 1024x683 design size rather
 *  than measuring the tiny stand-in file. */
function lpc_resolve_service_image($client_id, $service) {
    if (($service['image_active'] ?? '') === 'real' && !empty($service['image_real'])) {
        return [
            'url'    => lpc_service_image_url($client_id, $service['image_real']),
            'width'  => $service['image_real_w'] ?? null,
            'height' => $service['image_real_h'] ?? null,
        ];
    }
    if (($service['image_active'] ?? '') === 'ai' && !empty($service['image_ai'])) {
        return [
            'url'    => lpc_service_image_url($client_id, $service['image_ai']),
            'width'  => $service['image_ai_w'] ?? null,
            'height' => $service['image_ai_h'] ?? null,
        ];
    }
    return ['url' => lpc_placeholder_image_url(), 'width' => 1024, 'height' => 683];
}

/** Fills hero.image/image_alt/seo.og_image from the service's current image,
 *  but only if currently blank — a manually-set per-page override (typed
 *  directly into the editor) is never clobbered, same "never silently
 *  overwrite a manual customization" rule this feature already applies to
 *  page_title/primary_keyword. Alt text is deterministic (built from the
 *  page's own primary keyword), never AI-written — there's no way to
 *  honestly describe a generic/placeholder photo's actual contents without
 *  risking a mismatched claim, so it states what the page is about instead,
 *  which is also exactly what image-SEO alt text should center on anyway. */
function lpc_fill_service_image(&$page, $client_id, $service, $altText) {
    $image = lpc_resolve_service_image($client_id, $service);
    foreach ($page['sections'] as &$section) {
        if (($section['type'] ?? '') !== 'hero') continue;
        if (empty($section['image'])) {
            $section['image'] = $image['url'];
        }
        // Backfills width/height whenever the hero's current image IS this
        // service's own resolved image (just set above, or already matching
        // from before this field existed) — never overwrites dimensions for
        // a different, manually-typed image URL it doesn't actually describe.
        if ($section['image'] === $image['url'] && empty($section['image_width'])) {
            $section['image_width']  = $image['width'];
            $section['image_height'] = $image['height'];
        }
        if (empty($section['image_alt'])) $section['image_alt'] = $altText;
    }
    unset($section);
    if (empty($page['seo']['og_image'])) {
        $page['seo']['og_image'] = $image['url'];
    }
}
