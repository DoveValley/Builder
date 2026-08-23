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

// Source images for the overlay preview — THIS site's own media only (not every
// site in the fleet, unlike Test Lab's picker, which browsed everything on the
// box). Only photo-sized images; icons/logos/thumbnails filtered out.
$giSrcOptions = [];
foreach (glob(ACTIVE_SITE_DIR . '/uploads/{media,}/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
    $sz = @getimagesize($f);
    if (!$sz || (int)$sz[0] < 480 || (int)$sz[1] < 240) continue;
    $giSrcOptions[] = ['p' => 'sites/' . ACTIVE_SITE_ID . substr($f, strlen(ACTIVE_SITE_DIR)), 'w' => (int)$sz[0], 'h' => (int)$sz[1], 'a' => (int)$sz[0] * (int)$sz[1]];
}
usort($giSrcOptions, fn($a, $b) => $b['a'] <=> $a['a']);   // largest first
$giSrcOptions = array_slice($giSrcOptions, 0, 25);

// Currently-locked overlay style, if any — seeds the controls so this screen
// shows what the build actually uses, not just the form's own defaults.
$giLockedStyle = @json_decode((string)@file_get_contents(BASE_DIR . '/multisite/hero_style.json'), true) ?: [];
$giLS = fn($k, $d) => $giLockedStyle[$k] ?? $d;

// Currently-saved variation ranges, filled in against the real defaults so the
// form always shows real numbers, never blanks.
$giRanges = ms_image_variation_ranges(
    @json_decode((string)@file_get_contents(BASE_DIR . '/multisite/image_variation.json'), true) ?: []
);
$giVaryLocked = is_file(BASE_DIR . '/multisite/image_variation.json');

$gh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<div class="tab-content" style="<?= $tab === 'genimage' ? '' : 'display:none;' ?>">

<div class="card">
    <h2>&#128248; Hero text overlay</h2>
    <p class="hint">Bakes two lines &mdash; <strong>keyword</strong> + <strong>city, ST</strong> &mdash; onto a hero image,
        the way every generated site will. Read-only here: nothing is written to any site's uploads until you press
        <strong>Lock this style</strong>. Applies fleet-wide (every batch build uses this) unless a master has its own
        override file.</p>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:24px;align-items:start;margin-top:14px;">
        <div>
            <div class="form-group">
                <label for="gi-src">Source image</label>
                <select id="gi-src" style="width:100%;">
                    <?php foreach ($giSrcOptions as $img): ?>
                        <option value="<?= $gh($img['p']) ?>"><?= $gh(basename($img['p'])) ?> (<?= $img['w'] ?>&times;<?= $img['h'] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">This site's own photos only &mdash; only photo-sized images shown (icons/logos hidden).</span>
            </div>
            <div class="form-group">
                <label for="gi-line1">Line 1 &mdash; keyword</label>
                <input type="text" id="gi-line1" value="Cockroach Exterminator" maxlength="60">
            </div>
            <div class="form-group">
                <label for="gi-line2">Line 2 &mdash; city, ST</label>
                <input type="text" id="gi-line2" value="Dallas, TX" maxlength="60">
            </div>
            <div class="form-group">
                <label for="gi-line3">Line 3 &mdash; optional</label>
                <input type="text" id="gi-line3" value="" maxlength="60" placeholder="(leave blank for 2 lines)">
            </div>
            <div style="display:flex;gap:12px;">
                <div class="form-group" style="flex:1;">
                    <label for="gi-pos">Position</label>
                    <select id="gi-pos" style="width:100%;">
                        <option value="bl" <?= $giLS('pos','bl') === 'bl' ? 'selected' : '' ?>>Bottom left</option>
                        <option value="bc" <?= $giLS('pos','bl') === 'bc' ? 'selected' : '' ?>>Bottom center</option>
                        <option value="tl" <?= $giLS('pos','bl') === 'tl' ? 'selected' : '' ?>>Top left</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="gi-c2">City color</label>
                    <input type="color" id="gi-c2" value="<?= $gh($giLS('c2','#fd783b')) ?>">
                </div>
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
                <label>Dark fade height <span style="color:#64748b;font-weight:400">(readability)</span> <output id="gi-scrimo" style="color:#64748b;font-weight:400"></output></label>
                <input type="range" id="gi-scrim" min="0" max="600" value="<?= (int)$giLS('scrim',300) ?>" style="width:100%;">
            </div>
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
                <button type="button" id="gi-lockbtn" class="btn btn-primary">&#128274; Lock this style into the build</button>
                <div id="gi-lockmsg" class="hint" style="margin-top:8px;"></div>
                <?php if ($giLockedStyle): ?><div class="hint" style="margin-top:4px;color:#065f46;">A locked style is active &mdash; every build uses it. Adjust above and re-lock to change.</div><?php endif; ?>
            </div>
        </div>
        <div>
            <div style="background:#0f172a;border-radius:10px;padding:16px;text-align:center;">
                <img id="gi-out" alt="preview" src="" style="max-width:100%;height:auto;border-radius:6px;">
                <div id="gi-err" style="display:none;color:#fca5a5;font-family:monospace;font-size:.78rem;text-align:left;white-space:pre-wrap;padding:12px;line-height:1.5;"></div>
            </div>
            <p class="hint" style="margin-top:10px;">Both lines come from data the system already stores: the page's
                <code>primary_keyword</code> and the site's <code>city</code>/<code>SS</code>. Nudge position/size/color
                to taste, then lock it in &mdash; sizes scale proportionally to each hero's actual dimensions.</p>
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
        <?php if ($giVaryLocked): ?><div class="hint" style="margin-top:4px;color:#065f46;">Custom ranges are saved &mdash; every build uses them. Leaving all four at their original numbers is the same as never having saved this.</div>
        <?php else: ?><div class="hint" style="margin-top:4px;color:#94a3b8;">Nothing saved yet &mdash; builds are using the original hardcoded numbers shown above.</div><?php endif; ?>
    </div>
</div>

<script>
(function () {
    var giCsrf = <?= json_encode($csrfToken) ?>;
    var giActive = <?= $tab === 'genimage' ? 'true' : 'false' ?>;

    // ── Hero overlay preview (only fetches while this tab is the one showing —
    // no reason to spend an ImageMagick call on every admin page load). ──────
    var ids = ['gi-src','gi-line1','gi-line2','gi-line3','gi-pos','gi-c2','gi-s1','gi-s2','gi-scrim'];
    var el = {}; ids.forEach(function (i) { el[i] = document.getElementById(i); });
    var out = document.getElementById('gi-out'), err = document.getElementById('gi-err');
    function sync() {
        document.getElementById('gi-s1o').textContent = el['gi-s1'].value;
        document.getElementById('gi-s2o').textContent = el['gi-s2'].value;
        document.getElementById('gi-scrimo').textContent = el['gi-scrim'].value;
    }
    function params() {
        return new URLSearchParams({
            src: el['gi-src'].value, line1: el['gi-line1'].value, line2: el['gi-line2'].value, line3: el['gi-line3'].value,
            pos: el['gi-pos'].value, c2: el['gi-c2'].value, s1: el['gi-s1'].value, s2: el['gi-s2'].value, scrim: el['gi-scrim'].value
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

    var lockBtn = document.getElementById('gi-lockbtn'), lockMsg = document.getElementById('gi-lockmsg');
    lockBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf_token', giCsrf);
        fd.append('pos', el['gi-pos'].value);
        fd.append('c1', '#ffffff');
        fd.append('c2', el['gi-c2'].value);
        fd.append('s1', el['gi-s1'].value);
        fd.append('s2', el['gi-s2'].value);
        fd.append('scrim', el['gi-scrim'].value);
        fd.append('ref_w', out.naturalWidth || 715);
        fd.append('ref_h', out.naturalHeight || 600);
        lockMsg.textContent = 'Saving…';
        fetch('hero_style_save.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
            .then(function (d) {
                lockMsg.textContent = d.error ? ('✗ ' + d.error) : '✓ Locked — every build now uses this style.';
                lockMsg.style.color = d.error ? '#991b1b' : '#065f46';
            })
            .catch(function () { lockMsg.textContent = '✗ save failed'; lockMsg.style.color = '#991b1b'; });
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
