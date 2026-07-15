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

require_once __DIR__ . '/../../includes/keyword_roles.php';
$roleInfo = keyword_map_roles($kwMap);   // page-role derivation for the structure summary + badges

$tierOpts = [
    'high-1' => 'High', 'high-2' => 'High 2', 'high-3' => 'High 3',
    'medium-1' => 'Medium', 'medium-2' => 'Medium 2', 'medium-3' => 'Medium 3',
    'low-1' => 'Low', 'low-2' => 'Low 2', 'low-3' => 'Low 3',
];
// Priority rank for sorting (higher = sorts to top on High→Low). Untiered = 0.
$tierRank = ['high-1'=>9,'high-2'=>8,'high-3'=>7,'medium-1'=>6,'medium-2'=>5,'medium-3'=>4,'low-1'=>3,'low-2'=>2,'low-3'=>1];

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
$renderItems = function (array $rows, string $section) use ($tierOpts, $lbl, $numStyle, $roleInfo) {
    foreach ($rows as $idx => $s):
        $nm = $s['primary'] ?? ''; $sl = $s['slug'] ?? ''; $ti = $s['tier'] ?? '';
        $secStr = implode(', ', array_map('trim', (array)($s['secondary'] ?? [])));
        $roleLab = $roleInfo['roles'][$sl]['label'] ?? '';
    ?>
        <div class="kw-item" style="border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:10px;background:#fff;">
            <div style="margin-bottom:8px;">
                <label style="<?= $lbl ?>">Primary keyword <?php if ($roleLab): ?><span style="<?= keyword_role_chip_style($roleLab) ?>margin-left:6px;"><?= h($roleLab) ?></span><?php endif; ?></label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="kw-num" style="<?= $numStyle ?>"><?= $idx + 1 ?></span>
                    <input type="text" name="kw_primary[]" value="<?= h($nm) ?>" style="flex:1;min-width:0;">
                    <select name="kw_tier[]" style="width:120px;flex:none;" title="Tier / priority"><option value="">Tier…</option><?php foreach ($tierOpts as $v=>$l): ?><option value="<?= $v ?>" <?= $ti===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
                    <input type="hidden" name="kw_section[]" value="<?= h($section) ?>">
                    <button type="button" class="btn" style="padding:2px 8px;flex:none;" onclick="kwMove(this,-1)" title="Move up">&uarr;</button>
                    <button type="button" class="btn" style="padding:2px 8px;flex:none;" onclick="kwMove(this,1)" title="Move down">&darr;</button>
                    <button type="button" class="btn btn-danger" style="padding:2px 9px;flex:none;" onclick="kwDel(this)" title="Remove keyword">&times;</button>
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
        <h2 style="margin-top:0;margin-bottom:8px;">How to build your keyword map</h2>
        <p class="hint" style="margin:0 0 10px;">
            <strong>Model:</strong> one site per city, long-tail <code>keyword + city</code>. Every keyword becomes a page with a <strong>primary</strong>
            (the page's main target), its <strong>secondary</strong> keywords (variants worked into the title, H2s, and FAQ), and a <strong>tier</strong> (priority).
            This map is the source of truth that feeds the Bulk Template Generator.
        </p>
        <ol class="hint" style="margin:0 0 14px;padding-left:20px;line-height:1.7;">
            <li><strong>Get the prompt.</strong> Download the prompt template, then fill in the <code>[BRACKETS]</code> — your niche — and attach your keyword data (a Google Keyword Planner or Ahrefs CSV export).</li>
            <li><strong>Generate the list.</strong> Paste it into an AI assistant. It clusters your keywords into pages: one <strong>Home</strong> head term, optional <strong>Core</strong> category pages, and one <strong>Landing</strong> page per service — each with a primary, secondaries, and a tier.</li>
            <li><strong>Check the format.</strong> The sample output shows exactly what a finished list looks like (any niche follows the same shape).</li>
            <li><strong>Enter it here.</strong> Type the results into the Home / Core / Landing sections below and click <strong>Save</strong>.</li>
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
            <div id="kw-rows-<?= h($key) ?>">
                <?php $renderItems($bySection[$key], $key); ?>
            </div>
            <button type="button" class="btn" style="margin-top:2px;" onclick="kwAddItem('kw-rows-<?= h($key) ?>','<?= h($key) ?>')">+ Add keyword</button>
        </div>
        <?php $n++; endforeach; ?>

        <button type="submit" class="btn">Save</button>
    </form>

    <script>
    var KW_TIERS = <?= json_encode($tierOpts) ?>;
    var KW_TIER_RANK = <?= json_encode($tierRank) ?>;
    function kwEsc(v){ return (v==null?'':(''+v)).replace(/"/g,'&quot;'); }
    function kwText(v){ return (v==null?'':(''+v)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function kwItemHtml(section, primary, slug, tier, secondary){
        var t='<option value="">Tier…</option>'; for(var k in KW_TIERS){ t+='<option value="'+k+'"'+(tier===k?' selected':'')+'>'+KW_TIERS[k]+'</option>'; }
        return '<div style="margin-bottom:8px;">'+
                 '<label style="display:block;font-size:.72rem;font-weight:600;color:#64748b;margin:0 0 2px;">Primary keyword</label>'+
                 '<div style="display:flex;gap:8px;align-items:center;">'+
                   '<span class="kw-num" style="flex:none;display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;background:#7c3aed;color:#fff;border-radius:12px;font-size:.78rem;font-weight:700;"></span>'+
                   '<input type="text" name="kw_primary[]" value="'+kwEsc(primary)+'" style="flex:1;min-width:0;">'+
                   '<select name="kw_tier[]" style="width:120px;flex:none;" title="Tier / priority">'+t+'</select>'+
                   '<input type="hidden" name="kw_section[]" value="'+kwEsc(section)+'">'+
                   '<button type="button" class="btn" style="padding:2px 8px;flex:none;" onclick="kwMove(this,-1)" title="Move up">&uarr;</button>'+
                   '<button type="button" class="btn" style="padding:2px 8px;flex:none;" onclick="kwMove(this,1)" title="Move down">&darr;</button>'+
                   '<button type="button" class="btn btn-danger" style="padding:2px 9px;flex:none;" onclick="kwDel(this)" title="Remove keyword">&times;</button>'+
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
    function kwAddItem(containerId, section, primary, slug, tier, secondary){
        var c=document.getElementById(containerId);
        var d=document.createElement('div'); d.className='kw-item';
        d.style.cssText='border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:10px;background:#fff;';
        d.innerHTML=kwItemHtml(section, primary, slug, tier, secondary);
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
