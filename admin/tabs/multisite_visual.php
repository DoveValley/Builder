<?php
/**
 * Color Presets & Fonts library (the "factory"). One per-site panel that:
 *   • creates/edits up to 10 presets (colors + font + button radius),
 *     each with a live logo + favicon preview — using THIS SITE'S REAL active
 *     Logo Config for icon/text, so the preview matches reality (see the
 *     Logo Library, admin/tabs/logo_library.php, for the icon itself),
 *   • lets you pick ONE preset as THIS site's brand ("Use for this site" →
 *     applies colors, optionally font/buttons, and regenerates logo + favicon),
 *   • flags which presets the multisite build rotates through when generating clones.
 *
 * Included by admin/tabs/genvisual.php (was theme.php until that tab was renamed
 * Gen-Visual and moved next to Gen-Image). Reads theme_presets.json +
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
        'in_rotation' => array_key_exists('in_rotation', $p) ? (bool)$p['in_rotation'] : true,
    ];
}
$vpBusiness = trim($data['site_vars']['business'] ?? '') ?: 'Acme Company';

// Every card's preview below renders THIS SITE'S REAL active Logo Config
// (icon, icon background, line text sources/colors) — only accent/dark swap
// per card — so a preset preview is a true "what would my actual logo look
// like with this preset's colors" rather than a separate cosmetic guess built
// from the preset's own (otherwise unused) `icon` field. See admin/tabs/
// logo_library.php for the config this reads and ms_resolve_logo_lines() for
// the same resolution the real build performs.
require_once BASE_DIR . '/includes/multisite/visual.php';
$vpLogoDoc      = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/logo_configs.json'), true) ?: [];
$vpLogoConfigs  = is_array($vpLogoDoc['logos'] ?? null) ? $vpLogoDoc['logos'] : [];
$vpSingleLogoId = (int)($vpLogoDoc['single_logo_id'] ?? 0);
$vpLogoConfig   = null;
foreach ($vpLogoConfigs as $idx => $l) {
    if ((int)($l['id'] ?? ($idx + 1)) === $vpSingleLogoId) { $vpLogoConfig = $l; break; }
}
$vpActiveIcon     = trim((string)($vpLogoConfig['icon'] ?? ''));
$vpActiveIconBg   = ($vpLogoConfig['icon_bg'] ?? 'dark') === 'accent' ? 'accent' : 'dark';
$vpLine1Source    = (string)($vpLogoConfig['line1_source'] ?? 'business');
$vpLine2Source    = (string)($vpLogoConfig['line2_source'] ?? 'city');
$vpLine1Custom    = (string)($vpLogoConfig['line1_custom'] ?? '');
$vpLine2Custom    = (string)($vpLogoConfig['line2_custom'] ?? '');
$vpLine1ColorMode = ($vpLogoConfig['line1_color'] ?? 'accent') === 'dark' ? 'dark' : 'accent';
$vpLine2ColorMode = ($vpLogoConfig['line2_color'] ?? 'dark') === 'accent' ? 'accent' : 'dark';
$vpCity = trim((string)($data['site_vars']['city'] ?? '')) ?: 'Lufkin';
$vpFonts = ['Inclusive Sans, sans-serif','Inter, sans-serif','Nunito, sans-serif','Poppins, sans-serif',
            'Montserrat, sans-serif','Roboto, sans-serif','Open Sans, sans-serif','Lato, sans-serif',
            'Raleway, sans-serif','Mulish, sans-serif','Playfair Display, serif','Merriweather, serif'];
?>
<div class="card" id="ms-visual">
    <h2 style="margin-top:0;">Color Presets &amp; Fonts</h2>
    <p class="hint" style="margin-bottom:6px;">Build a library of color/font/button combinations. Each drives a generated <strong>logo + favicon</strong> — using <em>this site's actual Logo Library arrangement</em> (icon, line text, line colors, below), just with that preset's colors swapped in — so what you see here is what applying it would really look like. Then:</p>
    <ul class="hint" style="margin:0 0 12px 18px;line-height:1.7;">
        <li><strong>Use for this site</strong> — applies one preset to <em>this</em> site now (theme + logo + favicon).</li>
        <li><strong>In multisite rotation</strong> — the multisite build rotates through the checked presets when generating clone sites (or a row's <code>theme_preset</code> column overrides).</li>
    </ul>
    <div style="display:flex;gap:10px;align-items:center;margin:12px 0 16px;flex-wrap:wrap;">
        <label for="msv-name" style="margin:0;font-weight:600;">Preview business name:</label>
        <input type="text" id="msv-name" value="<?= h($vpBusiness) ?>" style="width:220px;" oninput="msvAllPreviews()">
        <span class="hint">only shows up in a line if your <a href="#ll-visual">Logo Library</a> config actually uses "Business name" there</span>
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
var MSV_FONTS  = <?= json_encode($vpFonts, JSON_UNESCAPED_SLASHES) ?>;
var MSV_CSRF   = <?= json_encode($csrfToken ?? '') ?>;
var MSV_SINGLE = <?= $vpSingleId > 0 ? ($vpSingleId - 1) : -1 ?>;   // 0-based index of this site's brand
var msvBust    = 0;

// This site's REAL active Logo Config — the same one every preview card below
// renders, so only the accent/dark colors vary per card (see the PHP block
// above that resolves these from logo_configs.json, mirroring what the real
// build does via ms_resolve_logo_lines()).
var MSV_ICON        = <?= json_encode($vpActiveIcon) ?>;
var MSV_ICON_BG     = <?= json_encode($vpActiveIconBg) ?>;
var MSV_LINE1_SRC   = <?= json_encode($vpLine1Source) ?>;
var MSV_LINE2_SRC   = <?= json_encode($vpLine2Source) ?>;
var MSV_LINE1_CUST  = <?= json_encode($vpLine1Custom) ?>;
var MSV_LINE2_CUST  = <?= json_encode($vpLine2Custom) ?>;
var MSV_LINE1_COLOR = <?= json_encode($vpLine1ColorMode) ?>;
var MSV_LINE2_COLOR = <?= json_encode($vpLine2ColorMode) ?>;
var MSV_CITY        = <?= json_encode($vpCity) ?>;

function msvEsc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function msvName(){ return document.getElementById('msv-name').value || 'Acme Company'; }
function msvBizWord1(){ return msvName().trim().split(/\s+/)[0] || ''; }
function msvBizRest(){ return msvName().trim().split(/\s+/).slice(1).join(' '); }
function msvResolveLine(source, custom){
    if (source === 'custom')         return custom;
    if (source === 'city')           return MSV_CITY;
    if (source === 'business_word1') return msvBizWord1();
    if (source === 'business_rest')  return msvBizRest();
    return msvName();
}

function msvPreviewSrc(i, type){
    var p = MSV[i];
    return 'visual_preview.php?type=' + type
        + '&accent=' + encodeURIComponent(p.accent)
        + '&dark='   + encodeURIComponent(p.dark)
        + '&icon='   + encodeURIComponent(MSV_ICON || '')
        + '&line1='  + encodeURIComponent(msvResolveLine(MSV_LINE1_SRC, MSV_LINE1_CUST))
        + '&line2='  + encodeURIComponent(msvResolveLine(MSV_LINE2_SRC, MSV_LINE2_CUST))
        + '&line1_color=' + encodeURIComponent(MSV_LINE1_COLOR)
        + '&line2_color=' + encodeURIComponent(MSV_LINE2_COLOR)
        + '&icon_bg='      + encodeURIComponent(MSV_ICON_BG)
        + '&_='      + msvBust;
}
function msvPreview(i){
    var card = document.querySelector('.msv-card[data-i="'+i+'"]'); if(!card) return;
    msvBust++;
    card.querySelector('.msv-logo').src = msvPreviewSrc(i,'logo');
    var fav = card.querySelector('.msv-fav');
    if (MSV_ICON){ fav.style.display=''; fav.src = msvPreviewSrc(i,'favicon'); } else { fav.style.display='none'; }
}
function msvAllPreviews(){ MSV.forEach(function(_,i){ msvPreview(i); }); }

// Keeps a preset's Accent/Dark color picker and its visible hex text in sync — the
// picker alone never shows the hex, and pasting a palette from a design tool needs the
// text field to type/paste into directly, same pattern as the paired color fields
// elsewhere on this tab (genvisual.php's $colorField()).
function msvColorSync(i, key, fromColor, value){
    var cId = 'msv-'+key+'-c-'+i, tId = 'msv-'+key+'-t-'+i;
    if (fromColor) {
        document.getElementById(tId).value = value;
    } else if (/^#[0-9a-fA-F]{6}$/.test(value)) {
        document.getElementById(cId).value = value;
    } else {
        return; // partial/invalid hex typed so far — don't commit yet
    }
    MSV[i][key] = value;
    msvPreview(i);
    msvAutoSave();
}

function msvCardHtml(i){
    var p = MSV[i];
    var fonts = MSV_FONTS.map(function(f){ return '<option value="'+msvEsc(f)+'"'+(f===p.font?' selected':'')+'>'+msvEsc(f.split(',')[0])+'</option>'; }).join('');
    if (MSV_FONTS.indexOf(p.font)<0) fonts = '<option value="'+msvEsc(p.font)+'" selected>'+msvEsc(p.font.split(',')[0])+'</option>' + fonts;
    var isSingle = (MSV_SINGLE === i);
    return '<div class="msv-card" data-i="'+i+'" style="position:relative;border:1px solid '+(isSingle?'#2563eb':'#e2e8f0')+';border-radius:8px;padding:14px;margin-bottom:12px;display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;'+(isSingle?'box-shadow:0 0 0 2px #dbeafe;':'')+'">'
      + '<span style="position:absolute;top:-10px;left:12px;background:#2563eb;color:#fff;border-radius:999px;min-width:22px;height:22px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;">'+(i+1)+'</span>'
      + '<div style="flex:0 0 250px;">'
      +   '<img class="msv-logo" alt="logo" style="width:100%;background:#fff;border:1px solid #eee;border-radius:6px;padding:8px;min-height:56px;">'
      +   '<div style="margin-top:8px;display:flex;align-items:center;gap:8px;"><img class="msv-fav" alt="favicon" width="44" height="44" style="border-radius:9px;border:1px solid #eee;"><span class="hint">favicon</span></div>'
      +   '<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">'
      +     (isSingle
                 ? '<div style="font-size:.85rem;color:#2563eb;font-weight:700;">★ This site’s brand</div>'
                   + '<button type="button" class="btn btn-secondary" style="padding:4px 8px;font-size:.78rem;align-self:flex-start;" onclick="msvUse('+i+')" title="Re-apply this preset\'s current colors/font and regenerate the live logo + favicon">↻ Regenerate</button>'
                 : '<button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:.85rem;align-self:flex-start;" onclick="msvUse('+i+')">Use for this site →</button>')
      +     '<label style="margin:0;font-weight:400;display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.9rem;">'
      +       '<input type="checkbox" class="msv-rot" data-i="'+i+'"'+(p.in_rotation!==false?' checked':'')+'> In multisite rotation</label>'
      +   '</div>'
      + '</div>'
      + '<div style="flex:1;min-width:250px;">'
      +   '<label style="margin-top:0;">Preset name</label><input type="text" class="msv-f" data-k="name" value="'+msvEsc(p.name)+'">'
      +   '<div style="display:flex;gap:14px;margin-top:8px;">'
      +     '<div><label style="margin-top:0;">Accent</label><div class="color-field">'
      +       '<input type="color" id="msv-accent-c-'+i+'" value="'+msvEsc(p.accent)+'" oninput="msvColorSync('+i+',\'accent\',true,this.value)">'
      +       '<input type="text" id="msv-accent-t-'+i+'" value="'+msvEsc(p.accent)+'" style="width:82px;font-family:monospace;" oninput="msvColorSync('+i+',\'accent\',false,this.value)">'
      +     '</div></div>'
      +     '<div><label style="margin-top:0;">Dark</label><div class="color-field">'
      +       '<input type="color" id="msv-dark-c-'+i+'" value="'+msvEsc(p.dark)+'" oninput="msvColorSync('+i+',\'dark\',true,this.value)">'
      +       '<input type="text" id="msv-dark-t-'+i+'" value="'+msvEsc(p.dark)+'" style="width:82px;font-family:monospace;" oninput="msvColorSync('+i+',\'dark\',false,this.value)">'
      +     '</div></div>'
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
              font:'Inter, sans-serif', radius:'6', in_rotation:true});
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
    var isSingle = (MSV_SINGLE === i);
    var typo = document.getElementById('msv-typo').checked;
    var warn = isSingle
        ? 'Re-apply "'+(p.name||'this preset')+'"\'s CURRENT colors'+(typo ? ', font and button style' : '')+' and regenerate the live logo + favicon?\n\nUseful after editing this preset — editing alone only saves the library, it never refreshes the live theme/logo.'
        : 'Make "'+(p.name||'preset')+'" this site\'s brand?\n\nThis overwrites the site\'s '
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
