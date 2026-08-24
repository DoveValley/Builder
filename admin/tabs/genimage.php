<?php
/**
 * Gen-Image tab — the two things multisite's "Images" build step does to a
 * domain's photos, moved out of Test Lab (a grab-bag of unrelated one-off
 * experiments) into a real home. Both are master-level settings: whatever is
 * set here is exactly what any batch generated from this site inherits.
 * $tab, $csrfToken, ACTIVE_SITE_ID, ACTIVE_SITE_DIR available from index.php.
 *
 *   1) Hero text overlay — bakes "keyword" + "city, ST" onto a hero image.
 *      Ported as-is from Test Lab: same preview endpoint (hero_overlay.php),
 *      same lock endpoint (hero_style_save.php), same multisite/hero_style.json.
 *   2) Photo variation — crops/tone-shifts/re-compresses every OTHER photo so
 *      no two domains in a batch share an identical file. This had no admin
 *      surface at all before — the ranges were hardcoded numbers in
 *      includes/multisite/image_overlay.php with nothing to look at or adjust.
 *      New multisite/image_variation.json, read by ms_perturb_image().
 */
require_once __DIR__ . '/../../includes/multisite/image_overlay.php';

// Source images for the overlay preview — real HERO photos only, picked by the
// exact same ms_hero_image_field() the actual build/generate step uses to
// decide what to stamp. Previously this scanned every big image file under
// uploads/ regardless of what block (if any) it belonged to, so the default
// preview — and the size-based fallback numbers computed from it — could land
// on an unrelated section/background photo instead of an actual hero picture,
// which is a different shape and gave a misleading preview.
$giHeroCandidates = []; // path => true, de-duped across every page sharing one
$giCollectHeroImages = function (array $blocks) use (&$giHeroCandidates) {
    foreach ($blocks as $b) {
        if (!is_array($b)) continue;
        $field = ms_hero_image_field($b);
        if ($field === null) continue;
        // The pre-stamp original (if this block has already been baked once)
        // wins over the current value — same preference ms_stamp_blocks() uses,
        // so the preview never shows text baked on top of already-baked text.
        $origKey = '_' . $field . '_orig';
        $v = (isset($b[$origKey]) && is_string($b[$origKey]) && $b[$origKey] !== '') ? $b[$origKey] : ($b[$field] ?? '');
        if (is_string($v) && $v !== '') $giHeroCandidates[$v] = true;
    }
};
$giCollectHeroImages($data['content_blocks'] ?? []);
foreach (glob(PAGES_DIR . '*.json') ?: [] as $pf) {
    // glob's own *.json pattern already excludes the .bak siblings — nothing
    // ending in .json.bak matches it, so no extra filter is needed here.
    $pg = json_decode((string) @file_get_contents($pf), true);
    if (is_array($pg)) $giCollectHeroImages($pg['content_blocks'] ?? []);
}

$giSrcOptions = [];
foreach (array_keys($giHeroCandidates) as $rel) {
    $f = BASE_DIR . '/' . ltrim($rel, '/');
    $sz = @getimagesize($f);
    if (!$sz) continue;
    $giSrcOptions[] = ['p' => $rel, 'w' => (int)$sz[0], 'h' => (int)$sz[1], 'a' => (int)$sz[0] * (int)$sz[1]];
}
usort($giSrcOptions, fn($a, $b) => $b['a'] <=> $a['a']);   // largest first
$giSrcOptions = array_slice($giSrcOptions, 0, 25);

// A site with zero hero blocks anywhere (shouldn't normally happen, but keeps
// the picker from rendering empty) falls back to the old any-big-photo scan.
if (!$giSrcOptions) {
    foreach (glob(ACTIVE_SITE_DIR . '/uploads/{media,}/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        $sz = @getimagesize($f);
        if (!$sz || (int)$sz[0] < 480 || (int)$sz[1] < 240) continue;
        $giSrcOptions[] = ['p' => 'sites/' . ACTIVE_SITE_ID . substr($f, strlen(ACTIVE_SITE_DIR)), 'w' => (int)$sz[0], 'h' => (int)$sz[1], 'a' => (int)$sz[0] * (int)$sz[1]];
    }
    usort($giSrcOptions, fn($a, $b) => $b['a'] <=> $a['a']);
    $giSrcOptions = array_slice($giSrcOptions, 0, 25);
}

// The live site never shows a hero_split photo at its native size or aspect —
// includes/blocks.php's .hs-image-wrap/.hs-image CSS caps it at 500px wide,
// forced to a 4:3 box via object-fit:cover (site-wide: every one of this
// site's hero blocks is hero_split). The preview below replicates that exact
// box so text/position tuned here isn't previewed bigger and uncropped
// compared to what actually ships — including each photo's real focal point,
// since a non-center focal point shifts which part of the image survives the
// crop, same as get_focal_point() already does for the live render.
$giFocalPoints = [];
foreach ($giSrcOptions as $img) { $giFocalPoints[$img['p']] = get_focal_point($img['p']); }

// Both settings resolve per-master-first, repo-global as fallback — exactly the
// order multisite/build_one.php uses, via the one shared helper, so this screen
// can never show a different file from the one the build reads. Saving always
// writes THIS master's own copy (see ms_image_settings_write()).
$giStyleLoc = ms_image_settings_locate(ACTIVE_SITE_DIR, 'hero_style.json');
$giVaryLoc  = ms_image_settings_locate(ACTIVE_SITE_DIR, 'image_variation.json');

// Currently-locked overlay style, if any — seeds the controls so this screen
// shows what the build actually uses, not just the form's own defaults. When
// nothing is locked, fall back to ms_hero_style()'s OWN no-locked-style output
// (computed against the default preview image's real dimensions) rather than a
// second, hand-picked set of numbers here that can quietly drift from what a
// real build actually falls back to — c2/s1/s2 previously did exactly that
// (c2 showed an orange placeholder while the build's real fallback is white;
// s1/s2 showed flat numbers while the build scales them from image width).
$giLockedStyle = ms_image_settings_read(ACTIVE_SITE_DIR, 'hero_style.json');
$giPreviewImg = $giSrcOptions[0] ?? ['w' => 900, 'h' => 600];
$giFallbackStyle = ms_hero_style((int)$giPreviewImg['w'], (int)$giPreviewImg['h'], []);
$giLS = fn($k, $d) => $giLockedStyle[$k] ?? ($giFallbackStyle[$k] ?? $d);

// Currently-saved variation ranges, filled in against the real defaults so the
// form always shows real numbers, never blanks.
$giRanges     = ms_image_variation_ranges(ms_image_settings_read(ACTIVE_SITE_DIR, 'image_variation.json'));
$giVaryLocked = $giVaryLoc['scope'] !== 'none';

// One shared "where is this coming from" line, so the scope is never ambiguous.
$giScopeNote = function (array $loc) {
    if ($loc['scope'] === 'master') {
        return '<span style="color:#065f46;">Saved for <strong>this site only</strong></span> &mdash; <code>'
             . htmlspecialchars(ACTIVE_SITE_ID, ENT_QUOTES) . '/multisite/' . htmlspecialchars(basename($loc['master']), ENT_QUOTES)
             . '</code>. No other master is affected.';
    }
    if ($loc['scope'] === 'global') {
        return '<span style="color:#b45309;">Currently inheriting the shared fallback</span> <code>multisite/'
             . htmlspecialchars(basename($loc['global']), ENT_QUOTES)
             . '</code>, which every master without its own file uses. Saving below writes a copy for '
             . '<strong>this site only</strong> and stops it inheriting.';
    }
    return '<span style="color:#94a3b8;">Nothing saved for this site and no shared fallback</span> &mdash; builds use the '
         . 'built-in defaults. Saving writes <code>' . htmlspecialchars(ACTIVE_SITE_ID, ENT_QUOTES) . '/multisite/'
         . htmlspecialchars(basename($loc['master']), ENT_QUOTES) . '</code>, for this site only.';
};

$gh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<div class="tab-content" style="<?= $tab === 'genimage' ? '' : 'display:none;' ?>">
<?php // tab_header() runs h() over both strings — raw characters, not HTML entities. ?>
<?php tab_header('Gen-Image', 'What the build does to a generated domain\'s photos — the hero text baked onto each hero, and the variation that makes every other photo byte-unique.', 'tab-genimage'); ?>

<div class="card">
    <h2>&#128248; Hero text overlay</h2>
    <p class="hint">Bakes two lines &mdash; <strong>keyword</strong> + <strong>city, ST</strong> &mdash; onto a hero image,
        the way every generated site will. Read-only here: nothing is written to any site's uploads until you press
        <strong>Lock this style</strong>. What you lock applies to <strong>every domain generated from this site</strong>,
        and to no other master.</p>
    <p class="hint" style="margin-top:8px;"><?= $giScopeNote($giStyleLoc) ?></p>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:24px;align-items:start;margin-top:14px;">
        <div>
            <h3 style="margin:0 0 10px;font-size:.95rem;color:#334155;">Text</h3>
            <div class="form-group">
                <label for="gi-src">Source image</label>
                <select id="gi-src" style="width:100%;">
                    <?php foreach ($giSrcOptions as $img): ?>
                        <option value="<?= $gh($img['p']) ?>"><?= $gh(basename($img['p'])) ?> (<?= $img['w'] ?>&times;<?= $img['h'] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">This site's own photos only &mdash; only photo-sized images shown (icons/logos hidden).</span>
            </div>
            <div style="display:flex;gap:10px;align-items:flex-end;">
                <div class="form-group" style="flex:1;">
                    <label for="gi-line1">Line 1 &mdash; keyword</label>
                    <input type="text" id="gi-line1" value="Cockroach Exterminator" maxlength="60">
                </div>
                <div class="form-group">
                    <label for="gi-j1">Justify</label>
                    <select id="gi-j1">
                        <option value="left" <?= $giLS('j1','left') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= $giLS('j1','left') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= $giLS('j1','left') === 'right' ? 'selected' : '' ?>>Right</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:flex-end;">
                <div class="form-group" style="flex:1;">
                    <label for="gi-line2">Line 2 &mdash; city, ST</label>
                    <input type="text" id="gi-line2" value="Dallas, TX" maxlength="60">
                </div>
                <div class="form-group">
                    <label for="gi-j2">Justify</label>
                    <select id="gi-j2">
                        <option value="left" <?= $giLS('j2','left') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= $giLS('j2','left') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= $giLS('j2','left') === 'right' ? 'selected' : '' ?>>Right</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:flex-end;">
                <div class="form-group" style="flex:1;">
                    <label for="gi-line3">Line 3 &mdash; optional</label>
                    <input type="text" id="gi-line3" value="" maxlength="60" placeholder="(leave blank for 2 lines)">
                </div>
                <div class="form-group">
                    <label for="gi-j3">Justify</label>
                    <select id="gi-j3">
                        <option value="left" <?= $giLS('j3','left') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= $giLS('j3','left') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= $giLS('j3','left') === 'right' ? 'selected' : '' ?>>Right</option>
                    </select>
                </div>
            </div>
            <p class="hint" style="margin:-6px 0 10px;">Line 1 justifies relative to the image width (inset by the X position below); line 2/3 justify relative to line 1's own width &mdash; e.g. a short city line can center or right-align under a longer keyword line.</p>
            <div class="form-group">
                <label for="gi-c2">City color</label>
                <input type="color" id="gi-c2" value="<?= $gh($giLS('c2','#fd783b')) ?>">
            </div>
            <div class="form-group">
                <label>Keyword size <output id="gi-s1o" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-s1" min="20" max="90" value="<?= (int)$giLS('s1',44) ?>" style="width:100%;">
            </div>
            <div class="form-group">
                <label>City size <output id="gi-s2o" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-s2" min="16" max="80" value="<?= (int)$giLS('s2',40) ?>" style="width:100%;">
            </div>
            <div class="form-group">
                <label>Horizontal position <output id="gi-xo" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-x" min="0" max="100" value="<?= (int)$giLS('x',5) ?>" style="width:100%;">
            </div>
            <div class="form-group">
                <label>Vertical position <output id="gi-yo" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-y" min="0" max="100" value="<?= (int)$giLS('y',80) ?>" style="width:100%;">
                <span class="hint">Both are % of the image, measured from the top-left corner &mdash; place text anywhere, not just a preset corner.</span>
            </div>

            <h3 style="margin:18px 0 10px;padding-top:14px;border-top:1px solid #e2e8f0;font-size:.95rem;color:#334155;">Background</h3>
            <div class="form-group">
                <label for="gi-bgside">Edge</label>
                <select id="gi-bgside" style="width:100%;">
                    <option value="bottom" <?= $giLS('bg_side','bottom') === 'bottom' ? 'selected' : '' ?>>Bottom band</option>
                    <option value="top" <?= $giLS('bg_side','bottom') === 'top' ? 'selected' : '' ?>>Top band</option>
                    <option value="full" <?= $giLS('bg_side','bottom') === 'full' ? 'selected' : '' ?>>Full image tint</option>
                    <option value="none" <?= $giLS('bg_side','bottom') === 'none' ? 'selected' : '' ?>>None</option>
                </select>
                <span class="hint">Independent of where the text sits above &mdash; e.g. text can be centered while the band still hugs the bottom edge.</span>
            </div>
            <div class="form-group" id="gi-bgheight-row">
                <label>Band height <output id="gi-bgheighto" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-bgheight" min="0" max="100" value="<?= (int)$giLS('bg_height',55) ?>" style="width:100%;">
            </div>
            <div class="form-group" id="gi-bgfade-row">
                <label><input type="checkbox" id="gi-bgfade" <?= $giLS('bg_fade',true) ? 'checked' : '' ?>> Fade (soft gradient) &mdash; unchecked is a flat, hard-edged band</label>
            </div>
            <div class="form-group">
                <label>Darkness <output id="gi-bgopacityo" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-bgopacity" min="0" max="100" value="<?= (int)$giLS('bg_opacity',100) ?>" style="width:100%;">
            </div>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button type="button" id="gi-lockbtn" class="btn btn-primary">&#128274; Lock this style into the build</button>
                    <button type="button" id="gi-defaultbtn" class="btn btn-secondary">Default</button>
                </div>
                <div id="gi-lockmsg" class="hint" style="margin-top:8px;"></div>
                <?php if ($giLockedStyle): ?><div class="hint" style="margin-top:4px;">A locked style is active &mdash; every build of this site's domains uses it. Adjust above and re-lock to change.<br><?= $giScopeNote($giStyleLoc) ?></div><?php endif; ?>
                <span class="hint"><strong>Default</strong> resets the controls above back to <?= $giLockedStyle ? "this site's currently locked style" : "the plain fallback style (nothing is locked for this site yet)" ?> &mdash; discarding whatever you've changed since the page loaded. It doesn't save anything; press <strong>Lock this style</strong> to persist a change.</span>
            </div>
        </div>
        <div>
            <div style="background:#0f172a;border-radius:10px;padding:16px;text-align:center;">
                <img id="gi-out" alt="preview" src="" style="width:500px;max-width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:6px;">
                <div id="gi-err" style="display:none;color:#fca5a5;font-family:monospace;font-size:.78rem;text-align:left;white-space:pre-wrap;padding:12px;line-height:1.5;"></div>
            </div>
            <p class="hint" style="margin-top:8px;">Shown at the site's real display size for a hero photo &mdash; capped at
                500px wide and cropped to 4:3 (<code>object-fit:cover</code>), exactly like <code>.hs-image-wrap</code> on
                the live page. The raw image is always rendered full-size first; this box only crops what's <em>displayed</em>,
                the same as the browser does &mdash; nothing here changes the file that gets baked.</p>
            <div class="form-group" style="margin-top:10px;">
                <label><input type="checkbox" id="gi-edgeguide"> Show image edge guide</label>
                <span class="hint">A thin white outline around the preview so you can see exactly where the image's edges are while placing text. This box only &mdash; never rendered onto the actual image or the live site.</span>
            </div>
            <p class="hint" style="margin-top:10px;">Both lines come from data the system already stores: the page's
                <code>primary_keyword</code> and the site's <code>city</code>/<code>SS</code>. Nudge position/background/color
                to taste, then lock it in &mdash; position and background are percentages of each hero's actual dimensions, so
                the look holds steady across heroes of any size.</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h2>&#127912; Photo variation</h2>
    <p class="hint" style="margin-bottom:14px;">
        Every OTHER photo on a batch-generated domain (not the hero, which the overlay above handles) gets a
        small, automatic treatment so two domains built from the same master never share an identical image file:
    </p>
    <ul class="hint" style="margin:0 0 14px;padding-left:20px;line-height:1.6;">
        <li><strong>Cropped</strong> slightly off-center &mdash; a sliver taken off two edges, not a visible reframe.</li>
        <li><strong>Brightness and saturation</strong> nudged a few percent, up or down.</li>
        <li><strong>Re-compressed</strong> at a slightly different quality level, which also strips the original file's metadata.</li>
        <li>The <strong>file name always gets that domain's city appended</strong> (e.g. <code>hero-topeka.jpg</code> &rarr;
            <code>hero-overland-park.jpg</code>) &mdash; this is deliberate and not adjustable here: a city-named image file
            is a small, real signal for that city's own page, so it stays regardless of the ranges below.</li>
    </ul>
    <p class="hint" style="margin-bottom:14px;">
        Until now none of this had any setting anywhere &mdash; the four ranges below were plain numbers written
        directly into the code. The same domain always lands on the same numbers within whatever range you set
        (so a rebuild doesn't re-shuffle a photo that hasn't otherwise changed); different domains land on different
        numbers within that range.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
        <div class="form-group">
            <label>Crop amount (% of width/height)</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="number" id="gi-crop-min" value="<?= $gh($giRanges['crop_min']) ?>" min="0" max="10" step="0.1" style="width:80px;"> to
                <input type="number" id="gi-crop-max" value="<?= $gh($giRanges['crop_max']) ?>" min="0" max="10" step="0.1" style="width:80px;">
            </div>
        </div>
        <div class="form-group">
            <label>Brightness (%)</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="number" id="gi-bright-min" value="<?= $gh($giRanges['brightness_min']) ?>" min="80" max="120" style="width:80px;"> to
                <input type="number" id="gi-bright-max" value="<?= $gh($giRanges['brightness_max']) ?>" min="80" max="120" style="width:80px;">
            </div>
        </div>
        <div class="form-group">
            <label>Saturation (%)</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="number" id="gi-sat-min" value="<?= $gh($giRanges['saturation_min']) ?>" min="80" max="120" style="width:80px;"> to
                <input type="number" id="gi-sat-max" value="<?= $gh($giRanges['saturation_max']) ?>" min="80" max="120" style="width:80px;">
            </div>
        </div>
        <div class="form-group">
            <label>Output quality</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="number" id="gi-q-min" value="<?= $gh($giRanges['quality_min']) ?>" min="40" max="100" style="width:80px;"> to
                <input type="number" id="gi-q-max" value="<?= $gh($giRanges['quality_max']) ?>" min="40" max="100" style="width:80px;">
            </div>
        </div>
    </div>
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
        <button type="button" id="gi-varybtn" class="btn btn-primary">Save these ranges</button>
        <div id="gi-varymsg" class="hint" style="margin-top:8px;"></div>
        <div class="hint" style="margin-top:4px;">
            <?php if ($giVaryLocked): ?>Custom ranges are in effect &mdash; every build of this site's domains uses them.
                Leaving all four at their original numbers is the same as never having saved this.<br><?php endif; ?>
            <?= $giScopeNote($giVaryLoc) ?>
        </div>
    </div>
</div>

<script>
(function () {
    var giCsrf = <?= json_encode($csrfToken) ?>;
    var giActive = <?= $tab === 'genimage' ? 'true' : 'false' ?>;
    // Each photo's real crop focal point (same data get_focal_point() reads on
    // the live page) — so the preview's 4:3 crop centers on the same spot the
    // real hero image does, not always the geometric center.
    var giFocalPoints = <?= json_encode($giFocalPoints) ?>;

    // ── Hero overlay preview (only fetches while this tab is the one showing —
    // no reason to spend an ImageMagick call on every admin page load). ──────
    var ids = ['gi-src','gi-line1','gi-line2','gi-line3','gi-c2','gi-s1','gi-s2','gi-x','gi-y','gi-j1','gi-j2','gi-j3',
               'gi-bgside','gi-bgheight','gi-bgfade','gi-bgopacity'];
    var el = {}; ids.forEach(function (i) { el[i] = document.getElementById(i); });
    // Snapshot of every control's server-rendered starting value (this site's
    // locked style, or the plain fallback if nothing's locked) — captured once,
    // before any user edits, so "Default" restores the real thing instead of a
    // second, separately-hardcoded guess that can drift from it. Deliberately
    // excludes gi-src — resetting the style shouldn't also swap the preview photo.
    var giDefaults = {};
    ids.forEach(function (i) {
        if (i === 'gi-src') return;
        giDefaults[i] = (el[i].type === 'checkbox') ? el[i].checked : el[i].value;
    });
    var out = document.getElementById('gi-out'), err = document.getElementById('gi-err');
    var bgHeightRow = document.getElementById('gi-bgheight-row'), bgFadeRow = document.getElementById('gi-bgfade-row');
    function syncBgVisibility() {
        var side = el['gi-bgside'].value;
        bgHeightRow.style.display = (side === 'full' || side === 'none') ? 'none' : '';
        bgFadeRow.style.display = (side === 'full' || side === 'none') ? 'none' : '';
    }
    function sync() {
        document.getElementById('gi-s1o').textContent = el['gi-s1'].value;
        document.getElementById('gi-s2o').textContent = el['gi-s2'].value;
        document.getElementById('gi-xo').textContent = el['gi-x'].value + '% from left';
        document.getElementById('gi-yo').textContent = el['gi-y'].value + '% from top';
        document.getElementById('gi-bgheighto').textContent = el['gi-bgheight'].value + '%';
        document.getElementById('gi-bgopacityo').textContent = el['gi-bgopacity'].value + '%';
        out.style.objectPosition = giFocalPoints[el['gi-src'].value] || '50% 50%';
        syncBgVisibility();
    }
    function params() {
        return new URLSearchParams({
            src: el['gi-src'].value, line1: el['gi-line1'].value, line2: el['gi-line2'].value, line3: el['gi-line3'].value,
            x: el['gi-x'].value, y: el['gi-y'].value, j1: el['gi-j1'].value, j2: el['gi-j2'].value, j3: el['gi-j3'].value,
            c2: el['gi-c2'].value, s1: el['gi-s1'].value, s2: el['gi-s2'].value,
            bg_side: el['gi-bgside'].value, bg_height: el['gi-bgheight'].value,
            bg_fade: el['gi-bgfade'].checked ? '1' : '0', bg_opacity: el['gi-bgopacity'].value
        });
    }
    out.onload = function () { err.style.display = 'none'; out.style.display = ''; };
    out.onerror = function () {
        if (!out.src) return;   // blank src fires onerror too — nothing to report
        var q = params(); q.set('debug', '1');
        fetch('hero_overlay.php?' + q.toString()).then(function (r) { return r.text(); })
            .then(function (txt) { out.style.display = 'none'; err.style.display = 'block'; err.textContent = 'Preview failed:\n\n' + txt; })
            .catch(function () { out.style.display = 'none'; err.style.display = 'block'; err.textContent = 'Preview failed and the diagnostic request also failed.'; });
    };
    var t = null;
    function render() {
        if (!el['gi-src'].value) return;
        sync();
        var q = params(); q.set('_', Date.now());
        out.src = 'hero_overlay.php?' + q.toString();
    }
    function schedule() { clearTimeout(t); t = setTimeout(render, 180); }
    ids.forEach(function (i) { el[i].addEventListener('input', schedule); el[i].addEventListener('change', schedule); });
    if (giActive) render();

    // ── Image edge guide — preview-only visual aid, never sent to the server or
    // included in the locked style. ──────────────────────────────────────────
    var edgeGuide = document.getElementById('gi-edgeguide');
    function applyEdgeGuide() {
        out.style.outline = edgeGuide.checked ? '2px dashed #fff' : 'none';
        out.style.outlineOffset = edgeGuide.checked ? '-2px' : '0';
    }
    edgeGuide.addEventListener('change', applyEdgeGuide);
    applyEdgeGuide();

    // Shared by both save buttons below — the current on-screen style, as FormData.
    function styleFormData() {
        var fd = new FormData();
        fd.append('csrf_token', giCsrf);
        fd.append('x', el['gi-x'].value);
        fd.append('y', el['gi-y'].value);
        fd.append('j1', el['gi-j1'].value);
        fd.append('j2', el['gi-j2'].value);
        fd.append('j3', el['gi-j3'].value);
        fd.append('c1', '#ffffff');
        fd.append('c2', el['gi-c2'].value);
        fd.append('s1', el['gi-s1'].value);
        fd.append('s2', el['gi-s2'].value);
        fd.append('bg_side', el['gi-bgside'].value);
        fd.append('bg_height', el['gi-bgheight'].value);
        fd.append('bg_fade', el['gi-bgfade'].checked ? '1' : '0');
        fd.append('bg_opacity', el['gi-bgopacity'].value);
        fd.append('ref_w', out.naturalWidth || 715);
        return fd;
    }

    var lockMsg = document.getElementById('gi-lockmsg');
    document.getElementById('gi-lockbtn').addEventListener('click', function () {
        lockMsg.textContent = 'Saving…';
        fetch('hero_style_save.php', { method: 'POST', body: styleFormData() }).then(function (r) { return r.json(); })
            .then(function (d) {
                lockMsg.textContent = d.error ? ('✗ ' + d.error) : '✓ Locked — every build of this site now uses this style.';
                lockMsg.style.color = d.error ? '#991b1b' : '#065f46';
            })
            .catch(function () { lockMsg.textContent = '✗ save failed'; lockMsg.style.color = '#991b1b'; });
    });

    // ── Default — restores every control to the snapshot captured on page load
    // (this site's own locked style, or the plain fallback). Pure form reset,
    // nothing is written to disk; press "Lock this style" to persist a change.
    document.getElementById('gi-defaultbtn').addEventListener('click', function () {
        Object.keys(giDefaults).forEach(function (i) {
            if (el[i].type === 'checkbox') el[i].checked = giDefaults[i];
            else el[i].value = giDefaults[i];
        });
        lockMsg.textContent = '';
        sync();
        schedule();
    });

    // ── Photo-variation ranges ────────────────────────────────────────────────
    var varyBtn = document.getElementById('gi-varybtn'), varyMsg = document.getElementById('gi-varymsg');
    varyBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf_token', giCsrf);
        [['gi-crop-min','crop_min'],['gi-crop-max','crop_max'],
         ['gi-bright-min','brightness_min'],['gi-bright-max','brightness_max'],
         ['gi-sat-min','saturation_min'],['gi-sat-max','saturation_max'],
         ['gi-q-min','quality_min'],['gi-q-max','quality_max']].forEach(function (pair) {
            fd.append(pair[1], document.getElementById(pair[0]).value);
        });
        varyMsg.textContent = 'Saving…';
        fetch('image_variation_save.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
            .then(function (d) {
                varyMsg.textContent = d.error ? ('✗ ' + d.error) : '✓ Saved — every build now uses these ranges.';
                varyMsg.style.color = d.error ? '#991b1b' : '#065f46';
            })
            .catch(function () { varyMsg.textContent = '✗ save failed'; varyMsg.style.color = '#991b1b'; });
    });
})();
</script>
</div>
