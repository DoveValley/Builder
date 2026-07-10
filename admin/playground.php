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
// "Share with Claude" uploads (newest first) for the gallery.
$convoDir = BASE_DIR . '/uploads/convo';
$convoFiles = [];
foreach (glob($convoDir . '/*') ?: [] as $p) { if (is_file($p)) $convoFiles[$p] = filemtime($p); }
arsort($convoFiles);
$convoFiles = array_keys($convoFiles);
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
    <a href="#share-claude" style="color:#93c5fd;font-weight:700;">📎 Share with Claude</a>
    <button type="button" class="active">Hero text overlay</button>
    <a class="back" href="#preset-check" style="color:#fd783b;">↓ Theme Preset check</a>
    <a class="back" href="#logo-gen" style="color:#fd783b;">↓ Logo generator</a>
    <a class="back" href="#bug-icons" style="color:#fd783b;">↓ Bug icons</a>
    <a class="back" href="docs.php">← Documentation</a>
    <a class="back" href="index.php">← Admin</a>
</div>
<main>
    <section id="share-claude" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Share with Claude <span class="pill">upload · conversations</span></h1>
        <p class="sub">Drop an image <strong>or a file</strong> here (a screenshot, a design, a photo, a PDF, a spreadsheet, notes) to put it on the server, then <strong>copy the path it gives you and paste it into the chat</strong> — Claude reads files off the VPS, not your Mac. You can also just <strong>paste a screenshot</strong> (Cmd-V) anywhere on this page. Kept for 7 days.</p>

        <div id="cv-drop" style="border:2px dashed #94a3b8;border-radius:12px;background:#fff;padding:34px 20px;text-align:center;cursor:pointer;transition:.15s;max-width:720px;">
            <div style="font-size:2rem;">📎</div>
            <div style="font-weight:700;color:#1e3a5f;margin-top:6px;">Drag &amp; drop an image or file here</div>
            <div class="note" style="margin-top:4px;">or paste a screenshot with Cmd-V · images · PDF · text/markdown/CSV/JSON · Office docs · zip · max 20 MB</div>
            <button type="button" id="cv-select" style="margin-top:14px;background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:9px 20px;font-weight:600;font-size:.9rem;cursor:pointer;">Select file…</button>
            <input type="file" id="cv-file" accept="image/*,.pdf,.txt,.md,.markdown,.csv,.tsv,.json,.xml,.yaml,.yml,.log,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.rtf,.odt,.ods,.odp,.zip" style="display:none;">
        </div>

        <div id="cv-result" style="display:none;max-width:720px;margin-top:16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
            <div style="display:flex;gap:16px;align-items:flex-start;">
                <img id="cv-thumb" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;flex-shrink:0;background:#f1f5f9;">
                <div style="flex:1;min-width:0;">
                    <div id="cv-status" style="font-weight:700;color:#065f46;">✓ Uploaded — paste this path to Claude:</div>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <input type="text" id="cv-path" readonly onclick="this.select()" style="flex:1;font-family:monospace;font-size:.8rem;">
                        <button type="button" id="cv-copy" style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:600;cursor:pointer;">Copy</button>
                    </div>
                    <div id="cv-meta" class="note" style="margin-top:6px;"></div>
                </div>
            </div>
        </div>

        <?php if ($convoFiles): ?>
        <h3 style="margin:24px 0 10px;color:#1e3a5f;">Recent uploads</h3>
        <div style="display:flex;flex-wrap:wrap;gap:14px;">
            <?php foreach (array_slice($convoFiles, 0, 24) as $p): $n = basename($p);
                $pext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                $pisImg = in_array($pext, ['jpg','jpeg','png','webp','gif'], true); ?>
            <div style="width:150px;">
                <a href="/uploads/convo/<?= $h($n) ?>" target="_blank"><?php if ($pisImg): ?><img src="/uploads/convo/<?= $h($n) ?>" alt="" style="width:150px;height:110px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;display:block;"><?php else: ?><span style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:150px;height:110px;border-radius:8px;border:1px solid #e2e8f0;background:#f1f5f9;color:#475569;"><span style="font-size:1.6rem;">📄</span><span style="font-size:.7rem;font-weight:700;margin-top:4px;"><?= $h(strtoupper($pext)) ?></span></span><?php endif; ?></a>
                <input type="text" readonly onclick="this.select()" value="<?= $h($p) ?>" style="width:150px;font-family:monospace;font-size:.62rem;margin-top:4px;padding:3px 5px;border:1px solid #e2e8f0;border-radius:4px;">
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section id="preset-check" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Theme Preset check <span class="pill">theme · before / after</span></h1>
        <p class="sub">Left = pest master (orange/indigo — the <strong>Classic</strong> preset). Right = the same page, <strong>full height including the footer</strong>, with the <strong>Bold</strong> Theme Preset (charcoal&nbsp;<code>#1f2937</code> + red&nbsp;<code>#dc2626</code>) merged into <code>data['theme']</code> — the exact swap a per-site Theme Preset would do. Scroll to the bottom: the <strong>3-column footer</strong> flips indigo&nbsp;→&nbsp;charcoal and the sticky bar flips orange&nbsp;→&nbsp;red. These are two Theme Presets; both read as legitimate brand looks.</p>
        <p class="sub" style="background:#ecfdf5;border-left:3px solid #10b981;padding:10px 14px;">
            <strong>Result — header &amp; footer now follow.</strong> Everything tracks the theme: splits, tabs, hero-grid cells, badges, CTA icons, FAQ arrows, <strong>the top nav bar</strong>, the closing CTA, and the <strong>bottom “24/7 Support Line” sticky bar</strong> all recolor red in the Bold variation. The nav bar + sticky bars were the one holdout — driven by <code>header.nav_bg</code>, a raw hex outside the theme. Fixed by making <code>nav_bg</code> accept a mode keyword and defaulting it to <code>accent</code> (<code>site-template.php:208</code>); pest’s <code>header.nav_bg</code> is now <code>"accent"</code> instead of a pinned <code>#fd783b</code>. <em>Still to do:</em> the Theme Preset step should set <code>nav_bg</code> per site, and <code>data.php:40</code>’s <code>#fd783b</code> default should change so it stops leaking onto other sites.
        </p>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
            <div style="flex:1;min-width:320px;">
                <h3 style="margin:0 0 8px;">Before — Classic preset (orange / indigo)</h3>
                <a href="_labshots/style_before.jpg" target="_blank"><img src="_labshots/style_before.jpg" alt="pest master, current theme" style="width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
            </div>
            <div style="flex:1;min-width:320px;">
                <h3 style="margin:0 0 8px;">After — Bold preset (charcoal / red)</h3>
                <a href="_labshots/style_after.jpg" target="_blank"><img src="_labshots/style_after.jpg" alt="pest master, Bold Theme Preset applied" style="width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
            </div>
        </div>
        <p class="note" style="margin-top:10px;">Click either image to open full size. Read-only demo — the master theme was swapped, screenshotted, and restored; nothing was committed.</p>
    </section>

    <section id="logo-gen" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Logo generator <span class="pill">visual identity · e2e</span></h1>
        <p class="sub">Each generated site gets a full <strong>logo built from its business name</strong> — a <strong>bug mark</strong> (accent silhouette on a dark tile) left of a <strong>two-tone wordmark</strong> (first word in the accent color, the rest in the dark color), all in its Theme Preset's palette. Plus a matching <strong>favicon</strong>. This replaces the master's baked-in “KATY PEST PROS” logo (an identity leak).</p>
        <p class="sub" style="background:#ecfdf5;border-left:3px solid #10b981;padding:10px 14px;">
            <strong>End-to-end verified.</strong> Real <code>build_one</code> runs: logo + favicon generated and referenced in the built HTML, Theme Preset applied, and the master's “Katy Pest Pros” logo + text <strong>gone</strong> (0 references). Bug + first word = accent; second line = the dark brand color; favicon = the bug tile at 128px.
        </p>
        <h3 style="margin:18px 0 8px;">All four presets — full logo</h3>
        <a href="_labshots/logo_full.png" target="_blank"><img src="_labshots/logo_full.png" alt="four preset logos" style="max-width:560px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:6px;">Classic (cockroach) · Bold (ant) · Fresh (spider) · Trust (mosquito).</p>
        <h3 style="margin:22px 0 8px;">In the real header (build_one)</h3>
        <a href="_labshots/logo_e2e_headers.png" target="_blank"><img src="_labshots/logo_e2e_headers.png" alt="real headers" style="width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:6px;">Dallas Pest Pros (Classic) · Houston Exterminators (Fresh).</p>
        <h3 style="margin:22px 0 8px;">Favicons</h3>
        <a href="_labshots/favicons.png" target="_blank"><img src="_labshots/favicons.png" alt="favicons" style="max-width:440px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:10px;">Read-only demos from <code>build_one --no-ai --keep</code> runs.</p>
    </section>

    <section id="bug-icons" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Bug icons <span class="pill">visual identity · logo mark</span></h1>
        <p class="sub">Real bug silhouettes from <strong>Noto Emoji (Apache 2.0 — no attribution required)</strong>, one matched to each Theme Preset and recolored to its palette. Each becomes the <strong>logo icon</strong> (left of the wordmark) and the <strong>favicon</strong>.</p>
        <h3 style="margin:16px 0 8px;">Colored per preset — accent bug on the dark tile</h3>
        <a href="_labshots/preset_bugs.png" target="_blank"><img src="_labshots/preset_bugs.png" alt="per-preset bug icons" style="max-width:520px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:8px;">Classic = cockroach · Bold = ant · Fresh = spider · Trust = mosquito.</p>
        <h3 style="margin:20px 0 8px;">The source silhouettes</h3>
        <a href="_labshots/bug_candidates.png" target="_blank"><img src="_labshots/bug_candidates.png" alt="bug icon candidates" style="max-width:520px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:8px;">✅ Wired into the build — see the finished logos in the <a href="#logo-gen">Logo generator</a> panel above.</p>
    </section>

    <h1 id="hero-overlay">Hero text overlay <span class="pill">4c · preview</span></h1>
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
<script>
// "Share with Claude" — drag & drop / click / paste-screenshot upload.
(function(){
    var drop=document.getElementById('cv-drop'), file=document.getElementById('cv-file'),
        result=document.getElementById('cv-result'), thumb=document.getElementById('cv-thumb'),
        pathI=document.getElementById('cv-path'), meta=document.getElementById('cv-meta'),
        statusEl=document.getElementById('cv-status'), copyB=document.getElementById('cv-copy');
    if(!drop) return;
    function upload(f){
        if(!f) return;
        var fd=new FormData(); fd.append('csrf_token', LAB_CSRF); fd.append('image', f);
        result.style.display='block'; statusEl.textContent='Uploading…'; statusEl.style.color='#1e3a5f'; meta.textContent='';
        fetch('convo_upload.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
            drop.style.borderColor='#94a3b8'; drop.style.background='#fff';
            if(d.error){ statusEl.textContent='✗ '+d.error; statusEl.style.color='#991b1b'; pathI.value=''; return; }
            statusEl.textContent='✓ Uploaded — paste this path to Claude:'; statusEl.style.color='#065f46';
            if(d.is_image){
                thumb.style.display='';
                thumb.src=d.web+'?t='+Date.now();
                meta.textContent=d.w+'×'+d.h+' · '+Math.round(d.size/1024)+' KB';
            } else {
                thumb.style.display='none';
                meta.textContent=(d.ext?d.ext.toUpperCase()+' · ':'')+Math.round(d.size/1024)+' KB';
            }
            pathI.value=d.abs_path;
            pathI.focus(); pathI.select();
        }).catch(function(){ statusEl.textContent='✗ upload failed'; statusEl.style.color='#991b1b'; });
    }
    drop.addEventListener('click', function(){ file.click(); });
    var selBtn=document.getElementById('cv-select');
    if(selBtn) selBtn.addEventListener('click', function(ev){ ev.stopPropagation(); file.click(); });
    file.addEventListener('change', function(){ if(file.files&&file.files[0]) upload(file.files[0]); });
    ['dragenter','dragover'].forEach(function(e){ drop.addEventListener(e,function(ev){ ev.preventDefault(); drop.style.borderColor='#1e3a5f'; drop.style.background='#eff6ff'; }); });
    ['dragleave','dragend'].forEach(function(e){ drop.addEventListener(e,function(ev){ ev.preventDefault(); drop.style.borderColor='#94a3b8'; drop.style.background='#fff'; }); });
    drop.addEventListener('drop', function(ev){ ev.preventDefault(); var f=ev.dataTransfer&&ev.dataTransfer.files&&ev.dataTransfer.files[0]; if(f) upload(f); });
    copyB.addEventListener('click', function(){
        pathI.select();
        (navigator.clipboard? navigator.clipboard.writeText(pathI.value) : Promise.reject()).catch(function(){ document.execCommand('copy'); });
        copyB.textContent='Copied ✓'; setTimeout(function(){copyB.textContent='Copy';},1400);
    });
    window.addEventListener('paste', function(ev){
        var items=ev.clipboardData&&ev.clipboardData.items; if(!items) return;
        for(var i=0;i<items.length;i++){ if(items[i].type.indexOf('image')===0){ upload(items[i].getAsFile()); break; } }
    });
})();
</script>
</body>
</html>
