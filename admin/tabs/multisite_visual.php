<?php
/**
 * Visual Identity library (the "factory"). One per-site panel that:
 *   • creates/edits up to 10 presets (colors + font + button radius + bug icon),
 *     each with a live logo + favicon preview,
 *   • lets you pick ONE preset as THIS site's brand ("Use for this site" →
 *     applies colors, optionally font/buttons, and regenerates logo + favicon),
 *   • flags which presets the multisite build rotates through when generating clones.
 *
 * Included by admin/tabs/theme.php (the per-site home). Reads theme_presets.json +
 * icons/; previews via admin/visual_preview.php; library save via
 * admin/visual_presets_save.php; single-site apply via save.php (section=apply_preset).
 * Needs $csrfToken (from index.php). Must sit OUTSIDE the Theme <form>.
 */
$vpDoc      = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/theme_presets.json'), true) ?: [];
$vpSingleId = (int)($vpDoc['single_preset_id'] ?? 0);
$vpPresets  = [];
foreach (($vpDoc['presets'] ?? []) as $p) {
    $t = $p['theme'] ?? [];
    $vpPresets[] = [
        'name'        => (string)($p['name'] ?? ''),
        'note'        => (string)($p['note'] ?? ''),
        'accent'      => (string)($t['accent_color'] ?? '#333333'),
        'dark'        => (string)($t['heading_color'] ?? ($t['header_bg'] ?? '#111111')),
        'font'        => (string)($t['primary_font'] ?? 'Inclusive Sans, sans-serif'),
        'radius'      => (string)($t['button_radius'] ?? '5'),
        'icon'        => (string)($p['icon'] ?? ''),
        'in_rotation' => array_key_exists('in_rotation', $p) ? (bool)$p['in_rotation'] : true,
    ];
}
$vpIcons = [];
foreach (glob(ACTIVE_SITE_DIR . '/multisite/icons/*.svg') ?: [] as $f) $vpIcons[] = basename($f);
$vpBusiness = trim($data['site_vars']['business'] ?? '') ?: 'Acme Company';
$vpFonts = ['Inclusive Sans, sans-serif','Inter, sans-serif','Nunito, sans-serif','Poppins, sans-serif',
            'Montserrat, sans-serif','Roboto, sans-serif','Open Sans, sans-serif','Lato, sans-serif',
            'Raleway, sans-serif','Mulish, sans-serif','Playfair Display, serif','Merriweather, serif'];
?>
<div class="card" id="ms-visual">
    <h2 style="margin-top:0;">Visual Identity — Presets</h2>
    <p class="hint" style="margin-bottom:6px;">Build a library of visual identities (colors + font + button shape + bug icon). Each drives a generated <strong>logo + favicon</strong>. Then:</p>
    <ul class="hint" style="margin:0 0 12px 18px;line-height:1.7;">
        <li><strong>Use for this site</strong> — applies one preset to <em>this</em> site now (theme + logo + favicon).</li>
        <li><strong>In multisite rotation</strong> — the multisite build rotates through the checked presets when generating clone sites (or a row's <code>theme_preset</code> column overrides).</li>
    </ul>
    <?php if (!$vpIcons): ?>
        <p class="hint" style="color:#b45309;">No brand icons yet — add SVGs in the <strong>Brand icons</strong> card above. Until then presets render a wordmark logo + a monogram favicon.</p>
    <?php endif; ?>
    <div style="display:flex;gap:10px;align-items:center;margin:12px 0 16px;flex-wrap:wrap;">
        <label for="msv-name" style="margin:0;font-weight:600;">Preview name:</label>
        <input type="text" id="msv-name" value="<?= h($vpBusiness) ?>" style="width:220px;" oninput="msvAllPreviews()">
        <span id="msv-count" class="hint"></span>
    </div>
    <div id="msv-list"></div>
    <div style="margin-top:6px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" id="msv-add" onclick="msvAdd()">+ Add preset</button>
        <button type="button" class="btn" onclick="msvSave()">Save library</button>
        <label style="margin:0 0 0 8px;font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="msv-typo" checked> “Use for this site” applies font &amp; buttons too
        </label>
        <span id="msv-msg" class="hint" style="margin-left:4px;"></span>
    </div>
    <p class="hint" style="margin:8px 0 0;">Changes <strong>save automatically</strong> as you edit (icon, colors, font, rotation) — the “Saved” note confirms it; <strong>Save library</strong> is just a manual re-save. Tick which presets are <strong>in multisite rotation</strong>. To set <em>this</em> site's own brand, click <strong>Use for this site →</strong> on a preset (applies its look + regenerates the logo now).</p>

    <!-- Real POST for the single-site apply (full reload → shows the applied theme). -->
    <form id="msv-apply-form" action="save.php" method="post" style="display:none;">
        <input type="hidden" name="section" value="apply_preset">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken ?? '') ?>">
        <input type="hidden" name="preset_id" id="msv-apply-id" value="">
        <input type="hidden" name="apply_typography" id="msv-apply-typo" value="1">
    </form>

<script>
var MSV        = <?= json_encode($vpPresets, JSON_UNESCAPED_SLASHES) ?>;
var MSV_ICONS  = <?= json_encode($vpIcons, JSON_UNESCAPED_SLASHES) ?>;
var MSV_FONTS  = <?= json_encode($vpFonts, JSON_UNESCAPED_SLASHES) ?>;
var MSV_CSRF   = <?= json_encode($csrfToken ?? '') ?>;
var MSV_SINGLE = <?= $vpSingleId > 0 ? ($vpSingleId - 1) : -1 ?>;   // 0-based index of this site's brand
var msvBust    = 0;

function msvEsc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function msvName(){ return document.getElementById('msv-name').value || 'Acme Company'; }

function msvPreviewSrc(i, type){
    var p = MSV[i];
    return 'visual_preview.php?type=' + type
        + '&accent=' + encodeURIComponent(p.accent)
        + '&dark='   + encodeURIComponent(p.dark)
        + '&icon='   + encodeURIComponent(p.icon || '')
        + '&name='   + encodeURIComponent(msvName())
        + '&_='      + msvBust;
}
function msvPreview(i){
    var card = document.querySelector('.msv-card[data-i="'+i+'"]'); if(!card) return;
    msvBust++;
    card.querySelector('.msv-logo').src = msvPreviewSrc(i,'logo');
    var fav = card.querySelector('.msv-fav');
    if (MSV[i].icon){ fav.style.display=''; fav.src = msvPreviewSrc(i,'favicon'); } else { fav.style.display='none'; }
}
function msvAllPreviews(){ MSV.forEach(function(_,i){ msvPreview(i); }); }

function msvCardHtml(i){
    var p = MSV[i];
    var fonts = MSV_FONTS.map(function(f){ return '<option value="'+msvEsc(f)+'"'+(f===p.font?' selected':'')+'>'+msvEsc(f.split(',')[0])+'</option>'; }).join('');
    if (MSV_FONTS.indexOf(p.font)<0) fonts = '<option value="'+msvEsc(p.font)+'" selected>'+msvEsc(p.font.split(',')[0])+'</option>' + fonts;
    var icons = '<option value="">— none —</option>' + MSV_ICONS.map(function(ic){ return '<option value="'+msvEsc(ic)+'"'+(ic===p.icon?' selected':'')+'>'+msvEsc(ic.replace(/\.svg$/,''))+'</option>'; }).join('');
    var isSingle = (MSV_SINGLE === i);
    return '<div class="msv-card" data-i="'+i+'" style="border:1px solid '+(isSingle?'#2563eb':'#e2e8f0')+';border-radius:8px;padding:14px;margin-bottom:12px;display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;'+(isSingle?'box-shadow:0 0 0 2px #dbeafe;':'')+'">'
      + '<div style="flex:0 0 250px;">'
      +   '<img class="msv-logo" alt="logo" style="width:100%;background:#fff;border:1px solid #eee;border-radius:6px;padding:8px;min-height:56px;">'
      +   '<div style="margin-top:8px;display:flex;align-items:center;gap:8px;"><img class="msv-fav" alt="favicon" width="44" height="44" style="border-radius:9px;border:1px solid #eee;"><span class="hint">favicon</span></div>'
      +   '<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">'
      +     (isSingle
                 ? '<div style="font-size:.85rem;color:#2563eb;font-weight:700;">★ This site’s brand</div>'
                 : '<button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:.85rem;align-self:flex-start;" onclick="msvUse('+i+')">Use for this site →</button>')
      +     '<label style="margin:0;font-weight:400;display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.9rem;">'
      +       '<input type="checkbox" class="msv-rot" data-i="'+i+'"'+(p.in_rotation!==false?' checked':'')+'> In multisite rotation</label>'
      +   '</div>'
      + '</div>'
      + '<div style="flex:1;min-width:250px;">'
      +   '<label style="margin-top:0;">Preset name</label><input type="text" class="msv-f" data-k="name" value="'+msvEsc(p.name)+'">'
      +   '<div style="display:flex;gap:14px;margin-top:8px;">'
      +     '<div><label style="margin-top:0;">Accent</label><input type="color" class="msv-f" data-k="accent" value="'+msvEsc(p.accent)+'"></div>'
      +     '<div><label style="margin-top:0;">Dark</label><input type="color" class="msv-f" data-k="dark" value="'+msvEsc(p.dark)+'"></div>'
      +     '<div style="flex:1;"><label style="margin-top:0;">Brand icon</label><select class="msv-f" data-k="icon">'+icons+'</select></div>'
      +   '</div>'
      +   '<div style="display:flex;gap:14px;margin-top:8px;">'
      +     '<div style="flex:1;"><label style="margin-top:0;">Font</label><select class="msv-f" data-k="font">'+fonts+'</select></div>'
      +     '<div><label style="margin-top:0;">Button radius</label><input type="number" min="0" max="50" class="msv-f" data-k="radius" value="'+msvEsc(p.radius)+'" style="width:80px;"></div>'
      +   '</div>'
      +   '<button type="button" onclick="msvRemove('+i+')" style="margin-top:12px;background:none;border:0;color:#dc2626;cursor:pointer;font-size:.85rem;padding:0;">✕ Remove preset</button>'
      + '</div>'
      + '</div>';
}
function msvRender(){
    document.getElementById('msv-list').innerHTML = MSV.map(function(_,i){ return msvCardHtml(i); }).join('');
    document.querySelectorAll('#msv-list .msv-f').forEach(function(el){
        var card = el.closest('.msv-card'); var i = +card.getAttribute('data-i'); var k = el.getAttribute('data-k');
        var ev = (el.type === 'color' || el.tagName === 'SELECT') ? 'change' : 'input';
        el.addEventListener(ev, function(){ MSV[i][k] = el.value; if (k!=='name') msvPreview(i); });
        el.addEventListener('change', msvAutoSave);   // auto-persist on commit/blur (icon, color, font, radius, name)
    });
    document.querySelectorAll('#msv-list .msv-rot').forEach(function(el){
        el.addEventListener('change', function(){ MSV[+el.getAttribute('data-i')].in_rotation = el.checked; msvAutoSave(); });
    });
    document.getElementById('msv-count').textContent = MSV.length + ' / 10 presets';
    document.getElementById('msv-add').disabled = MSV.length >= 10;
    msvAllPreviews();
}
function msvAdd(){
    if (MSV.length >= 10) return;
    MSV.push({name:'Preset '+(MSV.length+1), note:'', accent:'#2563eb', dark:'#1e293b',
              font:'Inter, sans-serif', radius:'6', in_rotation:true,
              icon:(MSV_ICONS[MSV.length % Math.max(1,MSV_ICONS.length)]||'')});
    msvRender();
    msvAutoSave();
}
function msvRemove(i){
    if (MSV.length<=1){ alert('Keep at least one preset.'); return; }
    MSV.splice(i,1);
    if (MSV_SINGLE === i) MSV_SINGLE = -1; else if (MSV_SINGLE > i) MSV_SINGLE--;
    msvRender();
    msvAutoSave();
}
// Called from the Brand icons card: assign uploaded icons to the presets in order.
function msvAutoAssignIcons(){
    if (!MSV_ICONS.length){ alert('Upload some SVG icons first (Brand icons card above).'); return; }
    MSV.forEach(function(p,i){ p.icon = MSV_ICONS[i % MSV_ICONS.length]; });
    msvRender();
    msvSave();   // persist immediately (sets its own "Saved N presets." message)
}
function msvPayload(){
    var fd = new FormData();
    fd.append('csrf_token', MSV_CSRF);
    fd.append('presets', JSON.stringify(MSV));
    fd.append('single_preset_id', MSV_SINGLE >= 0 ? (MSV_SINGLE + 1) : 0);
    return fd;
}
// Debounced auto-save so preset edits (esp. picking an icon) persist without needing
// to remember the "Save library" button — the #1 "it doesn't save" confusion.
var msvSaveTimer = null;
function msvAutoSave(){ clearTimeout(msvSaveTimer); msvSaveTimer = setTimeout(function(){ msvSave(); }, 600); }
function msvSave(cb){
    var msg = document.getElementById('msv-msg'); msg.style.color='#64748b'; msg.textContent='Saving…';
    fetch('visual_presets_save.php', {method:'POST', body:msvPayload()})
      .then(function(r){ return r.json(); })
      .then(function(d){
          if(d.ok){ msg.style.color='#059669'; msg.textContent='Saved '+d.count+' presets.'; if(cb)cb(true); }
          else { msg.style.color='#dc2626'; msg.textContent='Error: '+(d.error||'save failed'); if(cb)cb(false); }
      })
      .catch(function(){ msg.style.color='#dc2626'; msg.textContent='Network error.'; if(cb)cb(false); });
}
function msvUse(i){
    var p = MSV[i];
    var typo = document.getElementById('msv-typo').checked;
    var warn = 'Make "'+(p.name||'preset')+'" this site\'s brand?\n\nThis overwrites the site\'s '
             + (typo ? 'colors, font and button style' : 'colors')
             + ' and regenerates the logo + favicon.';
    if (!confirm(warn)) return;
    // Save the library first (so the applied version + any edits match), then apply.
    msvSave(function(ok){
        if(!ok) return;
        document.getElementById('msv-apply-id').value = i + 1;
        document.getElementById('msv-apply-typo').value = typo ? '1' : '';
        document.getElementById('msv-apply-form').submit();
    });
}
msvRender();
</script>
</div>
