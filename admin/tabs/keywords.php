<?php
// Keywords tab — Stage 1: build the PRIMARY keyword list (the service list).
// Map-first: solidifying writes data/keyword_map.json, the source of truth that
// feeds the Bulk Template Generator and (Stage 2) each page's secondary keywords.
// $tab, $csrfToken available from index.php.

$kwFile = dirname(TEMPLATES_FILE) . '/keyword_map.json';
$kwMap  = file_exists($kwFile) ? (json_decode(file_get_contents($kwFile), true) ?: []) : [];
$services = $kwMap['services'] ?? [];
$stage    = $kwMap['stage'] ?? 'primary';

// Seed from existing templates on first use, so the operator starts from what's there.
if (empty($services) && file_exists(TEMPLATES_FILE)) {
    $tpls = json_decode(file_get_contents(TEMPLATES_FILE), true) ?: [];
    foreach ($tpls as $t) {
        $name = trim($t['seo']['service_name'] ?? '') ?: trim(preg_replace('/\s*\|.*$/', '', $t['title'] ?? ''));
        $slug = trim(str_replace('-{city_slug}', '', $t['slug_pattern'] ?? ''));
        if ($name === '') continue;
        $services[] = ['primary' => $name, 'slug' => $slug, 'tier' => '', 'status' => 'primary', 'secondary' => []];
    }
}
$tierOpts   = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$statusOpts = ['primary' => 'Primary (own page)', 'fold' => 'Fold into another', 'cut' => 'Cut'];
?>
<div class="tab-content" style="<?= $tab === 'keywords' ? '' : 'display:none;' ?>">
<?php tab_header('Keywords', 'Decide the keyword map before generating templates. Stage 1: lock the PRIMARY keywords (your service list). Stage 2 (after) builds each one\'s secondary long-tail keywords.', 'tab-keywords'); ?>

    <div class="card" style="margin-bottom:16px;">
        <p class="hint" style="margin:0;">
            <strong>Model:</strong> one site per city, long-tail <code>keyword + city</code>. Each <strong>Primary</strong> becomes one landing page
            (primary keyword = <code>[service] [city]</code>). Score by <strong>tier</strong> (lead value × demand × winnability) and keep the strong ones —
            aim for ~10–15 focused, high-value services. Mark low-value/niche ones <em>Cut</em> or <em>Fold</em>. This list is the source of truth that feeds
            the Bulk Template Generator; Stage 2 then adds each page's secondary keywords.
        </p>
    </div>

    <form action="keywords_save.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_primaries">

        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:12px;flex-wrap:wrap;">
                <h2 style="margin:0;">Stage 1 — Primary keywords (service list)</h2>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn" style="background:#7c3aed;" id="kw-ai-btn" onclick="kwAiSuggest()">&#10024; AI Suggest &amp; Rank</button>
                    <button type="submit" class="btn">Solidify Primaries</button>
                </div>
            </div>
            <p class="hint" style="margin-bottom:12px;"><strong>Tier</strong> = priority (High = build first). <strong>Status</strong> = Primary (own page) / Fold / Cut. AI Suggest proposes the niche's services with a rough tier — <em>directional; validate demand with a real tool</em>.</p>
            <div id="kw-ai-msg" style="display:none;margin-bottom:10px;padding:8px 12px;border-radius:6px;font-size:.85rem;"></div>

            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="text-align:left;">
                    <th style="padding:4px 8px;font-size:.78rem;color:#64748b;">Primary keyword (service)</th>
                    <th style="padding:4px 8px;font-size:.78rem;color:#64748b;">Slug base</th>
                    <th style="padding:4px 8px;font-size:.78rem;color:#64748b;width:120px;">Tier</th>
                    <th style="padding:4px 8px;font-size:.78rem;color:#64748b;width:160px;">Status</th>
                    <th style="width:44px;"></th>
                </tr></thead>
                <tbody id="kw-rows">
                <?php foreach ($services as $s):
                    $nm = $s['primary'] ?? ''; $sl = $s['slug'] ?? ''; $ti = $s['tier'] ?? ''; $st = $s['status'] ?? 'primary';
                ?>
                    <tr>
                        <td style="padding:3px 8px;"><input type="text" name="kw_primary[]" value="<?= h($nm) ?>" style="width:100%;" oninput="kwSlug(this)"></td>
                        <td style="padding:3px 8px;"><input type="text" name="kw_slug[]" value="<?= h($sl) ?>" style="width:100%;font-family:monospace;font-size:.82rem;"></td>
                        <td style="padding:3px 8px;"><select name="kw_tier[]" style="width:100%;"><option value=""></option><?php foreach ($tierOpts as $v=>$l): ?><option value="<?= $v ?>" <?= $ti===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></td>
                        <td style="padding:3px 8px;"><select name="kw_status[]" style="width:100%;"><?php foreach ($statusOpts as $v=>$l): ?><option value="<?= $v ?>" <?= $st===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></td>
                        <td style="padding:3px 8px;"><button type="button" class="btn btn-danger" style="padding:2px 9px;" onclick="this.closest('tr').remove()">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn" style="margin-top:10px;" onclick="kwAddRow()">+ Add row</button>
        </div>

        <div class="card" style="margin-bottom:16px;background:#f8fafc;">
            <h2 style="margin-top:0;">Stage 2 — Secondary keywords</h2>
            <p class="hint" style="margin:0;">Unlocks after you solidify the primaries. It'll let you build each primary's long-tail secondaries (with AI assist) and write them onto the matching templates. <em>Current status: <strong><?= $stage === 'secondary' ? 'primaries solidified — ready' : 'locked (solidify primaries first)' ?></strong>.</em></p>
        </div>

        <button type="submit" class="btn">Solidify Primaries</button>
    </form>

    <script>
    var KW_TIERS = <?= json_encode($tierOpts) ?>;
    var KW_STATUS = <?= json_encode($statusOpts) ?>;
    function kwSlugify(s){ return s.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
    function kwSlug(inp){ var tr=inp.closest('tr'); var slugIn=tr.querySelector('input[name="kw_slug[]"]'); if(slugIn && !slugIn.dataset.touched){ slugIn.value=kwSlugify(inp.value); } }
    function kwRowHtml(primary, slug, tier, status){
        var t='<option value=""></option>'; for(var k in KW_TIERS){ t+='<option value="'+k+'"'+(tier===k?' selected':'')+'>'+KW_TIERS[k]+'</option>'; }
        var s=''; for(var k2 in KW_STATUS){ s+='<option value="'+k2+'"'+((status||'primary')===k2?' selected':'')+'>'+KW_STATUS[k2]+'</option>'; }
        return '<td style="padding:3px 8px;"><input type="text" name="kw_primary[]" value="'+(primary||'').replace(/"/g,'&quot;')+'" style="width:100%;" oninput="kwSlug(this)"></td>'+
               '<td style="padding:3px 8px;"><input type="text" name="kw_slug[]" value="'+(slug||'').replace(/"/g,'&quot;')+'" style="width:100%;font-family:monospace;font-size:.82rem;" onchange="this.dataset.touched=1"></td>'+
               '<td style="padding:3px 8px;"><select name="kw_tier[]" style="width:100%;">'+t+'</select></td>'+
               '<td style="padding:3px 8px;"><select name="kw_status[]" style="width:100%;">'+s+'</select></td>'+
               '<td style="padding:3px 8px;"><button type="button" class="btn btn-danger" style="padding:2px 9px;" onclick="this.closest(\'tr\').remove()">&times;</button></td>';
    }
    function kwAddRow(primary, slug, tier, status){
        var tr=document.createElement('tr'); tr.innerHTML=kwRowHtml(primary, slug, tier, status);
        document.getElementById('kw-rows').appendChild(tr); return tr;
    }
    function kwMsg(txt, ok){ var m=document.getElementById('kw-ai-msg'); m.style.display='block'; m.style.background=ok?'#ecfdf5':'#fef2f2'; m.style.color=ok?'#065f46':'#991b1b'; m.textContent=txt; }
    function kwAiSuggest(){
        var btn=document.getElementById('kw-ai-btn'); btn.disabled=true; var old=btn.innerHTML; btn.innerHTML='Thinking…';
        var fd=new FormData(); fd.append('csrf_token','<?= h($csrfToken) ?>');
        fetch('keyword_map_suggest.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
            btn.disabled=false; btn.innerHTML=old;
            if(j.error){ kwMsg(j.error,false); return; }
            var list=j.services||[]; if(!list.length){ kwMsg('No suggestions returned.',false); return; }
            if(!confirm('Add '+list.length+' AI-suggested service(s) to the list? (Existing rows are kept; duplicates are skipped.)')) return;
            var have={}; document.querySelectorAll('#kw-rows input[name="kw_primary[]"]').forEach(function(i){ have[i.value.trim().toLowerCase()]=1; });
            var added=0;
            list.forEach(function(it){ var nm=(it.service||'').trim(); if(!nm||have[nm.toLowerCase()]) return; kwAddRow(nm, kwSlugify(nm), it.tier||'', 'primary'); have[nm.toLowerCase()]=1; added++; });
            kwMsg('Added '+added+' suggestion(s). Review tiers, then Solidify Primaries.', true);
        }).catch(function(e){ btn.disabled=false; btn.innerHTML=old; kwMsg('Request failed: '+e,false); });
    }
    </script>
</div>
