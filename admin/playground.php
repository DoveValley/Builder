<?php
/**
 * Test Lab — a permanent playground for previewing generator features before
 * they're wired into the build. First test: the per-site hero text overlay (4c).
 * Add more tests by adding a panel + (optionally) a backend endpoint.
 * Auth required. Read-only: nothing here writes into any site.
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

// Gather candidate source images from every site's uploads. Only photo-sized
// images (icons/logos/thumbnails filtered out), largest first, dimensions shown.
$srcOptions = [];
foreach (glob(BASE_DIR . '/sites/*', GLOB_ONLYDIR) ?: [] as $siteDir) {
    $sid = basename($siteDir);
    $imgs = [];
    foreach (glob($siteDir . '/uploads/{media,}/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        $sz = @getimagesize($f);
        if (!$sz || (int)$sz[0] < 480 || (int)$sz[1] < 240) continue;   // skip icons/logos
        $imgs[] = ['p' => 'sites/' . $sid . substr($f, strlen($siteDir)), 'w' => (int)$sz[0], 'h' => (int)$sz[1], 'a' => (int)$sz[0] * (int)$sz[1]];
    }
    if ($imgs) {
        usort($imgs, fn($a, $b) => $b['a'] <=> $a['a']);          // largest first
        $srcOptions[$sid] = array_slice($imgs, 0, 25);
    }
}
$defaultSrc = 'sites/pest-template/uploads/media/about-whitefly-treatment-katy_93c79d.webp';
// If the known pest hero didn't survive the filter, fall back to the first listed image.
$haveDefault = false;
foreach ($srcOptions as $imgs) { foreach ($imgs as $im) { if ($im['p'] === $defaultSrc) { $haveDefault = true; break 2; } } }
if (!$haveDefault) { foreach ($srcOptions as $imgs) { if ($imgs) { $defaultSrc = $imgs[0]['p']; break; } } }
$csrf = $_SESSION['csrf_token'] ?? '';
// Currently-locked style (if any) → seed the controls so the Lab shows what the build uses.
$lockedStyle = @json_decode((string)@file_get_contents(BASE_DIR . '/multisite/hero_style.json'), true) ?: [];
$ls = fn($k, $d) => $lockedStyle[$k] ?? $d;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test Lab — Site Factory</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:15px;color:#1e293b;background:#f8fafc;display:flex;min-height:100vh}
#side{width:230px;flex-shrink:0;background:#1e3a5f;color:#cbd5e1;padding:20px 0}
#side .logo{font-weight:800;padding:0 20px 16px;font-size:1.05rem;border-bottom:1px solid rgba(255,255,255,.12);margin-bottom:12px}
#side .logo small{display:block;font-weight:500;color:#94a3b8;font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;margin-top:4px}
#side a,#side button{display:block;width:100%;text-align:left;background:none;border:0;color:#cbd5e1;padding:9px 20px;font-size:.9rem;cursor:pointer;text-decoration:none}
#side a:hover,#side button:hover{background:rgba(255,255,255,.06);color:#fff}
#side .active{background:rgba(255,255,255,.12);color:#fff;font-weight:600;border-left:3px solid #fd783b}
#side .back{margin-top:18px;color:#94a3b8;font-size:.82rem}
main{flex:1;padding:28px 34px;max-width:1200px}
h1{font-size:1.4rem;color:#1e3a5f;margin-bottom:4px}
.sub{color:#64748b;margin-bottom:22px;font-size:.9rem}
.lab{display:grid;grid-template-columns:340px 1fr;gap:26px;align-items:start}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px}
.card h3{font-size:1rem;color:#1e3a5f;margin-bottom:14px}
label{display:block;font-size:.78rem;font-weight:600;color:#475569;margin:12px 0 4px}
label:first-of-type{margin-top:0}
input[type=text],select,input[type=number]{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.88rem}
input[type=color]{width:48px;height:34px;border:1px solid #cbd5e1;border-radius:6px;padding:2px;background:#fff;vertical-align:middle}
.row{display:flex;gap:12px}.row>div{flex:1}
.rng{display:flex;align-items:center;gap:8px}.rng input[type=range]{flex:1}
.rng output{font-size:.8rem;color:#64748b;min-width:34px;text-align:right}
.preview{background:#0f172a;border-radius:10px;padding:16px;text-align:center}
.preview img{max-width:100%;height:auto;border-radius:6px}
.note{font-size:.8rem;color:#64748b;margin-top:10px;line-height:1.5}
.pill{display:inline-block;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:600;margin-left:8px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:.82em}
</style>
</head>
<body>
<div id="side">
    <div class="logo">Site Factory <small>Test Lab</small></div>
    <button type="button" class="active">Hero text overlay</button>
    <a class="back" href="docs.php">← Documentation</a>
    <a class="back" href="index.php">← Admin</a>
</div>
<main>
    <h1>Hero text overlay <span class="pill">4c · preview</span></h1>
    <p class="sub">Bake two lines — <strong>keyword</strong> + <strong>city, ST</strong> — onto a hero image, the way each generated site will. Read-only: originals are never touched. Tune the look here, then we wire the chosen style into the build.</p>

    <div class="lab">
        <div class="card">
            <h3>Controls</h3>

            <label for="src">Source image</label>
            <select id="src">
                <?php foreach ($srcOptions as $sid => $imgs): ?>
                    <optgroup label="<?= $h($sid) ?>">
                        <?php foreach ($imgs as $img): ?>
                            <option value="<?= $h($img['p']) ?>" <?= $img['p'] === $defaultSrc ? 'selected' : '' ?>><?= $h(basename($img['p'])) ?> (<?= $img['w'] ?>×<?= $img['h'] ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <span class="note">Only photo-sized images shown (icons/logos hidden).</span>

            <label for="line1">Line 1 — keyword</label>
            <input type="text" id="line1" value="Cockroach Exterminator" maxlength="60">

            <label for="line2">Line 2 — city, ST</label>
            <input type="text" id="line2" value="Dallas, TX" maxlength="60">

            <label for="line3">Line 3 — optional (e.g. business)</label>
            <input type="text" id="line3" value="" maxlength="60" placeholder="(leave blank for 2 lines)">

            <div class="row">
                <div>
                    <label for="pos">Position</label>
                    <select id="pos">
                        <option value="bl" <?= $ls('pos','bl') === 'bl' ? 'selected' : '' ?>>Bottom left</option>
                        <option value="bc" <?= $ls('pos','bl') === 'bc' ? 'selected' : '' ?>>Bottom center</option>
                        <option value="tl" <?= $ls('pos','bl') === 'tl' ? 'selected' : '' ?>>Top left</option>
                    </select>
                </div>
                <div>
                    <label for="c2">City color</label>
                    <input type="color" id="c2" value="<?= $h($ls('c2','#fd783b')) ?>">
                </div>
            </div>

            <label>Keyword size <span id="s1v" style="color:#64748b;font-weight:400"></span></label>
            <div class="rng"><input type="range" id="s1" min="20" max="90" value="<?= (int)$ls('s1',44) ?>"><output id="s1o"></output></div>

            <label>City size <span id="s2v" style="color:#64748b;font-weight:400"></span></label>
            <div class="rng"><input type="range" id="s2" min="16" max="80" value="<?= (int)$ls('s2',40) ?>"><output id="s2o"></output></div>

            <label>Dark fade height <span style="color:#64748b;font-weight:400">(readability)</span></label>
            <div class="rng"><input type="range" id="scrim" min="0" max="600" value="<?= (int)$ls('scrim',300) ?>"><output id="scrimo"></output></div>

            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
                <button type="button" id="lockbtn" style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:9px 16px;font-size:.88rem;font-weight:600;cursor:pointer;">🔒 Lock this style into the build</button>
                <div id="lockmsg" class="note" style="margin-top:8px;"></div>
                <?php if ($lockedStyle): ?><div class="note" style="margin-top:4px;color:#065f46;">A locked style is active — the build uses it. Adjust above and re-lock to change.</div><?php endif; ?>
            </div>
        </div>

        <div>
            <div class="preview">
                <img id="out" alt="preview" src="">
                <div id="err" style="display:none;color:#fca5a5;font-family:monospace;font-size:.78rem;text-align:left;white-space:pre-wrap;padding:12px;line-height:1.5"></div>
            </div>
            <p class="note">
                Same photo, different city → a genuinely different image file per site (defeats duplicate-image detection).
                Both lines come from data the system already stores: the page's <code>primary_keyword</code> and the site's <code>city</code>/<code>SS</code>.
                Nudge the position/size/color to taste — when you're happy, tell me and I'll lock this style into the multisite build.
            </p>
        </div>
    </div>

    <div class="card" style="margin-top:20px;max-width:720px;">
        <h3>Upload a test image</h3>
        <p class="note" style="margin-top:0;margin-bottom:12px;">Preview the overlay on your own photo (e.g. a real hero, or a screenshot). It's saved only as a temporary scratch file for testing and auto-deleted after a day — it never goes into any real site.</p>
        <input type="file" id="upl" accept="image/jpeg,image/png,image/webp,image/gif">
        <span id="uplmsg" class="note" style="margin-left:10px;"></span>
    </div>
</main>

<script>
var LAB_CSRF = <?= json_encode($csrf) ?>;
</script>
<script>
(function () {
    var ids = ['src','line1','line2','line3','pos','c2','s1','s2','scrim'];
    var el = {}; ids.forEach(function (i) { el[i] = document.getElementById(i); });
    var out = document.getElementById('out');
    var err = document.getElementById('err');
    function sync() {
        document.getElementById('s1o').textContent = el.s1.value;
        document.getElementById('s2o').textContent = el.s2.value;
        document.getElementById('scrimo').textContent = el.scrim.value;
    }
    function params() {
        return new URLSearchParams({
            src: el.src.value, line1: el.line1.value, line2: el.line2.value, line3: el.line3.value,
            pos: el.pos.value, c2: el.c2.value, s1: el.s1.value, s2: el.s2.value, scrim: el.scrim.value
        });
    }
    // If the image fails, fetch the same request with debug=1 and show the reason inline.
    out.onload = function () { err.style.display = 'none'; out.style.display = ''; };
    out.onerror = function () {
        var q = params(); q.set('debug', '1');
        fetch('hero_overlay.php?' + q.toString())
            .then(function (r) { return r.text(); })
            .then(function (txt) { out.style.display = 'none'; err.style.display = 'block'; err.textContent = 'Preview failed:\n\n' + txt; })
            .catch(function () { out.style.display = 'none'; err.style.display = 'block'; err.textContent = 'Preview failed and the diagnostic request also failed (network/auth?).'; });
    };
    var t = null;
    function render() {
        sync();
        var q = params(); q.set('_', Date.now());
        out.src = 'hero_overlay.php?' + q.toString();
    }
    function schedule() { clearTimeout(t); t = setTimeout(render, 180); }
    ids.forEach(function (i) { el[i].addEventListener('input', schedule); el[i].addEventListener('change', schedule); });

    // Upload a scratch image, then select it as the source and re-render.
    var upl = document.getElementById('upl'), uplmsg = document.getElementById('uplmsg');
    upl.addEventListener('change', function () {
        if (!upl.files || !upl.files[0]) return;
        var fd = new FormData();
        fd.append('csrf_token', LAB_CSRF);
        fd.append('image', upl.files[0]);
        uplmsg.textContent = 'Uploading…';
        fetch('hero_upload.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { uplmsg.textContent = '✗ ' + d.error; return; }
                var opt = document.createElement('option');
                opt.value = d.src; opt.textContent = '⬆ ' + d.name + ' (' + d.w + '×' + d.h + ')';
                var grp = document.createElement('optgroup'); grp.label = 'Uploaded'; grp.appendChild(opt);
                el.src.insertBefore(grp, el.src.firstChild);
                el.src.value = d.src;
                uplmsg.textContent = '✓ uploaded';
                render();
            })
            .catch(function () { uplmsg.textContent = '✗ upload failed'; });
    });

    // Lock the current style into the build (store control values + reference dims).
    var lockBtn = document.getElementById('lockbtn'), lockMsg = document.getElementById('lockmsg');
    lockBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf_token', LAB_CSRF);
        fd.append('pos', el.pos.value);
        fd.append('c1', '#ffffff');
        fd.append('c2', el.c2.value);
        fd.append('s1', el.s1.value);
        fd.append('s2', el.s2.value);
        fd.append('scrim', el.scrim.value);
        fd.append('ref_w', out.naturalWidth || 715);
        fd.append('ref_h', out.naturalHeight || 600);
        lockMsg.textContent = 'Saving…';
        fetch('hero_style_save.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                lockMsg.textContent = d.error ? ('✗ ' + d.error) : '✓ Locked — every build now uses this style (sizes scale to each hero).';
                lockMsg.style.color = d.error ? '#991b1b' : '#065f46';
            })
            .catch(function () { lockMsg.textContent = '✗ save failed'; lockMsg.style.color = '#991b1b'; });
    });

    render();
})();
</script>
</body>
</html>
