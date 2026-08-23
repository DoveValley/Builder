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
]);

$newValue = UPLOAD_URL . 'media/' . $filename;

// Build the edit list: this slot, plus every other slot holding the same image in the
// same kind of field when propagate is on.
$edits = [[
    'scope' => $parts['scope'], 'id' => $parts['id'],
    'block' => $parts['block'], 'field' => $parts['field'], 'value' => $newValue,
]];

$propagated = 0;
if (!empty($_POST['propagate'])) {
    foreach (picdrop_matching_slots($key, $slot['value'], picdrop_leaf($parts['field'])) as $m) {
        $edits[] = [
            'scope' => $m['scope'], 'id' => $m['page_id'],
            'block' => $m['block'], 'field' => $m['field'], 'value' => $newValue,
        ];
        $propagated++;
    }
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
    'errors'     => $res['errors'],
]);
