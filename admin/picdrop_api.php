<?php
/**
 * admin/picdrop_api.php — write endpoints for the Pic Drop tab.
 *
 * POST action=place   multipart: key, file, [propagate=1], [screen=1]
 * POST action=alt                key, alt
 *
 * Every call writes ONE field (or that same field across matching pages). It never
 * posts the whole page the way the Home tab's block editor does — which is the point:
 * that form runs to ~6,200 inputs on a busy site and PHP truncates it past
 * max_input_vars / max_multipart_body_parts with no error anyone can see. A one-field
 * POST cannot hit either limit.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media_lib.php';
require_once __DIR__ . '/../includes/picdrop.php';

header('Content-Type: application/json');

function pd_fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if (empty($_SESSION['admin_logged_in']))              pd_fail('Not authenticated.', 403);

/* ── GET: the adjuster's two read-only needs ───────────────────────────────────
   Kept behind this endpoint rather than letting the browser fetch _originals/
   directly. That folder lives under the webroot (sites/<id>/_originals/), so a raw
   path would be world-readable to anyone who guessed it — full-size sources for the
   whole fleet. Here it is gated by the same admin session as everything else. */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!ACTIVE_SITE_ID) pd_fail('No active site.');
    $gAction = (string) ($_GET['action'] ?? '');
    $gKey    = (string) ($_GET['key'] ?? '');
    $gParts  = picdrop_parse_key($gKey);
    if ($gParts === null) pd_fail('Unrecognised slot.');

    $gSlot = null;
    foreach (picdrop_groups() as $g) {
        foreach ($g['slots'] as $s) { if ($s['key'] === $gKey) { $gSlot = $s; break 2; } }
    }
    if ($gSlot === null || $gSlot['value'] === '' || !empty($gSlot['token'])) {
        pd_fail('Nothing croppable in that slot.');
    }

    // pd_crop_source() is declared further down; PHP hoists top-level functions.
    [$srcPath, $hasOriginal, $mediaItem] = pd_crop_source($gSlot);
    if ($srcPath === null || !is_file($srcPath)) pd_fail('The source image is missing from disk.');

    if ($gAction === 'source') {
        // Stream the crop source for the preview. No caching: after an adjust the
        // same key can resolve to a different file.
        $mime = (string) (@getimagesize($srcPath)['mime'] ?? 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($srcPath));
        header('Cache-Control: no-store');
        readfile($srcPath);
        exit;
    }

    if ($gAction === 'info') {
        [$sw, $sh] = @getimagesize($srcPath) ?: [0, 0];
        $adj = $mediaItem['adjust'] ?? [];
        echo json_encode([
            'success'      => true,
            'slot_w'       => (int) $gSlot['w'],
            'slot_h'       => (int) $gSlot['h'],
            'src_w'        => (int) $sw,
            'src_h'        => (int) $sh,
            'has_original' => $hasOriginal,
            // How far you can zoom OUT before the whole picture is visible. 1 means
            // the source is already the slot's shape, so there is nothing to reveal.
            'zoom_min'     => img_zoom_min((int) $sw, (int) $sh, (int) $gSlot['w'], (int) $gSlot['h']),
            'zoom'         => (float) ($adj['zoom'] ?? 1),
            'fx'           => (float) ($adj['fx'] ?? 0.5),
            'fy'           => (float) ($adj['fy'] ?? 0.5),
            'fill'         => (string) ($adj['fill'] ?? 'white'),
        ]);
        exit;
    }
    pd_fail('Unknown action.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')            pd_fail('POST required.', 405);
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))
                                                      pd_fail('Invalid request token.', 403);
if (!ACTIVE_SITE_ID)                                  pd_fail('No active site.');

$action = (string) ($_POST['action'] ?? '');
$key    = (string) ($_POST['key'] ?? '');
$parts  = picdrop_parse_key($key);
if ($parts === null) pd_fail('Unrecognised slot.');

/**
 * OCR screen for text burned into the pixels — the four-variant recipe, because a
 * single grayscale+normalize pass produces false negatives on plain white captions.
 * Any one confident word is a hit; a two-word threshold once missed an image that
 * only yielded the fragment "vices" out of "Services".
 */
function pd_burnin_words(string $file): ?array {
    if (!is_executable('/usr/bin/tesseract') || !is_executable('/usr/bin/convert')) return null;

    $variants = [
        ['-colorspace gray -resize 1600x -normalize', '11'],
        ['-colorspace gray -resize 1600x -level 55%,100%', '6'],
        ['-colorspace gray -resize 1600x -negate -level 55%,100%', '6'],
        ['-gravity south -crop 100%x45%+0+0 +repage -colorspace gray -resize 1600x -level 55%,100%', '6'],
    ];

    $found = [];
    foreach ($variants as [$ops, $psm]) {
        $png = tempnam(sys_get_temp_dir(), 'pdocr') . '.png';
        $cmd = '/usr/bin/convert ' . escapeshellarg($file) . ' ' . $ops . ' ' . escapeshellarg('PNG:' . $png) . ' 2>/dev/null';
        @shell_exec($cmd);
        if (!is_file($png) || filesize($png) === 0) { @unlink($png); continue; }

        $tsv = (string) @shell_exec(
            '/usr/bin/tesseract ' . escapeshellarg($png) . ' stdout --psm ' . $psm . ' tsv 2>/dev/null'
        );
        @unlink($png);

        foreach (explode("\n", $tsv) as $line) {
            $c = explode("\t", $line);
            if (count($c) < 12) continue;
            $conf = (float) $c[10];
            $word = trim($c[11]);
            if ($conf >= 65 && strlen($word) >= 4 && preg_match('/^[A-Za-z]+$/', $word)) {
                $found[strtolower($word)] = true;
            }
        }
    }
    return array_keys($found);
}

// ── ALT TEXT ─────────────────────────────────────────────────────────────────────
if ($action === 'alt') {
    $spec    = picdrop_fields()[picdrop_leaf($parts['field'])];
    $altPath = picdrop_alt_path($parts['field'], $spec['alt']);
    if ($altPath === null) pd_fail('This slot is decorative and has no alt text.');

    $res = picdrop_apply_edits([[
        'scope' => $parts['scope'], 'id' => $parts['id'], 'block' => $parts['block'],
        'field' => $altPath,        'value' => (string) ($_POST['alt'] ?? ''),
    ]]);

    if ($res['ok'] === 0) pd_fail($res['errors'][0] ?? 'Nothing was written.');
    echo json_encode(['success' => true]);
    exit;
}

/* The media.json entry for a stored image path, or null. */
function pd_media_for(string $value): ?array {
    $file = basename($value);
    foreach (media_load() as $m) if (($m['filename'] ?? '') === $file) return $m;
    return null;
}

/* The best crop source for a slot: the kept original if there is one, otherwise the
   slot's own file. With no original the file IS already the cover crop, so zoom 1
   simply means "as it is" and only tightening is possible — which is the honest
   behaviour, not a special case. */
function pd_crop_source(array $slot): array {
    $m    = pd_media_for($slot['value']);
    $orig = (string) ($m['origin'] ?? '');
    if ($orig !== '' && is_file(ORIGINALS_DIR . basename($orig))) {
        return [ORIGINALS_DIR . basename($orig), true, $m];
    }
    $own = picdrop_resolve($slot['value']);
    return [$own, false, $m];
}

// ── ADJUST: RE-CROP AN EXISTING SLOT ─────────────────────────────────────────────
if ($action === 'adjust') {
    $slot = null;
    foreach (picdrop_groups() as $g) {
        foreach ($g['slots'] as $s) { if ($s['key'] === $key) { $slot = $s; break 2; } }
    }
    if ($slot === null)            pd_fail('That slot no longer exists — reload the tab.');
    if (!empty($slot['token']))    pd_fail('This slot is filled per city and cannot be cropped here.');
    if ($slot['value'] === '')     pd_fail('There is no picture in this slot yet.');

    [$srcPath, $hasOriginal, $mediaItem] = pd_crop_source($slot);
    if ($srcPath === null || !is_file($srcPath)) pd_fail('The source image is missing from disk.');

    $zoom = (float) ($_POST['zoom'] ?? 1);
    $fx   = (float) ($_POST['fx']   ?? 0.5);
    $fy   = (float) ($_POST['fy']   ?? 0.5);

    [$ow, $oh] = @getimagesize($srcPath) ?: [0, 0];
    if ($ow < 1) pd_fail('Could not read the source image.');

    $tw = (int) $slot['w'];
    $th = (int) $slot['h'];
    if ($tw < 1 || $th < 1) pd_fail('This slot has no size to crop to.');

    $fill = (string) ($_POST['fill'] ?? 'white');

    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);
    /* A NEW file, not an overwrite. One picture can sit in several slots — propagate
       puts it there deliberately — and re-cropping in place would silently change all
       of them. A new file keeps the adjustment to the slot you are looking at. */
    $base     = preg_replace('/(\.orig)?\.webp$/', '', basename($slot['value'])) ?: 'image';
    $base     = preg_replace('/_[0-9a-f]{6}$/', '', $base);
    $filename = $base . '_' . substr(md5(uniqid('', true)), 0, 6) . '.webp';
    $dest     = MEDIA_DIR . $filename;

    [$ok, $note] = img_place_to($srcPath, $dest, $tw, $th, $zoom, $fx, $fy, $fill);
    if (!$ok) pd_fail($note);

    $oldValue = $slot['value'];
    $newValue = UPLOAD_URL . 'media/' . $filename;

    media_register([
        'filename'   => $filename,
        'url'        => $newValue,
        'width'      => $tw,
        'height'     => $th,
        'size'       => (int) filesize($dest),
        'alt'        => $slot['alt'],
        'tags'       => ['picdrop'],
        'source_url' => '',
        'added_at'   => date('Y-m-d H:i:s'),
        // Carry the original forward, or this becomes a one-shot crop.
        'origin'     => (string) ($mediaItem['origin'] ?? ''),
        'adjust'     => ['zoom' => $zoom, 'fx' => $fx, 'fy' => $fy, 'fill' => $fill],
    ]);

    $res = picdrop_apply_edits([[
        'scope' => $parts['scope'], 'id' => $parts['id'],
        'block' => $parts['block'], 'field' => $parts['field'], 'value' => $newValue,
    ]]);
    if ($res['ok'] === 0) {
        @unlink($dest);
        pd_fail($res['errors'][0] ?? 'The crop was made but nothing could be written.');
    }

    /* Tidy up the file this one replaced, but only when it was produced here and
       nothing else still points at it. Never touch an image that arrived some other
       way, and never one another slot is using. */
    $pruned = false;
    $oldItem = pd_media_for($oldValue);
    if ($oldItem && ($oldItem['origin'] ?? '') !== '') {
        $stillUsed = false;
        foreach (picdrop_groups() as $g) {
            foreach ($g['slots'] as $s) { if ($s['value'] === $oldValue) { $stillUsed = true; break 2; } }
        }
        if (!$stillUsed) {
            $p = picdrop_resolve($oldValue);
            if ($p !== null) { @unlink($p); $pruned = true; }
            media_save(array_filter(media_load(), fn($m) => ($m['filename'] ?? '') !== basename($oldValue)));
        }
    }

    echo json_encode([
        'success'      => true,
        'url'          => $newValue,
        'filename'     => $filename,
        'width'        => $tw,
        'height'       => $th,
        'note'         => $note,
        'has_original' => $hasOriginal,
        'pruned'       => $pruned,
    ]);
    exit;
}

// ── PLACE AN IMAGE ───────────────────────────────────────────────────────────────
if ($action !== 'place') pd_fail('Unknown action.');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    // A body over post_max_size arrives with $_POST and $_FILES both empty, so the
    // generic "upload error" would be actively misleading here.
    $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    pd_fail(match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is over the server upload limit.',
        UPLOAD_ERR_PARTIAL                        => 'The upload was cut off before it finished.',
        UPLOAD_ERR_NO_FILE                        => 'No file arrived.',
        default                                   => 'Upload failed (code ' . $err . ').',
    });
}

$tmpFile = $_FILES['file']['tmp_name'];
// Only ever read a path PHP itself created for this request — never a path that
// merely arrived in the request.
if (!is_uploaded_file($tmpFile)) pd_fail('That upload did not come from this form.');

$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mime    = (string) $finfo->file($tmpFile);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    pd_fail('That is not a JPG, PNG, GIF or WebP.');
}
if ($_FILES['file']['size'] > 20 * 1024 * 1024) pd_fail('File too large (max 20 MB).');

// Optional burn-in screen, before anything is written anywhere.
$screened = null;
if (!empty($_POST['screen'])) {
    $words = pd_burnin_words($tmpFile);
    if ($words === null) {
        $screened = 'skipped — tesseract not available on this box';
    } elseif ($words) {
        pd_fail('Looks like this image has text burned into it ("'
            . implode('", "', array_slice($words, 0, 4)) . '"). Not placed.');
    } else {
        $screened = 'no burned-in text detected';
    }
}

// The slot decides the output size: match whatever is in it now. Every slot already
// holds a correctly-sized image, so this is self-configuring — no per-field size table
// to write, and none to keep in sync when a template changes.
$groups  = picdrop_groups();
$slot    = null;
foreach ($groups as $g) {
    foreach ($g['slots'] as $s) { if ($s['key'] === $key) { $slot = $s; break 2; } }
}
if ($slot === null) pd_fail('That slot no longer exists — reload the tab.');
if (!empty($slot['token'])) {
    pd_fail('This slot is filled per city from ' . $slot['value']
        . '. Replacing it with one file would pin every city to the same picture.');
}

if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);

$base     = pathinfo((string) $_FILES['file']['name'], PATHINFO_FILENAME);
$base     = strtolower(preg_replace('/[^a-z0-9_-]/i', '-', $base)) ?: 'image';
$filename = $base . '_' . substr(md5(uniqid('', true)), 0, 6) . '.webp';
$dest     = MEDIA_DIR . $filename;

[$ok, $note] = img_fit_to($tmpFile, $dest, $mime, (int) $slot['w'], (int) $slot['h']);
if (!$ok) pd_fail('Could not process that image.');

/* Keep the full-size original so the picture can be re-cropped later. The slot file
   is already trimmed to the slot, so without this a later "zoom out" would have no
   pixels to reveal. Stored OUTSIDE uploads/ — see ORIGINALS_DIR — so it is never
   deployed and never pruned. */
$origName = '';
if (media_originals_dir() !== null) {
    $origName = preg_replace('/\.webp$/', '', $filename) . '.orig.webp';
    // Held as webp at up to MAX_WIDTH rather than the raw upload: a 12 MB phone JPEG
    // is no more useful as a crop source than a 1800px webp, and this is disk we keep
    // for every drop.
    if (!img_optimize($tmpFile, ORIGINALS_DIR . $origName, $mime)) $origName = '';
}

[$nw, $nh] = @getimagesize($dest) ?: [0, 0];

media_register([
    'filename'   => $filename,
    'url'        => UPLOAD_URL . 'media/' . $filename,
    'width'      => (int) $nw,
    'height'     => (int) $nh,
    'size'       => (int) filesize($dest),
    'alt'        => $slot['alt'],
    'tags'       => ['picdrop'],
    'source_url' => '',
    'added_at'   => date('Y-m-d H:i:s'),
    // Crop state, so reopening the adjuster resumes where you left it rather than
    // snapping back to centre.
    'origin'     => $origName,
    'adjust'     => ['zoom' => 1.0, 'fx' => 0.5, 'fy' => 0.5],
]);

$newValue = UPLOAD_URL . 'media/' . $filename;

// Build the edit list: this slot, plus every other slot holding the same image in the
// same kind of field when propagate is on.
$edits = [[
    'scope' => $parts['scope'], 'id' => $parts['id'],
    'block' => $parts['block'], 'field' => $parts['field'], 'value' => $newValue,
]];

$propagated = 0;
$templates  = 0;
if (!empty($_POST['propagate'])) {
    foreach (picdrop_matching_slots($key, $slot['value'], picdrop_leaf($parts['field'])) as $m) {
        $edits[] = [
            'scope' => $m['scope'], 'id' => $m['page_id'],
            'block' => $m['block'], 'field' => $m['field'], 'value' => $newValue,
        ];
        $propagated++;
    }
    // Also fix the landing templates these pages are generated from. Without this the
    // next regen puts the old picture straight back, which reads as "Pic Drop did not
    // save" long after the drop.
    foreach (picdrop_template_matches($slot['value'], picdrop_leaf($parts['field'])) as $t) {
        $edits[] = $t + ['value' => $newValue];
        $templates++;
    }
}

// seo.og_image follows the picture wherever it was pointing at this exact file. Not
// gated on propagate: if THIS page's social image was the picture just replaced, it
// should follow regardless.
$ogUpdated = 0;
foreach (picdrop_og_matches($slot['value']) as $og) {
    $edits[] = $og + ['value' => $newValue];
    $ogUpdated++;
}

$res = picdrop_apply_edits($edits);
if ($res['ok'] === 0) {
    @unlink($dest);
    pd_fail($res['errors'][0] ?? 'The image was processed but nothing could be written.');
}

echo json_encode([
    'success'    => true,
    'url'        => $newValue,
    'filename'   => $filename,
    'width'      => (int) $nw,
    'height'     => (int) $nh,
    'note'       => $note,
    'screened'   => $screened,
    'propagated' => $propagated,
    'templates'  => $templates,
    'og_updated' => $ogUpdated,
    'errors'     => $res['errors'],
]);
