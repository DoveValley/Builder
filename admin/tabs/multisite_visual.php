<?php
/**
 * Multisite → Visual Identity editor (isolated). View / edit / add / remove /
 * save the campaign's Theme Presets (1–10), with live logo + favicon previews.
 * Included by admin/tabs/multisite.php. Reads theme_presets.json + icons/;
 * previews via admin/visual_preview.php; saves via admin/visual_presets_save.php.
 * Needs $csrfToken (from index.php).
 */
$vpDoc     = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/theme_presets.json'), true) ?: [];
$vpPresets = [];
foreach (($vpDoc['presets'] ?? []) as $p) {
    $t = $p['theme'] ?? [];
    $vpPresets[] = [
        'name'   => (string)($p['name'] ?? ''),
        'note'   => (string)($p['note'] ?? ''),
        'accent' => (string)($t['accent_color'] ?? '#333333'),
        'dark'   => (string)($t['heading_color'] ?? ($t['header_bg'] ?? '#111111')),
        'font'   => (string)($t['primary_font'] ?? 'Inclusive Sans, sans-serif'),
        'radius' => (string)($t['button_radius'] ?? '5'),
        'icon'   => (string)($p['icon'] ?? ''),
    ];
}
$vpIcons = [];
foreach (glob(ACTIVE_SITE_DIR . '/multisite/icons/*.svg') ?: [] as $f) $vpIcons[] = basename($f);
$vpFonts = ['Inclusive Sans, sans-serif','Inter, sans-serif','Nunito, sans-serif','Poppins, sans-serif',
            'Montserrat, sans-serif','Roboto, sans-serif','Open Sans, sans-serif','Lato, sans-serif',
            'Raleway, sans-serif','Mulish, sans-serif','Playfair Display, serif','Merriweather, serif'];
?>
<div class="card" id="ms-visual">
    <h3 style="margin-top:0;">Visual Identity — Theme Presets</h3>
    <p class="hint">Each site is assigned one preset (the <code>theme_preset</code> CSV column, or auto by domain hash). Its <strong>colors, font, button shape, and bug icon</strong> drive the site's theme and its generated <strong>logo + favicon</strong>. Up to 10.</p>
    <?php if (!$vpIcons): ?>
        <p class="hint" style="color:#b45309;">No bug icons found in <code>multisite/icons/</code> — presets will render a wordmark only.</p>
    <?php endif; ?>
    <div style="display:flex;gap:10px;align-items:center;margin:12px 0 16px;flex-wrap:wrap;">
        <label for="msv-name" style="margin:0;font-weight:600;">Preview name:</label>
        <input type="text" id="msv-name" value="Acme Pest Control" style="width:220px;" oninput="msvAllPreviews()">
        <span id="msv-count" class="hint"></span>
    </div>
    <div id="msv-list"></div>
    <div style="margin-top:6px;display:flex;gap:10px;align-items:center;">
        <button type="button" class="btn btn-secondary" id="msv-add" onclick="msvAdd()">+ Add preset</button>
        <button type="button" class="btn" onclick="msvSave()">Save presets</button>
        <span id="msv-msg" class="hint" style="margin-left:4px;"></span>
    </div>

<script>
var MSV       = <?= json_encode($vpPresets, JSON_UNESCAPED_SLASHES) ?>;
var MSV_ICONS = <?= json_encode($vpIcons, JSON_UNESCAPED_SLASHES) ?>;
var MSV_FONTS = <?= json_encode($vpFonts, JSON_UNESCAPED_SLASHES) ?>;
var MSV_CSRF  = <?= json_encode($csrfToken ?? '') ?>;
var msvBust   = 0;

function msvEsc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function msvName(){ return document.getElementById('msv-name').value || 'Acme Pest Control'; }

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
    return '<div class="msv-card" data-i="'+i+'" style="border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:12px;display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;">'
      + '<div style="flex:0 0 250px;">'
      +   '<img class="msv-logo" alt="logo" style="width:100%;background:#fff;border:1px solid #eee;border-radius:6px;padding:8px;min-height:56px;">'
      +   '<div style="margin-top:8px;display:flex;align-items:center;gap:8px;"><img class="msv-fav" alt="favicon" width="44" height="44" style="border-radius:9px;border:1px solid #eee;"><span class="hint">favicon</span></div>'
      + '</div>'
      + '<div style="flex:1;min-width:250px;">'
      +   '<label style="margin-top:0;">Preset name</label><input type="text" class="msv-f" data-k="name" value="'+msvEsc(p.name)+'">'
      +   '<div style="display:flex;gap:14px;margin-top:8px;">'
      +     '<div><label style="margin-top:0;">Accent</label><input type="color" class="msv-f" data-k="accent" value="'+msvEsc(p.accent)+'"></div>'
      +     '<div><label style="margin-top:0;">Dark</label><input type="color" class="msv-f" data-k="dark" value="'+msvEsc(p.dark)+'"></div>'
      +     '<div style="flex:1;"><label style="margin-top:0;">Bug icon</label><select class="msv-f" data-k="icon">'+icons+'</select></div>'
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
    });
    document.getElementById('msv-count').textContent = MSV.length + ' / 10 presets';
    document.getElementById('msv-add').disabled = MSV.length >= 10;
    msvAllPreviews();
}
function msvAdd(){
    if (MSV.length >= 10) return;
    MSV.push({name:'Preset '+(MSV.length+1), note:'', accent:'#2563eb', dark:'#1e293b',
              font:'Inter, sans-serif', radius:'6', icon:(MSV_ICONS[MSV.length % Math.max(1,MSV_ICONS.length)]||'')});
    msvRender();
}
function msvRemove(i){ if (MSV.length<=1){ alert('Keep at least one preset.'); return; } MSV.splice(i,1); msvRender(); }
function msvSave(){
    var msg = document.getElementById('msv-msg'); msg.style.color='#64748b'; msg.textContent='Saving…';
    var fd = new FormData();
    fd.append('csrf_token', MSV_CSRF);
    fd.append('presets', JSON.stringify(MSV));
    fetch('visual_presets_save.php', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(d){ if(d.ok){ msg.style.color='#059669'; msg.textContent='Saved '+d.count+' presets.'; } else { msg.style.color='#dc2626'; msg.textContent='Error: '+(d.error||'save failed'); } })
      .catch(function(){ msg.style.color='#dc2626'; msg.textContent='Network error.'; });
}
msvRender();
</script>
</div>
