<?php
/**
 * Test Lab — a permanent playground for previewing generator features before
 * they're wired into the build. Add more tests by adding a panel + (optionally)
 * a backend endpoint.
 * Auth required. Read-only: nothing here writes into any site.
 *
 * The hero text-overlay preview that used to live here has moved to the
 * Gen-Image tab (admin/tabs/genimage.php) in the core admin panel, alongside
 * the photo-variation ranges it's paired with — a real, permanent setting
 * belongs in the panel that owns the site, not this experiments page.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/convo_uploads.php';   // accepted upload types, shared with convo_upload.php
require_once __DIR__ . '/../includes/layout_variations.php';  // ms_variant() — the REAL assigner
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$csrf = $_SESSION['csrf_token'] ?? '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

/**
 * ── Batch variance mockup ────────────────────────────────────────────────────────────────
 * A LOOK-ONLY proposal of the phase-4 controls, for agreeing scope before anything is built.
 * Writes nothing, changes nothing, is not wired to any batch. The option lists below are
 * illustrative placeholders EXCEPT the assignment itself: the "what each domain gets" preview
 * calls the real ms_variant() with the real salts, so the picks shown are the picks you'd get.
 */
$vmAxes = [
    'theme' => [
        'label' => 'Visual identity', 'status' => 'existing', 'salt' => 'theme',
        'blurb' => 'Colour/font preset + logo. Already runs today.',
        'options' => ['Navy + Orange', 'Slate + Red-Orange', 'Teal + Crimson', 'Forest + Orange',
                      'Charcoal + Amber', 'Navy + Hi-Vis Yellow'],
        'column' => 'theme_preset',
    ],
    'layout' => [
        'label' => 'Section order', 'status' => 'built, switched off', 'salt' => 'layout',
        'blurb' => 'Reorders the sections on each page. Hero stays first, so the H1 never moves.',
        'options' => ['Natural order', 'Trust bar before steps', 'FAQ above services', 'Steps last'],
        'column' => 'section_order',
    ],
    'classvocab' => [
        'label' => 'Class vocabulary', 'status' => 'NOT BUILT', 'salt' => 'classvocab',
        'blurb' => 'Same layout and same CSS rules under different class names, so two sites don\'t share a markup signature.',
        'options' => ['Semantic (.hero-wrap / .service-card)', 'Utility (.b-hero / .c-tile)',
                      'BEM (.site__hero / .site__card)'],
        'column' => 'class_vocab',
    ],
    'schemashape' => [
        'label' => 'Schema shape', 'status' => 'NOT BUILT', 'salt' => 'schemashape',
        'blurb' => 'Same facts in the JSON-LD, different field order and boilerplate phrasing.',
        'options' => ['serviceType → provider → description', 'description → name → areaServed',
                      'name → areaServed → serviceType'],
        'column' => 'schema_shape',
    ],
];
// Sample domains just to show the spread; any real batch's rows would appear here instead.
$vmDomains = ['boylerestoration.com', 'lufkinwaterpros.com', 'pineywoodsrestore.com', 'angelinadryout.com'];
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
    <a href="city_map_demo.php" style="color:#7dd3fc;font-weight:700;">&#128506; City Map demo</a>
    <a href="#variance-mockup" style="color:#c4b5fd;font-weight:700;">🧩 Batch variance (mock-up)</a>
    <a href="dirnet-data.php" style="color:#fdba74;font-weight:700;">📊 Directory Network data</a>
    <a href="dirnet-answers.php" style="color:#fdba74;font-weight:700;">❓ Directory Network answers</a>
    <a href="dirnet-sheets.php" style="color:#fdba74;font-weight:700;">📄 Directory Network sheets</a>
    <a href="#share-claude" style="color:#93c5fd;font-weight:700;">📎 Share with Claude</a>
    <a href="#downloads-scott" style="color:#fcd34d;font-weight:700;">⬇ Downloads for Scott</a>
    <a href="#keyword-lists" style="color:#86efac;font-weight:700;">🔑 Keyword lists</a>
    <a href="#water-icons" style="color:#7dd3fc;font-weight:700;">💧 Water icons</a>
    <a class="back" href="#preset-check" style="color:#fd783b;">↓ Theme Preset check</a>
    <a class="back" href="#logo-gen" style="color:#fd783b;">↓ Logo generator</a>
    <a class="back" href="#bug-icons" style="color:#fd783b;">↓ Bug icons</a>
    <a class="back" href="docs.php">← Documentation</a>
    <a class="back" href="index.php">← Admin</a>
</div>
<main>
    <section id="variance-mockup" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Batch variance <span class="pill" style="background:#fef3c7;color:#92400e;border-color:#fde68a;">MOCK-UP · nothing here is wired up</span></h1>
        <p class="sub">A proposal for the phase-4 controls, so we can agree the scope before any of it is built. <strong>Every tick below is inert</strong> — this page writes nothing and is not connected to a batch. The one honest part is the assignment table at the bottom: it runs the real <code>ms_variant()</code> hash, so those picks are the picks a domain would actually get.</p>

        <div style="display:grid;grid-template-columns:minmax(340px,1fr) minmax(340px,1fr);gap:22px;align-items:start;">

            <!-- 1 · the run card as it would look -->
            <div class="card">
                <h3 style="margin-top:0;">4. Generate sites <span style="font-weight:400;color:#94a3b8;font-size:.8rem;">— as it would look</span></h3>
                <p class="note" style="margin-top:0;">Untick a step to skip it for this run. Three new steps, in the list that already exists.</p>
                <div style="margin-top:12px;display:flex;flex-direction:column;gap:7px;">
                    <?php
                    $vmSteps = [
                        ['Landing pages', false], ['Visual identity', false],
                        ['Section order', true], ['Class vocabulary', true], ['Schema shape', true],
                        ['AI content', false], ['Images', false], ['Site tags (analytics, Search Console)', false],
                    ];
                    foreach ($vmSteps as [$label, $isNew]): ?>
                        <label class="hint" style="display:flex;align-items:center;gap:8px;<?= $isNew ? 'font-weight:700;color:#1e3a5f;' : '' ?>">
                            <input type="checkbox" checked disabled>
                            <?= $h($label) ?>
                            <?php if ($isNew): ?><span style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:999px;padding:1px 8px;font-size:.68rem;font-weight:700;">NEW</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="note" style="margin-top:14px;">Below this, unchanged: <em>Limit</em>, <em>Only this domain</em>, <em>Force</em>, and the <strong>Generate sites</strong> button.</p>
                <div style="margin-top:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                    <div style="font-weight:700;color:#1e3a5f;font-size:.82rem;">Not in this round</div>
                    <div class="note">No new page architectures · no change to the 26 landing pages · no approval workflow · no second renderer. Same pages, same H1s, same keywords, same titles and metas.</div>
                </div>
            </div>

            <!-- 2 · the pools -->
            <div class="card">
                <h3 style="margin-top:0;">Variance pools <span style="font-weight:400;color:#94a3b8;font-size:.8rem;">— set once on the master</span></h3>
                <p class="note" style="margin-top:0;">Which options are in rotation, exactly like Theme presets work today. Drop a folder in, it appears here — no code change.</p>
                <?php foreach ($vmAxes as $key => $ax): ?>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <strong style="color:#1e3a5f;font-size:.9rem;"><?= $h($ax['label']) ?></strong>
                            <?php
                            $st = $ax['status'];
                            $stStyle = $st === 'existing' ? 'background:#ecfdf5;color:#065f46;border-color:#a7f3d0;'
                                     : ($st === 'NOT BUILT' ? 'background:#fef2f2;color:#b91c1c;border-color:#fecaca;'
                                     : 'background:#fffbeb;color:#92400e;border-color:#fde68a;');
                            ?>
                            <span style="border:1px solid;border-radius:999px;padding:1px 9px;font-size:.68rem;font-weight:700;<?= $stStyle ?>"><?= $h($st) ?></span>
                        </div>
                        <div class="note" style="margin:3px 0 6px;"><?= $h($ax['blurb']) ?></div>
                        <?php foreach ($ax['options'] as $opt): ?>
                            <label class="hint" style="display:flex;align-items:center;gap:7px;"><input type="checkbox" checked disabled> <?= $h($opt) ?></label>
                        <?php endforeach; ?>
                        <div class="note" style="margin-top:5px;">Per-row override column: <code><?= $h($ax['column']) ?></code></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3 · the honest part -->
        <div class="card" style="margin-top:22px;">
            <h3 style="margin-top:0;">What each domain would actually get <span class="pill">real <code>ms_variant()</code> hash, not mocked</span></h3>
            <p class="note" style="margin-top:0;">Assignment is automatic and deterministic per domain — the same domain picks the same option forever, so a rebuild never churns your SEO signals. You choose <em>whether an axis runs</em> and <em>which options are in the pool</em>, not which option a given site gets. Sample domains shown; a real batch would list its own rows.</p>
            <table style="width:100%;border-collapse:collapse;font-size:.84rem;margin-top:10px;">
                <thead><tr>
                    <th style="text-align:left;padding:6px;">Domain</th>
                    <?php foreach ($vmAxes as $ax): ?><th style="text-align:left;padding:6px;"><?= $h($ax['label']) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($vmDomains as $dom): ?>
                    <tr style="border-top:1px solid #e2e8f0;">
                        <td style="padding:6px;font-weight:600;"><?= $h($dom) ?></td>
                        <?php foreach ($vmAxes as $ax):
                            $opts = $ax['options'];
                            $pick = $opts[ms_variant($dom, count($opts), $ax['salt'])] ?? $opts[0]; ?>
                            <td style="padding:6px;"><?= $h($pick) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="note" style="margin-top:10px;">Every axis is salted separately, so two domains that happen to share a theme still differ everywhere else — the rotations don't move in lockstep.</p>
        </div>

        <div class="card" style="margin-top:22px;border-color:#c7d2fe;background:#f5f3ff;">
            <h3 style="margin-top:0;">Ships alongside — the SEO guard</h3>
            <p class="note" style="margin-top:0;">A before/after check that renders each page with and without the variance and compares <strong>the page set, every H1, every title, every meta description, and the schema types</strong>. If any of those move, the variance is wrong and the run fails. That makes "the SEO held" a test result rather than a claim.</p>
        </div>
    </section>

    <section id="water-icons" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Water icons <span class="pill">Recovery Wellspring · pick one</span></h1>
        <p class="sub">Simple water-themed SVG options for the logo/favicon. Tell me the letter you want and I'll set it as the site logo + favicon (currently live: <strong>B · Waves</strong>).</p>
        <div style="display:flex;flex-wrap:wrap;gap:20px;">
            <?php
            $__wicons = [
              'A · Drop' => '<circle cx="32" cy="32" r="32" fill="#1e4e8c"/><path d="M32 13 C 32 13 45 31 45 40 a13 13 0 1 1 -26 0 C 19 31 32 13 32 13 Z" fill="#fff"/><path d="M26 40 a6 6 0 0 1 4 -9" fill="none" stroke="#1e4e8c" stroke-width="2.4" stroke-linecap="round"/>',
              'B · Waves (live)' => '<circle cx="32" cy="32" r="32" fill="#1e4e8c"/><g fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round"><path d="M14 26 q6 -6 12 0 t12 0 t12 0"/><path d="M14 34 q6 -6 12 0 t12 0 t12 0"/><path d="M14 42 q6 -6 12 0 t12 0 t12 0"/></g>',
              'C · Drop + ripple' => '<circle cx="32" cy="32" r="32" fill="#1e4e8c"/><path d="M32 12 C 32 12 43 27 43 35 a11 11 0 1 1 -22 0 C 21 27 32 12 32 12 Z" fill="none" stroke="#fff" stroke-width="3" stroke-linejoin="round"/><g fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round"><path d="M18 52 q14 6 28 0"/><path d="M24 58 q8 4 16 0"/></g>',
              'D · Drop + wave' => '<circle cx="32" cy="32" r="32" fill="#1e4e8c"/><path d="M32 12 C 32 12 44 29 44 38 a12 12 0 1 1 -24 0 C 20 29 32 12 32 12 Z" fill="#fff"/><path d="M21 40 q5 -5 11 0 t11 0 v9 a12 12 0 0 1 -22 0 Z" fill="#38bdf8"/>',
              'E · Waterfall' => '<circle cx="32" cy="32" r="32" fill="#1e4e8c"/><g fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M32 13 C 28 11 22 12 19 18"/><path d="M32 13 C 36 11 42 12 45 18"/><path d="M21 20 C 20 30 19 41 24 49"/><path d="M27 17 C 27 30 27 43 30 50"/><path d="M32 16 C 32 30 33 44 34 50"/><path d="M37 17 C 37 30 37 43 38 50"/><path d="M43 20 C 44 30 45 41 40 49"/><ellipse cx="32" cy="49" rx="21" ry="7"/><ellipse cx="32" cy="49.5" rx="13" ry="4.2"/></g>',
            ];
            foreach ($__wicons as $label => $body): ?>
              <div style="text-align:center;">
                <svg viewBox="0 0 64 64" width="96" height="96" style="border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.12);"><?= $body ?></svg>
                <div style="font-weight:700;color:#1e3a5f;font-size:.82rem;margin-top:8px;"><?= $h($label) ?></div>
              </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="share-claude" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Share with Claude <span class="pill">upload · conversations</span></h1>
        <p class="sub">Drop an image <strong>or a file</strong> here (a screenshot, a design, a photo, a PDF, a spreadsheet, notes) to put it on the server, then <strong>copy the path it gives you and paste it into the chat</strong> — Claude reads files off the VPS, not your Mac. You can also just <strong>paste a screenshot</strong> (Cmd-V) anywhere on this page. Kept for 7 days.</p>

        <div id="cv-drop" style="border:2px dashed #94a3b8;border-radius:12px;background:#fff;padding:34px 20px;text-align:center;cursor:pointer;transition:.15s;max-width:720px;">
            <div style="font-size:2rem;">📎</div>
            <div style="font-weight:700;color:#1e3a5f;margin-top:6px;">Drag &amp; drop an image or file here</div>
            <div class="note" style="margin-top:4px;">or paste a screenshot with Cmd-V · <?= htmlspecialchars(convo_accept_note(), ENT_QUOTES, 'UTF-8') ?></div>
            <button type="button" id="cv-select" style="margin-top:14px;background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:9px 20px;font-weight:600;font-size:.9rem;cursor:pointer;">Select file…</button>
            <!-- accept="" comes from the same list the server enforces. Hard-coding a
                 second copy here is what left the picker greying out a .jsx after the
                 server had been taught to accept one. -->
            <input type="file" id="cv-file" accept="<?= htmlspecialchars(convo_accept_attr(), ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
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

    <section id="downloads-scott" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Downloads for Scott <span class="pill">read · copy · download</span></h1>
        <p class="sub">The other direction from the panel above: files Claude has left <em>for you</em>. Anything dropped into <code>uploads/downloads/</code> shows up here automatically, newest first. Text files are shown in full so you can read them without downloading — use <strong>Copy</strong> to take the text, or <strong>Download</strong> to save the file.</p>
        <?php
        $dlFiles = [];
        foreach (glob(BASE_DIR . '/uploads/downloads/*') ?: [] as $p) { if (is_file($p)) $dlFiles[$p] = filemtime($p); }
        arsort($dlFiles);                                  // newest first
        $dlFiles = array_keys($dlFiles);
        // Shown inline rather than only offered as a download — most of what lands
        // here is a short set of instructions, and making it readable in the page
        // saves a round trip through the Downloads folder.
        $dlText = ['txt','md','markdown','csv','tsv','json','xml','yaml','yml','log','sh','conf','pub'];
        // Images shown inline too. A screenshot offered only as a download is a
        // file you have to save before you can find out whether you wanted it.
        $dlImg  = ['png','jpg','jpeg','gif','webp','svg'];
        if (!$dlFiles): ?>
        <p class="note">Nothing here yet. Files Claude leaves in <code>uploads/downloads/</code> will appear in this spot.</p>
        <?php else: foreach ($dlFiles as $df):
            $dn   = basename($df);
            $dext = strtolower(pathinfo($dn, PATHINFO_EXTENSION));
            $dIsText = in_array($dext, $dlText, true);
            $dIsImg  = in_array($dext, $dlImg,  true);
            $dSize = filesize($df);
            $dHuman = $dSize < 1024 ? $dSize . ' B' : ($dSize < 1048576
                ? round($dSize / 1024, 1) . ' KB' : round($dSize / 1048576, 1) . ' MB');
        ?>
        <div style="max-width:820px;margin-bottom:22px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                <span style="font-size:1.2rem;"><?= $dIsText ? '📄' : ($dIsImg ? '🖼' : '📦') ?></span>
                <strong style="color:#1e3a5f;font-family:monospace;font-size:.88rem;"><?= $h($dn) ?></strong>
                <span class="note" style="margin-left:auto;"><?= $h($dHuman) ?> · <?= $h(date('j M Y, H:i', filemtime($df))) ?></span>
                <?php if ($dIsText): ?>
                <button type="button" class="dl-copy" style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:6px 14px;font-weight:600;cursor:pointer;">Copy</button>
                <?php endif; ?>
                <a href="/uploads/downloads/<?= rawurlencode($dn) ?>" target="_blank" style="background:#0ea5e9;color:#fff;border-radius:6px;padding:6px 14px;font-weight:600;text-decoration:none;">Open</a>
                <a href="/uploads/downloads/<?= rawurlencode($dn) ?>" download style="background:#16a34a;color:#fff;border-radius:6px;padding:6px 14px;font-weight:600;text-decoration:none;">Download</a>
            </div>
            <?php if ($dIsImg): ?>
            <a href="/uploads/downloads/<?= rawurlencode($dn) ?>" target="_blank">
                <img src="/uploads/downloads/<?= rawurlencode($dn) ?>" alt="<?= $h($dn) ?>"
                     style="display:block;max-width:100%;border:1px solid #eef2f7;border-radius:8px;background:#f8fafc;">
            </a>
            <?php elseif ($dIsText): ?>
            <pre class="dl-body" style="white-space:pre-wrap;font-family:monospace;font-size:.78rem;line-height:1.55;color:#334155;background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:14px;max-height:520px;overflow:auto;margin:0;"><?= $h((string) file_get_contents($df)) ?></pre>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
        <script>
        document.querySelectorAll('.dl-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                // The <pre> is a sibling of the header row, not of the button itself.
                var pre = btn.closest('div').parentElement.querySelector('.dl-body');
                if (!pre) return;
                navigator.clipboard.writeText(pre.textContent).then(function () {
                    var t = btn.textContent; btn.textContent = '✓ Copied';
                    setTimeout(function () { btn.textContent = t; }, 1400);
                });
            });
        });
        </script>
    </section>

    <section id="keyword-lists" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Keyword lists <span class="pill">niche · research</span></h1>
        <p class="sub">Finalized primary-page keyword lists per niche, saved under <code>uploads/</code>. Each becomes the source for that site's <code>keyword_map.json</code>. Click <strong>Copy</strong> for the plain text or <strong>Download</strong> for the <code>.txt</code>.</p>
        <?php
        $kwFiles = glob(BASE_DIR . '/uploads/*keywords*.txt') ?: [];
        sort($kwFiles);
        if (!$kwFiles): ?>
        <p class="note">No keyword lists saved yet. Drop a <code>*-keywords.txt</code> into <code>uploads/</code> and it appears here.</p>
        <?php else: foreach ($kwFiles as $kf): $kn = basename($kf); $kbody = (string)file_get_contents($kf); ?>
        <div style="max-width:720px;margin-bottom:22px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <strong style="color:#1e3a5f;font-family:monospace;font-size:.85rem;"><?= $h($kn) ?></strong>
                <span class="note" style="margin-left:auto;"><?= substr_count($kbody, "\n") ?> lines</span>
                <button type="button" class="kw-copy" style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:6px 14px;font-weight:600;cursor:pointer;">Copy</button>
                <a href="/uploads/<?= $h($kn) ?>" download style="background:#16a34a;color:#fff;border-radius:6px;padding:6px 14px;font-weight:600;text-decoration:none;">Download</a>
            </div>
            <pre class="kw-body" style="white-space:pre-wrap;font-family:monospace;font-size:.8rem;color:#334155;background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:14px;max-height:420px;overflow:auto;margin:0;"><?= $h($kbody) ?></pre>
        </div>
        <?php endforeach; endif; ?>
        <script>
        document.querySelectorAll('.kw-copy').forEach(function(btn){
            btn.addEventListener('click', function(){
                var pre = btn.closest('div').parentElement.querySelector('.kw-body');
                navigator.clipboard.writeText(pre.textContent).then(function(){
                    var t = btn.textContent; btn.textContent = '✓ Copied'; setTimeout(function(){ btn.textContent = t; }, 1400);
                });
            });
        });
        </script>
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

    <section id="icon-trace-prototype" style="margin-bottom:40px;padding-bottom:32px;border-bottom:2px solid #e5e7eb;">
        <h1>Icon fill + traced background <span class="pill">✅ built — per-icon on the Brand Icons card</span></h1>
        <p class="sub">Each icon in the <a href="index.php?tab=genvisual#brand-icons">Brand Icons</a> library now has its own Fill %, Background (Box / Traced outline), Corner %, and Trace % — see <code>ms_render_bug_tile()</code> in <code>includes/multisite/visual.php</code>, saved per-icon to <code>multisite/icon_styles.json</code> via <code>admin/icon_styles_save.php</code>.</p>
        <h3 style="margin:16px 0 8px;">First pass — plain dilate (superseded)</h3>
        <a href="_labshots/icon_trace_prototype.png" target="_blank"><img src="_labshots/icon_trace_prototype.png" alt="old square-tile vs new traced-outline comparison" style="max-width:480px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:8px;">Worked on solid-silhouette icons (pest's ant, top row) but broke down on thin line-art icons (water's dehumidifier, bottom row) — small internal details (the circle, the diamond) got swallowed by the dilation into one blob instead of a crisp trace.</p>
        <h3 style="margin:20px 0 8px;">Fixed — Close (bridge small breaks) before Dilate (trace width)</h3>
        <a href="_labshots/icon_trace_fixed.png" target="_blank"><img src="_labshots/icon_trace_fixed.png" alt="fixed traced outline, both icon styles" style="max-width:480px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;"></a>
        <p class="note" style="margin-top:8px;">A morphological <strong>Close</strong> pass first bridges small internal gaps/breaks (a stroke that doesn't quite close, a separate small detail) so they read as their own clean traced shape — the ant is unaffected, the dehumidifier's circle and diamond now trace cleanly instead of blobbing into the main outline.</p>
    </section>

</main>

<script>
var LAB_CSRF = <?= json_encode($csrf) ?>;
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
