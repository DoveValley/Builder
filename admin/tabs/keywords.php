<?php
// Keywords tab — build the keyword map by page role. Fully manual: no auto-seed,
// no auto-fill, no AI. Save writes data/keyword_map.json, the source of truth
// that feeds the Bulk Template Generator.
// Stacked layout: each keyword is a .kw-item block with primary, slug, and
// secondary keywords each on their own full-width line (no horizontal scroll).
// $tab, $csrfToken available from index.php.

$kwFile = dirname(TEMPLATES_FILE) . '/keyword_map.json';
$kwMap  = file_exists($kwFile) ? (json_decode(file_get_contents($kwFile), true) ?: []) : [];
$services = $kwMap['services'] ?? [];
$niche    = trim($kwMap['niche'] ?? '');
$ppCounts  = $kwMap['page_pool']['counts'] ?? [];
$ppEnabled = ($kwMap['page_pool']['enabled'] ?? false) === true;

require_once __DIR__ . '/../../includes/keyword_roles.php';
require_once __DIR__ . '/../../includes/multisite/page_pool.php';
if (!is_array($ppCounts) || !$ppCounts) $ppCounts = MS_PAGE_POOL_DEFAULT_COUNTS;
$ppCounts = array_values($ppCounts);
$roleInfo = keyword_map_roles($kwMap);   // page-role derivation for the structure summary + badges

$tierOpts = [
    'high-1' => 'High', 'high-2' => 'High 2', 'high-3' => 'High 3',
    'medium-1' => 'Medium', 'medium-2' => 'Medium 2', 'medium-3' => 'Medium 3',
    'low-1' => 'Low', 'low-2' => 'Low 2', 'low-3' => 'Low 3',
];
// Priority rank for sorting (higher = sorts to top on High→Low). Untiered = 0.
$tierRank = ['high-1'=>9,'high-2'=>8,'high-3'=>7,'medium-1'=>6,'medium-2'=>5,'medium-3'=>4,'low-1'=>3,'low-2'=>2,'low-3'=>1];

// Page pool — which landing pages a domain can build (see includes/multisite/page_pool.php).
// Landing rows only; home/core have no concept of "which pages get built for a domain".
$poolOpts = ['pinned' => 'Pinned — always built', 'rotate' => 'Rotate — eligible', 'skip' => 'Skip — never built'];

// Page-role sections. Every service row belongs to exactly one.
$sectionDefs = [
    'home'    => ['label' => 'Home Page',     'hint' => 'The broad head term the homepage targets (e.g. &ldquo;[niche] {city}&rdquo;). Usually a single keyword.'],
    'core'    => ['label' => 'Core Pages',    'hint' => 'Broad category pages that group several services together. Optional — leave empty if this niche has none.'],
    'landing' => ['label' => 'Landing Pages', 'hint' => 'One page per specific service or product the business offers. Optional — leave empty if this niche has none.'],
];
$bySection = ['home' => [], 'core' => [], 'landing' => []];
foreach ($services as $s) {
    $sec = $s['section'] ?? 'landing';
    if (!isset($bySection[$sec])) $sec = 'landing';
    $bySection[$sec][] = $s;
}

$lbl = 'display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;';

// Render the saved keywords for one section, one stacked .kw-item block per keyword.
// Each block emits exactly one of every kw_* field so the POST arrays stay index-aligned.
$numStyle = 'flex:none;display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;background:#7c3aed;color:#fff;border-radius:12px;font-size:.78rem;font-weight:700;';
$renderItems = function (array $rows, string $section) use ($tierOpts, $poolOpts, $lbl, $numStyle, $roleInfo) {
    foreach ($rows as $idx => $s):
        $nm = $s['primary'] ?? ''; $sl = $s['slug'] ?? ''; $ti = $s['tier'] ?? '';
        $po = $s['pool'] ?? ms_page_pool_default_for_tier($ti);
        $secStr = implode(', ', array_map('trim', (array)($s['secondary'] ?? [])));
        $roleLab = $roleInfo['roles'][$sl]['label'] ?? '';
    ?>
        <div class="kw-item" style="border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:10px;background:#fff;">
            <div style="margin-bottom:8px;">
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <span class="kw-num" style="<?= $numStyle ?>margin-bottom:4px;"><?= $idx + 1 ?></span>
                    <div style="flex:1;min-width:0;">
                        <label style="<?= $lbl ?>">Primary keyword <?php if ($roleLab): ?><span style="<?= keyword_role_chip_style($roleLab) ?>margin-left:6px;"><?= h($roleLab) ?></span><?php endif; ?></label>
                        <input type="text" name="kw_primary[]" value="<?= h($nm) ?>" style="width:100%;">
                    </div>
                    <div style="width:120px;flex:none;">
                        <label style="<?= $lbl ?>">Priority</label>
                        <select name="kw_tier[]" style="width:100%;" title="Tier / priority"><option value="">Tier…</option><?php foreach ($tierOpts as $v=>$l): ?><option value="<?= $v ?>" <?= $ti===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
                    </div>
                    <?php if ($section === 'landing'): ?>
                    <div style="width:170px;flex:none;">
                        <label style="<?= $lbl ?>">Which sites build it</label>
                        <select name="kw_pool[]" style="width:100%;" title="Which sites build this page">
                            <?php foreach ($poolOpts as $v=>$l): ?><option value="<?= $v ?>" <?= $po===$v?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="kw_pool[]" value="">
                    <?php endif; ?>
                    <input type="hidden" name="kw_section[]" value="<?= h($section) ?>">
                    <button type="button" class="btn" style="padding:2px 8px;flex:none;margin-bottom:1px;" onclick="kwMove(this,-1)" title="Move up">&uarr;</button>
                    <button type="button" class="btn" style="padding:2px 8px;flex:none;margin-bottom:1px;" onclick="kwMove(this,1)" title="Move down">&darr;</button>
                    <button type="button" class="btn btn-danger" style="padding:2px 9px;flex:none;margin-bottom:1px;" onclick="kwDel(this)" title="Remove keyword">&times;</button>
                </div>
            </div>
            <div style="margin-bottom:8px;">
                <label style="<?= $lbl ?>">Slug base</label>
                <input type="text" name="kw_slug[]" value="<?= h($sl) ?>" style="width:100%;font-family:monospace;font-size:.85rem;">
            </div>
            <div>
                <label style="<?= $lbl ?>">Secondary keywords <span style="font-weight:400;">(comma or line separated)</span></label>
                <textarea name="kw_secondary[]" rows="2" style="width:100%;font-size:.85rem;"><?= h($secStr) ?></textarea>
            </div>
        </div>
    <?php endforeach;
};
?>
<div class="tab-content" style="<?= $tab === 'keywords' ? '' : 'display:none;' ?>">
<?php tab_header('Keywords', 'Manual keyword map, organised by page role. Type every primary and its secondary keywords — nothing is auto-filled, seeded, or AI-generated.', 'tab-keywords'); ?>

    <?php
    // Overall build process — the keyword map (this tab) is Phase 0, the source
    // of truth everything downstream is generated from. Full doc:
    // docs/landing-page-build-process-V1-20260709.md
    $procSteps = [
        ['0',  'Keyword map',            'This tab — which pages exist, each page&rsquo;s primary keyword, slug, and secondaries.', true],
        ['1',  'Master template',        'Build/audit the reusable block skeleton per archetype.', false],
        ['2',  'Bulk-generate templates','Clone the master into the noun-service pages (find/replace + dry-run).', false],
        ['3',  'Images',                 'Assign hero / intro / local images from the media library.', false],
        ['4',  'Inspection archetype',   'Inspection-intent pages, if the niche has them.', false],
        ['5',  'Category / plans',       'Broad category pages + a pricing/plans page.', false],
        ['6',  'Cross-links',            'Sync related-service cards + the services grid to the templates.', false],
        ['7',  'Generate city pages',    'Pass A (structure) &rarr; Pass B (AI content), per city.', false],
        ['8',  'Schema + grid',          'Service-area homepage LocalBusiness node; sync services grid.', false],
        ['9',  'Secondary keywords',     'Woven into the content by the AI generator (from this map).', false],
        ['10', 'Deploy',                 'Set logo / phone / deploy.json, build, FTP, verify live.', false],
    ];
    ?>
    <details class="card" style="margin-bottom:16px;" open>
        <summary style="cursor:pointer;font-weight:700;color:#120575;font-size:1.02rem;">
            Overall build process &mdash; keyword map &rarr; templates &rarr; deploy
            <span style="font-weight:400;color:#64748b;font-size:.85rem;">(you are on Phase 0)</span>
        </summary>
        <ol style="list-style:none;margin:14px 0 4px;padding:0;">
            <?php foreach ($procSteps as [$n, $title, $desc, $cur]): ?>
            <li style="display:flex;gap:12px;align-items:flex-start;padding:8px 10px;margin-bottom:6px;border-radius:6px;<?= $cur ? 'background:#f5f3ff;border:1px solid #c4b5fd;' : '' ?>">
                <span style="flex:none;display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;background:<?= $cur ? '#7c3aed' : '#e2e8f0' ?>;color:<?= $cur ? '#fff' : '#475569' ?>;border-radius:13px;font-size:.8rem;font-weight:700;"><?= h($n) ?></span>
                <div>
                    <span style="font-weight:600;color:#1f2937;"><?= h($title) ?></span>
                    <?php if ($cur): ?><span style="margin-left:6px;font-size:.72rem;font-weight:700;color:#7c3aed;">&larr; YOU ARE HERE</span><?php endif; ?>
                    <br><span class="hint" style="font-size:.82rem;"><?= $desc ?></span>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <p class="hint" style="margin:6px 0 0;">Full write-up: <code>docs/landing-page-build-process-V1-20260709.md</code></p>
    </details>

    <?php if (!empty($services)): ?>
    <details class="card" style="margin-bottom:16px;" open>
        <summary style="cursor:pointer;font-weight:700;color:#120575;font-size:1.02rem;">
            Site structure &mdash; page roles derived from slugs
            <span style="font-weight:400;color:#64748b;font-size:.85rem;">(<?= count($services) ?> pages &middot; <?= h($roleInfo['mode']) ?> mode)</span>
        </summary>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 4px;">
            <?php foreach ($roleInfo['counts'] as $lab => $n): ?>
                <span style="<?= keyword_role_chip_style($lab) ?>font-size:.8rem;padding:4px 12px;"><?= h($lab) ?>: <?= (int)$n ?></span>
            <?php endforeach; ?>
        </div>
        <?php if ($roleInfo['mode'] === 'appliance' && (!empty($roleInfo['byBrand']) || !empty($roleInfo['byType']))): ?>
        <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:10px;">
            <div>
                <div class="hint" style="font-weight:600;margin-bottom:4px;">Leaf pages per brand</div>
                <div style="font-size:.8rem;column-width:150px;">
                    <?php foreach ($roleInfo['byBrand'] as $b => $n): ?><div><?= h($b) ?> &mdash; <?= (int)$n ?></div><?php endforeach; ?>
                </div>
            </div>
            <div>
                <div class="hint" style="font-weight:600;margin-bottom:4px;">Leaf pages per appliance type</div>
                <div style="font-size:.8rem;column-width:170px;">
                    <?php foreach ($roleInfo['byType'] as $t => $n): ?><div><?= h($t) ?> &mdash; <?= (int)$n ?></div><?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <p class="hint" style="margin:10px 0 0;">
            <?php if ($roleInfo['mode'] === 'appliance'): ?>
                Precise appliance roles: <strong>Home</strong> (head term) &rarr; <strong>Type Hubs</strong> (one per appliance) + <strong>Brand Hubs</strong> (one per brand) &rarr; <strong>Leaf</strong> pages (brand &times; appliance). Each leaf links up to both its brand hub and its type hub.
            <?php else: ?>
                Generic roles: <strong>Home</strong> (section) &middot; <strong>Hub</strong> (a broader term nested inside &ge;2 other slugs) &middot; <strong>Leaf</strong> (everything else). Load an appliance-repair map to see the precise brand/type hub split.
            <?php endif; ?>
        </p>
    </details>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px;">
        <h2 style="margin-top:0;margin-bottom:8px;">How this tab works</h2>
        <p class="hint" style="margin:0 0 12px;">
            One site per city. <strong>Every row below becomes one page</strong>, targeting that keyword plus the
            city (e.g. &ldquo;<?= h($niche ?: 'your service') ?> {city}, {ST}&rdquo;). This map is the source of
            truth for the Bulk Template Generator &mdash; nothing gets built that isn&rsquo;t listed here.
        </p>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;margin-bottom:12px;">
            <div class="hint" style="font-weight:700;color:#1e3a5f;margin-bottom:6px;">What each field on a row does</div>
            <ul class="hint" style="margin:0;padding-left:18px;line-height:1.75;">
                <li><strong>Keyword (primary)</strong> &mdash; the page&rsquo;s main target. The city and state are
                    filled in per site, so you write the service only.</li>
                <li><strong>Slug</strong> &mdash; the page&rsquo;s URL.</li>
                <li><strong>Tier</strong> &mdash; your own priority judgement, <code>high-1</code> down to
                    <code>low-3</code>. It sorts this list with the <em>Sort: High &rarr; Low</em> button.
                    <strong>It does not decide what gets built</strong> &mdash; with one exception: if you never
                    touch the Pool dropdown, the tier picks a default for you
                    (<code>high-1</code> &rarr; Pinned, any <code>low-</code> &rarr; Skip, everything else
                    &rarr; Rotate). Once Pool is set, tier is just priority.</li>
                <li><strong>Secondary keywords</strong> &mdash; comma-separated variants. These get worked into the
                    page title, the H2s and the FAQ. They don&rsquo;t create pages of their own.</li>
                <li><strong>Pool</strong> (Landing Pages only) &mdash; the switch that actually decides whether the
                    page gets built. See below.</li>
            </ul>
        </div>

        <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 14px;margin-bottom:12px;">
            <div class="hint" style="font-weight:700;color:#92400e;margin-bottom:6px;">
                What decides whether a landing page gets built
            </div>
            <ul class="hint" style="margin:0 0 8px;padding-left:18px;line-height:1.75;">
                <li><strong>Pinned &mdash; always built.</strong> Every site in this niche gets this page. No exceptions.</li>
                <li><strong>Rotate &mdash; eligible.</strong> Goes into the draw. Some sites get it, some don&rsquo;t.</li>
                <li><strong>Skip &mdash; never built.</strong> Never appears on any site.</li>
            </ul>
            <p class="hint" style="margin:0 0 8px;">
                <strong>Pages per site</strong> (the three boxes in section 4) sets how many landing pages a site ends
                up with. You give three totals; <strong>each domain lands on one of them</strong>, chosen from its own
                name &mdash; so the fleet doesn&rsquo;t all have an identical page count. Pinned pages go in first,
                then the remainder is filled from the Rotate pool, picked by the domain name so it&rsquo;s stable
                rather than random.
            </p>
            <p class="hint" style="margin:0;">
                <em>Example:</em> totals of 12/14/16 with 3 Pinned and 19 Rotate &mdash; a domain that lands on 14
                builds its 3 Pinned pages plus 11 of the 19 Rotate pages. A different domain in the same niche builds
                a different 11.
            </p>
        </div>

        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px 14px;margin-bottom:12px;">
            <div class="hint" style="font-weight:700;color:#991b1b;margin-bottom:6px;">Two things that catch people out</div>
            <ul class="hint" style="margin:0;padding-left:18px;line-height:1.75;">
                <li><strong>The page choice locks in on a site&rsquo;s first build.</strong> Changing Tier, Pool or the
                    page counts here only affects domains that have <em>never been built</em>. An already-built site
                    reuses the selection saved at
                    <code>sites/{niche}/multisite/cache/{domain}.pagepool.json</code> on every rebuild, so its pages
                    never change underneath it. Delete that file if you genuinely want a built site re-drawn.</li>
                <li><strong>Saving here never switches pooling on.</strong> Pooling only runs when
                    <code>page_pool.enabled</code> is <code>true</code> in that niche&rsquo;s
                    <code>data/keyword_map.json</code> &mdash; a deliberate one-time decision, not something a routine
                    keyword edit should flip. While it&rsquo;s off, every page that isn&rsquo;t Skip is built on every
                    site.</li>
            </ul>
        </div>

        <div class="hint" style="font-weight:700;color:#1e3a5f;margin-bottom:6px;">Building the list in the first place</div>
        <ol class="hint" style="margin:0 0 14px;padding-left:20px;line-height:1.7;">
            <li><strong>Get the prompt.</strong> Download the prompt template, then fill in the <code>[BRACKETS]</code> &mdash; your niche &mdash; and attach your keyword data (a Google Keyword Planner or Ahrefs CSV export).</li>
            <li><strong>Generate the list.</strong> Paste it into an AI assistant. It clusters your keywords into pages: one <strong>Home</strong> head term, optional <strong>Core</strong> category pages, and one <strong>Landing</strong> page per service &mdash; each with a primary, secondaries, and a tier.</li>
            <li><strong>Check the format.</strong> The sample output shows exactly what a finished list looks like (any niche follows the same shape).</li>
            <li><strong>Enter it here.</strong> Type the results into the Home / Core / Landing sections below, set the Pool on each landing row, and click <strong>Save</strong>.</li>
        </ol>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a class="btn" href="/uploads/keyword-map-prompt.txt" download="keyword-map-prompt.txt">&#11015; Prompt template (.txt)</a>
            <a class="btn" href="/uploads/pest-landing-keywords.txt" download="sample-keyword-list.txt">&#11015; Sample keyword list (.txt)</a>
        </div>
    </div>

    <form action="keywords_save.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_primaries">

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:12px;">
            <button type="button" class="btn" style="background:#0f766e;" onclick="kwDownload()" title="Download the current keyword map (including unsaved edits) as a text file">&#11015; Download Niche Keyword Info</button>
            <button type="button" class="btn" style="background:#0369a1;" onclick="kwDownloadPriSec()" title="Download a plain text list of all primary + secondary keywords (including unsaved edits) — one per line, for pasting into keyword tools">&#11015; Download Pri/Sec Keywords</button>
            <button type="submit" class="btn">Save</button>
        </div>

        <!-- 1 · Niche definition -->
        <div class="card" style="margin-bottom:16px;border:2px solid #7c3aed;">
            <h2 style="margin-top:0;margin-bottom:8px;">1 &middot; Niche definition</h2>
            <div class="form-group" style="margin-bottom:8px;">
                <input type="text" name="niche" value="<?= h($niche) ?>" placeholder="e.g. pest control, roofing, HVAC" style="font-size:1.15rem;max-width:440px;">
            </div>
            <p class="hint" style="margin:0;">This site's vertical. Keywords target <code>[service] + {city}</code> (e.g. &ldquo;<?= h($niche ?: 'your service') ?> {city}&rdquo;), one site per city.</p>
        </div>

        <?php $n = 2; foreach ($sectionDefs as $key => $def): ?>
        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:4px;">
                <h2 style="margin:0;"><?= $n ?> &middot; <?= h($def['label']) ?></h2>
                <?php if ($key !== 'home'): ?>
                <button type="button" class="btn" style="flex:none;" data-dir="desc" onclick="kwSort(this,'kw-rows-<?= h($key) ?>')">Sort: High &rarr; Low</button>
                <?php endif; ?>
            </div>
            <p class="hint" style="margin:0 0 12px;"><?= $def['hint'] ?></p>
            <?php if ($key === 'landing'): ?>
            <?php /* Marker: proves the landing section was on the submitted form, so an
                     unchecked box means "off" rather than "this form never asked". */ ?>
            <input type="hidden" name="pp_present" value="1">
            <div id="pp-panel" style="background:<?= $ppEnabled ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $ppEnabled ? '#86efac' : '#fca5a5' ?>;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
                <label id="pp-lbl" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;color:<?= $ppEnabled ? '#166534' : '#991b1b' ?>;">
                    <input type="checkbox" name="pp_enabled" value="1" style="width:auto;flex:none;"
                           onclick="return ppConfirmToggle(this)" <?= $ppEnabled ? 'checked' : '' ?>>
                    Page pooling is <span id="pp-state"><?= $ppEnabled ? 'ON' : 'OFF' ?></span>
                    <span id="pp-unsaved" class="hint" style="display:none;font-weight:600;color:#92400e;">&mdash; not saved yet</span>
                </label>
                <p class="hint" style="margin:6px 0 0;">
                    <strong>On:</strong> each site builds only its own subset &mdash; Pinned pages plus a fill from
                    Rotate, up to the per-site total below. <strong>Off:</strong> every page that isn&rsquo;t Skip is
                    built on every site, and the totals below are ignored.
                    Changing this only affects domains that have <em>never been built</em>; a site that already exists
                    keeps the pages it was built with.
                </p>
            </div>
            <div id="pp-counts" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:14px;<?= $ppEnabled ? '' : 'opacity:.55;' ?>">
                <label style="<?= $lbl ?>">Pages per site &mdash; a domain lands on ONE of these totals (picked per domain, not the same for every site)</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <?php /* Three is the designed shape, but render more if more are on
                             file — a count with no box is invisible here and would be
                             dropped by the next save. */ ?>
                    <?php $ppBoxes = max(3, count($ppCounts)); for ($ci = 0; $ci < $ppBoxes; $ci++): ?>
                        <input type="number" name="pp_counts[]" min="1" step="1"
                               value="<?= h((string)($ppCounts[$ci] ?? '')) ?>" style="width:80px;">
                    <?php endfor; ?>
                    <span class="hint">Pinned pages count toward this total; the rest fill from Rotate.
                    <?php $ppElig = 0; foreach ($bySection['landing'] as $_s) {
                        $_p = $_s['pool'] ?? ms_page_pool_default_for_tier((string)($_s['tier'] ?? ''));
                        if ($_p !== 'skip') $ppElig++;
                    } $ppMax = $ppCounts ? max($ppCounts) : 0; ?>
                    <?php if ($ppEnabled && $ppMax >= $ppElig && $ppElig > 0): ?>
                        <br><strong style="color:#92400e;">Note:</strong> <?= (int) $ppElig ?> pages are eligible and the
                        highest total here is <?= (int) $ppMax ?> &mdash; domains that land on it build
                        <em>everything</em>, so pooling changes nothing for them. Lower the top number to prune.
                    <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            <div id="kw-rows-<?= h($key) ?>">
                <?php $renderItems($bySection[$key], $key); ?>
            </div>
            <button type="button" class="btn" style="margin-top:2px;" onclick="kwAddItem('kw-rows-<?= h($key) ?>','<?= h($key) ?>')">+ Add keyword</button>
        </div>
        <?php $n++; endforeach; ?>

        <button type="submit" class="btn">Save</button>
    </form>

    <script>
    // The state on file, so the 'not saved yet' marker only shows on a real change.
    var PP_SAVED = <?= $ppEnabled ? 'true' : 'false' ?>;

    function ppConfirmToggle(el) {
        var msg = el.checked
            ? 'Turn page pooling ON?\n\nEach site builds only some of these pages \u2014 the Pinned ones '
              + 'plus a fill from Rotate, up to one of the per-site totals.'
            : 'Turn page pooling OFF?\n\nEvery page that is not Skip gets built on every site. '
              + 'The per-site totals are ignored.';
        msg += '\n\nTakes effect when you Save, and only for sites not built yet.';
        if (!confirm(msg)) { el.checked = !el.checked; return false; }
        ppPaint(el.checked);
        return true;
    }

    // Keep the panel telling the truth about the checkbox. Without this the label
    // still read its SAVED state, so unticking the box left a green "Page pooling
    // is ON" sitting above an unticked box.
    function ppPaint(on) {
        var p = document.getElementById('pp-panel'),
            l = document.getElementById('pp-lbl'),
            st = document.getElementById('pp-state'),
            u = document.getElementById('pp-unsaved'),
            c = document.getElementById('pp-counts');
        if (st) st.textContent = on ? 'ON' : 'OFF';
        if (p) { p.style.background = on ? '#f0fdf4' : '#fef2f2';
                 p.style.borderColor = on ? '#86efac' : '#fca5a5'; }
        if (l) l.style.color = on ? '#166534' : '#991b1b';
        if (u) u.style.display = (on === PP_SAVED) ? 'none' : '';
        if (c) c.style.opacity = on ? '' : '.55';
    }

    var KW_TIERS = <?= json_encode($tierOpts) ?>;
    var KW_TIER_RANK = <?= json_encode($tierRank) ?>;
    var KW_POOLS = <?= json_encode($poolOpts) ?>;
    function kwEsc(v){ return (v==null?'':(''+v)).replace(/"/g,'&quot;'); }
    function kwText(v){ return (v==null?'':(''+v)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function kwItemHtml(section, primary, slug, tier, secondary, pool){
        var t='<option value="">Tier…</option>'; for(var k in KW_TIERS){ t+='<option value="'+k+'"'+(tier===k?' selected':'')+'>'+KW_TIERS[k]+'</option>'; }
        var poolField;
        if(section==='landing'){
            var pv = pool || 'rotate';
            var p='';
            for(var pk in KW_POOLS){ p+='<option value="'+pk+'"'+(pv===pk?' selected':'')+'>'+KW_POOLS[pk]+'</option>'; }
            poolField = '<div style="width:170px;flex:none;">'+
                          '<label style="display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;">Which sites build it</label>'+
                          '<select name="kw_pool[]" style="width:100%;" title="Which sites build this page">'+p+'</select>'+
                        '</div>';
        } else {
            poolField = '<input type="hidden" name="kw_pool[]" value="">';
        }
        return '<div style="margin-bottom:8px;">'+
                 '<div style="display:flex;gap:8px;align-items:flex-end;">'+
                   '<span class="kw-num" style="flex:none;display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;background:#7c3aed;color:#fff;border-radius:12px;font-size:.78rem;font-weight:700;margin-bottom:4px;"></span>'+
                   '<div style="flex:1;min-width:0;">'+
                     '<label style="display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;">Primary keyword</label>'+
                     '<input type="text" name="kw_primary[]" value="'+kwEsc(primary)+'" style="width:100%;">'+
                   '</div>'+
                   '<div style="width:120px;flex:none;">'+
                     '<label style="display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;">Priority</label>'+
                     '<select name="kw_tier[]" style="width:100%;" title="Tier / priority">'+t+'</select>'+
                   '</div>'+
                   poolField+
                   '<input type="hidden" name="kw_section[]" value="'+kwEsc(section)+'">'+
                   '<button type="button" class="btn" style="padding:2px 8px;flex:none;margin-bottom:1px;" onclick="kwMove(this,-1)" title="Move up">&uarr;</button>'+
                   '<button type="button" class="btn" style="padding:2px 8px;flex:none;margin-bottom:1px;" onclick="kwMove(this,1)" title="Move down">&darr;</button>'+
                   '<button type="button" class="btn btn-danger" style="padding:2px 9px;flex:none;margin-bottom:1px;" onclick="kwDel(this)" title="Remove keyword">&times;</button>'+
                 '</div>'+
               '</div>'+
               '<div style="margin-bottom:8px;">'+
                 '<label style="display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;">Slug base</label>'+
                 '<input type="text" name="kw_slug[]" value="'+kwEsc(slug)+'" style="width:100%;font-family:monospace;font-size:.85rem;">'+
               '</div>'+
               '<div>'+
                 '<label style="display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;">Secondary keywords <span style="font-weight:400;">(comma or line separated)</span></label>'+
                 '<textarea name="kw_secondary[]" rows="2" style="width:100%;font-size:.85rem;">'+kwText(secondary)+'</textarea>'+
               '</div>';
    }
    function kwRenumber(container){
        var items=container.querySelectorAll(':scope > .kw-item');
        for(var i=0;i<items.length;i++){ var n=items[i].querySelector('.kw-num'); if(n) n.textContent=(i+1); }
    }
    function kwTierRank(v){ return KW_TIER_RANK[v] || 0; }
    function kwSort(btn, containerId){
        var c=document.getElementById(containerId);
        var dir=btn.dataset.dir||'desc';
        var items=Array.prototype.slice.call(c.querySelectorAll(':scope > .kw-item'));
        items.sort(function(a,b){
            var sa=a.querySelector('select[name="kw_tier[]"]'), sb=b.querySelector('select[name="kw_tier[]"]');
            var ra=kwTierRank(sa?sa.value:''), rb=kwTierRank(sb?sb.value:'');
            if((ra===0)!==(rb===0)) return ra===0?1:-1;   // untiered always last
            if(ra===rb) return 0;
            return dir==='desc' ? rb-ra : ra-rb;
        });
        items.forEach(function(it){ c.appendChild(it); });   // reorder in place
        kwRenumber(c);
        btn.dataset.dir = dir==='desc' ? 'asc' : 'desc';
        btn.innerHTML = 'Sort: ' + (btn.dataset.dir==='desc' ? 'High &rarr; Low' : 'Low &rarr; High');
    }
    function kwDel(btn){
        var item=btn.closest('.kw-item'); var c=item.parentNode; item.remove(); kwRenumber(c);
    }
    function kwMove(btn, dir){
        var item=btn.closest('.kw-item'); var c=item.parentNode;
        if(dir<0){ var p=item.previousElementSibling; if(p && p.classList.contains('kw-item')) c.insertBefore(item,p); }
        else { var n=item.nextElementSibling; if(n && n.classList.contains('kw-item')) c.insertBefore(n,item); }
        kwRenumber(c);
    }
    function kwAddItem(containerId, section, primary, slug, tier, secondary, pool){
        var c=document.getElementById(containerId);
        var d=document.createElement('div'); d.className='kw-item';
        d.style.cssText='border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:10px;background:#fff;';
        d.innerHTML=kwItemHtml(section, primary, slug, tier, secondary, pool);
        c.appendChild(d); kwRenumber(c); return d;
    }
    function kwDlSlug(s){ return (s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
    function kwSectionText(title, containerId){
        var c=document.getElementById(containerId);
        var items=c.querySelectorAll(':scope > .kw-item');
        var rule=new Array(73).join('-');
        var out='\n'+title.toUpperCase()+' ('+items.length+')\n'+rule+'\n';
        if(!items.length){ return out+'  (none)\n'; }
        for(var i=0;i<items.length;i++){
            var it=items[i];
            var primary=(it.querySelector('input[name="kw_primary[]"]').value||'').trim();
            var slug=(it.querySelector('input[name="kw_slug[]"]').value||'').trim();
            var tierV=it.querySelector('select[name="kw_tier[]"]').value;
            var tier=KW_TIERS[tierV]||'—';
            var sec=(it.querySelector('textarea[name="kw_secondary[]"]').value||'');
            var secList=sec.split(/[\r\n,]+/).map(function(x){return x.trim();}).filter(Boolean).join('; ');
            out+='\n'+(i+1)+'.  '+primary+'   ['+tier+']\n';
            out+='    slug:       '+slug+'\n';
            out+='    secondary:  '+(secList||'—')+'\n';
        }
        return out;
    }
    function kwDownload(){
        var niche=(document.querySelector('input[name="niche"]').value||'').trim();
        var bar=new Array(73).join('=');
        var txt=bar+'\n'+(niche||'NICHE').toUpperCase()+' — LANDING PAGE KEYWORD MAP\n';
        txt+='One page per service | primary + on-page secondaries\n';
        txt+='Tokens {city} / {ST} localize per deployed site.\n'+bar+'\n';
        txt+=kwSectionText('Home Page','kw-rows-home');
        txt+=kwSectionText('Core Pages','kw-rows-core');
        txt+=kwSectionText('Landing Pages','kw-rows-landing');
        var blob=new Blob([txt],{type:'text/plain;charset=utf-8'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download=(kwDlSlug(niche)||'niche')+'-keyword-map.txt';
        document.body.appendChild(a); a.click();
        setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); },100);
    }
    // Flat primary/secondary keyword list — one keyword per line, secondaries
    // deduped (case-insensitive). Built from the live form so unsaved edits are
    // included. Handy for pasting into Keyword Planner / Ahrefs / a rank tracker.
    function kwDownloadPriSec(){
        var niche=(document.querySelector('input[name="niche"]').value||'').trim();
        var prims=[], secs=[], seen={};
        var pins=document.querySelectorAll('input[name="kw_primary[]"]');
        for(var i=0;i<pins.length;i++){ var p=(pins[i].value||'').trim(); if(p) prims.push(p); }
        var tas=document.querySelectorAll('textarea[name="kw_secondary[]"]');
        for(var j=0;j<tas.length;j++){
            var parts=(tas[j].value||'').split(/[\r\n,]+/);
            for(var k=0;k<parts.length;k++){
                var s=parts[k].trim(); if(!s) continue;
                var key=s.toLowerCase(); if(seen[key]) continue; seen[key]=1; secs.push(s);
            }
        }
        var bar=new Array(73).join('='), rule=new Array(73).join('-');
        var txt=bar+'\n'+(niche||'NICHE').toUpperCase()+' — PRIMARY / SECONDARY KEYWORDS\n'+bar+'\n';
        txt+='\nPRIMARY KEYWORDS ('+prims.length+')\n'+rule+'\n'+(prims.length?prims.join('\n')+'\n':'  (none)\n');
        txt+='\nSECONDARY KEYWORDS ('+secs.length+', deduped)\n'+rule+'\n'+(secs.length?secs.join('\n')+'\n':'  (none)\n');
        var blob=new Blob([txt],{type:'text/plain;charset=utf-8'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download=(kwDlSlug(niche)||'niche')+'-pri-sec-keywords.txt';
        document.body.appendChild(a); a.click();
        setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); },100);
    }
    </script>
</div>
