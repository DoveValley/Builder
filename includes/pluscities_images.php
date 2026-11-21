<?php
/**
 * includes/pluscities_images.php — image resolution and client-branding for
 * one niche image slot (e.g. "hero"). Mirrors Pic Drop's proven slot/pair
 * pattern (docs/pic-drop-image-management.md), adapted to this engine's
 * client-reused-everywhere model rather than Site Factory's per-domain one,
 * and deliberately simplified for a customer-facing screen (no scopes/pairs/
 * propagation vocabulary exposed anywhere — see admin/pluscities_photos.php).
 *
 * Resolution order per slot, simple on purpose: the client's own real upload
 * (if set) > a client-branded version of the niche default (generated once,
 * cached, reused on every page for that client — logo/color come from the
 * client's own branding fields) > the plain niche default (no branding
 * input set yet). Never a byte-identical-match propagation across clients —
 * the exact incident Pic Drop's own docs warn about; this engine doesn't
 * have that mechanism at all, so it can't recur here.
 *
 * Branding mechanism, chosen after checking what the shared OpenAI module
 * actually supports: openai_images_edit() takes ONE reference image + a text
 * prompt — it can't reliably reproduce an exact uploaded logo file painted
 * onto a photo (the model redraws from a text description, not a second
 * reference image). So color comes from the AI edit (a text-promptable,
 * plausible task); the real logo is composited afterward via plain GD as a
 * small corner badge — pixel-perfect, cheap, no reproduction risk.
 */

require_once __DIR__ . '/openai_images.php';

/** The client's own real URL for a slot, if they've uploaded one — the
 *  top of the resolution order, checked first. Stored exactly as
 *  save_uploaded_file() returned it: a full site-root-relative URL
 *  (e.g. "/pluscities_uploads/xxx.webp"), ready to use as-is. */
function pluscities_client_image_url(array $client, string $slot_id): ?string
{
    return $client['images'][$slot_id]['uploaded_path'] ?? null;
}

/**
 * Resolves the final image URL for one slot — the only function a renderer
 * should ever call; it never needs to know whether that's the client's own
 * photo, a branded default, or the plain niche default.
 */
function pluscities_resolve_image_url(array $client, array $niche, string $slot_id): ?string
{
    $uploaded = pluscities_client_image_url($client, $slot_id);
    if ($uploaded) return $uploaded;

    $brandedPath = $client['images'][$slot_id]['branded_cache_path'] ?? null;
    if ($brandedPath && file_exists(PLUSCITIES_UPLOADS_DIR . $brandedPath)) {
        return PLUSCITIES_UPLOADS_URL . $brandedPath;
    }

    foreach ($niche['image_slots'] ?? [] as $slot) {
        if ($slot['slot_id'] === $slot_id) {
            return $slot['default_image_path'] ? '/' . ltrim($slot['default_image_path'], '/') : null;
        }
    }
    return null;
}

/**
 * Generates (or regenerates) the client-branded version of one slot's niche
 * default — recolor via AI edit, then composite the client's real logo as a
 * corner badge. Runs ONCE per client per slot, cached; callers check the
 * cache file via pluscities_resolve_image_url() first so this never re-runs
 * on every page view.
 *
 * @return array{ok:bool,msg:string}
 */
function pluscities_generate_branded_image(string $client_id, array $niche, string $slot_id): array
{
    $clientFile = PLUSCITIES_CLIENTS_DIR . $client_id . '.json';
    $client = json_decode((string) @file_get_contents($clientFile), true);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found.'];

    $slot = null;
    foreach ($niche['image_slots'] ?? [] as $s) if ($s['slot_id'] === $slot_id) { $slot = $s; break; }
    if (!$slot || !$slot['default_image_path']) return ['ok' => false, 'msg' => 'This niche has no default image for that slot.'];

    $defaultPath = BASE_DIR . '/' . ltrim($slot['default_image_path'], '/');
    if (!file_exists($defaultPath)) return ['ok' => false, 'msg' => 'Niche default image file is missing.'];

    $brandColor = trim((string) ($client['branding']['brand_color'] ?? ''));
    $workingPath = $defaultPath;
    $tmpRecolor = null;

    // Step 1 — AI recolor, only if a brand color was actually set. No color
    // set means there's nothing useful to ask the model to change, so this
    // step is skipped rather than running a pointless (and billed) edit.
    if ($brandColor !== '') {
        $prompt = 'Change the color of the technician\'s uniform/shirt to ' . $brandColor
            . '. Keep everything else in the photo exactly the same — same pose, background, '
            . 'equipment, lighting. Do not add any text or logos.';
        $result = openai_images_edit($prompt, $defaultPath, ['size' => '1536x1024', 'quality' => 'medium']);
        if ($result['ok']) {
            $tmpRecolor = tempnam(sys_get_temp_dir(), 'pcimg_') . '.' . ($result['format'] ?: 'png');
            file_put_contents($tmpRecolor, $result['bytes']);
            $workingPath = $tmpRecolor;
        }
        // A failed recolor falls through to the plain default rather than
        // failing the whole branding pass — a logo badge on the unbranded
        // default is still better than nothing.
    }

    // Step 2 — composite the real logo as a corner badge, if one exists.
    // logo_url is stored exactly as save_uploaded_file() returned it — a full
    // site-root-relative URL — so the disk path is just BASE_DIR + that URL.
    $logoPath = trim((string) ($client['branding']['logo_url'] ?? ''));
    $finalPath = $workingPath;
    if ($logoPath !== '') {
        $realLogoPath = BASE_DIR . $logoPath;
        if (file_exists($realLogoPath)) {
            $composited = pluscities_composite_logo_badge($workingPath, $realLogoPath);
            if ($composited) $finalPath = $composited;
        }
    }

    if (!is_dir(PLUSCITIES_UPLOADS_DIR)) mkdir(PLUSCITIES_UPLOADS_DIR, 0775, true);
    $cacheRel = 'branded/' . $client_id . '_' . $slot_id . '_' . substr(md5($brandColor . $logoPath), 0, 8) . '.webp';
    $cacheAbs = PLUSCITIES_UPLOADS_DIR . $cacheRel;
    if (!is_dir(dirname($cacheAbs))) mkdir(dirname($cacheAbs), 0775, true);

    $img = pluscities_load_image_any($finalPath);
    if (!$img) return ['ok' => false, 'msg' => 'Could not process the branded image.'];
    imagewebp($img, $cacheAbs, 85);
    imagedestroy($img);

    if ($tmpRecolor && file_exists($tmpRecolor)) unlink($tmpRecolor);
    if ($finalPath !== $workingPath && file_exists($finalPath)) unlink($finalPath);

    $client['images'][$slot_id]['branded_cache_path'] = $cacheRel;
    pluscities_atomic_write_json($clientFile, $client);
    return ['ok' => true, 'msg' => ''];
}

/** Loads a JPEG/PNG/WebP file into a GD resource regardless of which one it is. */
function pluscities_load_image_any(string $path)
{
    $info = @getimagesize($path);
    if (!$info) return false;
    return match ($info['mime']) {
        'image/png'  => imagecreatefrompng($path),
        'image/webp' => imagecreatefromwebp($path),
        'image/jpeg' => imagecreatefromjpeg($path),
        default => false,
    };
}

/** Composites a real logo file onto the bottom-right corner of a photo —
 *  plain GD, no AI involved, so the logo is always pixel-accurate. Returns a
 *  temp file path, or null on failure (caller falls back to the unbadged photo). */
function pluscities_composite_logo_badge(string $photoPath, string $logoPath): ?string
{
    $photo = pluscities_load_image_any($photoPath);
    $logo  = pluscities_load_image_any($logoPath);
    if (!$photo || !$logo) return null;

    $photoW = imagesx($photo); $photoH = imagesy($photo);
    $logoW  = imagesx($logo);  $logoH  = imagesy($logo);

    // Logo badge is sized relative to the photo — about 18% of the photo's
    // width, never larger, so a huge uploaded logo file doesn't dominate the
    // photo it's supposed to just be a mark on.
    $targetW = (int) round($photoW * 0.18);
    $targetH = (int) round($targetW * ($logoH / $logoW));
    $padding = (int) round($photoW * 0.03);

    imagesavealpha($logo, true);
    imagecopyresampled(
        $photo, $logo,
        $photoW - $targetW - $padding, $photoH - $targetH - $padding, 0, 0,
        $targetW, $targetH, $logoW, $logoH
    );

    $tmp = tempnam(sys_get_temp_dir(), 'pcbadge_') . '.png';
    imagepng($photo, $tmp);
    imagedestroy($photo);
    imagedestroy($logo);
    return $tmp;
}
