<?php
/**
 * Logo Library — icon + line1/line2 text arrangement, a rotation pool fully
 * independent of the Visual Identity color presets (see includes/multisite/
 * visual.php: ms_pick_logo_config() vs ms_pick_theme_preset(), separate salts
 * 'logo' vs 'theme'). Same card pattern as multisite_visual.php's preset
 * library: live preview, per-item "in rotation" checkbox, one "use for this
 * site" pick, debounced auto-save.
 *
 * Included by admin/tabs/genvisual.php, replacing the old one-shot
 * "Brand — Logo & Favicon" card. Colors are NOT set here — every logo always
 * renders in whichever Theme Preset colors are currently active for that
 * domain (this site's own theme for the single-site case). Reads
 * logo_configs.json + the same icons/ library the presets use; previews via
 * admin/visual_preview.php (line1/line2 params); library save via
 * admin/logo_configs_save.php; single-site apply via save.php
 * (section=apply_logo). Needs $csrfToken, $theme (from index.php).
 */
$llDoc      = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/logo_configs.json'), true) ?: [];
$llSingleId = (int)($llDoc['single_logo_id'] ?? 0);
$llLogos    = [];
foreach (($llDoc['logos'] ?? []) as $l) {
    $llLogos[] = [
        'name'        => (string)($l['name'] ?? ''),
        'icon'        => (string)($l['icon'] ?? ''),
        'icon_bg'     => ($l['icon_bg'] ?? 'dark') === 'accent' ? 'accent' : 'dark',
        'in_rotation' => array_key_exists('in_rotation', $l) ? (bool)$l['in_rotation'] : true,
    ];
}
// Line 1/2 text+color apply to the WHOLE library, one shared block — not per
// logo (changed 2026-11-10, see ms_load_logo_configs()'s docblock for why).
$llTextRaw = is_array($llDoc['text'] ?? null) ? $llDoc['text'] : [];
$llText = [
    'line1_source' => (string)($llTextRaw['line1_source'] ?? 'business'),
    'line1_custom' => (string)($llTextRaw['line1_custom'] ?? ''),
    'line1_color'  => ($llTextRaw['line1_color'] ?? 'accent') === 'dark' ? 'dark' : 'accent',
    'line2_source' => (string)($llTextRaw['line2_source'] ?? 'city'),
    'line2_custom' => (string)($llTextRaw['line2_custom'] ?? ''),
    'line2_color'  => ($llTextRaw['line2_color'] ?? 'dark') === 'accent' ? 'accent' : 'dark',
];
$llIcons = [];
foreach (glob(ACTIVE_SITE_DIR . '/multisite/icons/*.svg') ?: [] as $f) $llIcons[] = basename($f);

// Preview always renders in THIS site's actual current theme colors — the
// exact same accent/dark resolution ms_generate_logo() itself uses — never a
// separately-editable color here, since colors are deliberately the theme
// preset's job, not the Logo Config's.
$llAccent = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['accent_color'] ?? '') ? $theme['accent_color'] : '#fd783b';
$llDark   = '#120575';
foreach (['heading_color', 'footer_bg', 'header_bg'] as $f) {
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $theme[$f] ?? '')) { $llDark = $theme[$f]; break; }
}

// This site's REAL two-row header colors (top row + colored nav row), so the
// sample header below each config isn't a generic mockup — it's what THIS
// site's actual header.php would show. nav_bg is either 'accent' (follow the
// brand accent, the common case) or a stored custom hex — same resolution
// genvisual.php's own nav-bar color field uses just above this card.
$llTopBg  = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['header_top_bg'] ?? '') ? $theme['header_top_bg'] : '#ffffff';
$llNavBg  = (($header['nav_bg'] ?? 'accent') === 'accent' || ($header['nav_bg'] ?? '') === '')
    ? $llAccent
    : (preg_match('/^#[0-9a-fA-F]{6}$/', $header['nav_bg']) ? $header['nav_bg'] : $llAccent);
$llNavTxt = preg_match('/^#[0-9a-fA-F]{6}$/', $header['nav_text'] ?? '') ? $header['nav_text'] : ($theme['header_text'] ?? '#ffffff');

// This site's REAL business name/city — ms_resolve_logo_text() (the function
// the actual build calls) reads these exact site_vars keys for a 'business'/
// 'city' line source. Previewing with generic placeholders instead of them
// was a real bug: every card looked right in the abstract but never showed
// what THIS site would actually get.
$llBusiness = trim((string)($siteVars['business'] ?? '')) ?: 'Acme Pest Control';
$llCity     = trim((string)($siteVars['city']     ?? '')) ?: 'Lufkin';
?>
<div class="card" id="ll-visual">
    <h2 style="margin-top:0;">Logo Library</h2>
    <p class="hint" style="margin-bottom:6px;">Build a library of logo arrangements — each is an icon, plus one shared line&nbsp;1/line&nbsp;2 text arrangement below that applies to every config in this library. Colors always follow <em>this site's current brand colors above</em>. Then:</p>
    <ul class="hint" style="margin:0 0 12px 18px;line-height:1.7;">
        <li><strong>Use for this site</strong> — applies one config to <em>this</em> site now (regenerates the logo + favicon).</li>
        <li><strong>In multisite rotation</strong> — the multisite build rotates through the checked configs when generating clone sites (or a row's <code>logo_config</code> column overrides) — independently of which color preset each clone gets.</li>
    </ul>
    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px;background:#f8fafc;">
        <div style="font-weight:600;font-size:.9rem;margin-bottom:8px;">Line 1 / Line 2 — applies to every logo below</div>
        <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:160px;"><label style="margin-top:0;">Line 1</label><span id="ll-text-line1-select"></span></div>
            <div style="flex:1;min-width:160px;"><input type="text" id="ll-text-line1-custom" placeholder="Custom text…" style="display:none;width:100%;"></div>
            <div style="flex:0 0 100px;"><label style="margin-top:0;">Color</label><span id="ll-text-line1-color"></span></div>
        </div>
        <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;">
            <div style="flex:1;min-width:160px;"><label style="margin-top:0;">Line 2</label><span id="ll-text-line2-select"></span></div>
            <div style="flex:1;min-width:160px;"><input type="text" id="ll-text-line2-custom" placeholder="Custom text…" style="display:none;width:100%;"></div>
            <div style="flex:0 0 100px;"><label style="margin-top:0;">Color</label><span id="ll-text-line2-color"></span></div>
        </div>
    </div>
    <?php if (!$llIcons): ?>
        <p class="hint" style="color:#b45309;">No brand icons yet — add SVGs in the <strong>Brand icons</strong> card above. Until then logos render as a wordmark only.</p>
    <?php endif; ?>
    <div id="ll-list"></div>
    <div style="margin-top:6px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" id="ll-add" onclick="llAdd()">+ Add logo</button>
        <button type="button" class="btn" onclick="llSave()">Save library</button>
        <span id="ll-msg" class="hint" style="margin-left:4px;"></span>
    </div>
    <p class="hint" style="margin:8px 0 0;">Changes <strong>save automatically</strong> as you edit — the "Saved" note confirms it; <strong>Save library</strong> is just a manual re-save. To set <em>this</em> site's own logo, click <strong>Use for this site →</strong> on a config.</p>

    <!-- Real POST for the single-site apply (full reload → shows the applied logo). -->
    <form id="ll-apply-form" action="save.php" method="post" style="display:none;">
        <input type="hidden" name="section" value="apply_logo">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken ?? '') ?>">
        <input type="hidden" name="logo_id" id="ll-apply-id" value="">
    </form>

<script>
var LL        = <?= json_encode($llLogos, JSON_UNESCAPED_SLASHES) ?>;
var LL_TEXT   = <?= json_encode($llText, JSON_UNESCAPED_SLASHES) ?>;   // shared line1/2 text+color, applies to every logo below
var LL_ICONS  = <?= json_encode($llIcons, JSON_UNESCAPED_SLASHES) ?>;
var LL_TOPBG  = <?= json_encode($llTopBg) ?>;
var LL_NAVBG  = <?= json_encode($llNavBg) ?>;
var LL_NAVTXT = <?= json_encode($llNavTxt) ?>;
var LL_BIZ    = <?= json_encode($llBusiness) ?>;
var LL_CITY   = <?= json_encode($llCity) ?>;
var LL_CSRF   = <?= json_encode($csrfToken ?? '') ?>;
var LL_ACCENT = <?= json_encode($llAccent) ?>;
var LL_DARK   = <?= json_encode($llDark) ?>;
var LL_SINGLE = <?= $llSingleId > 0 ? ($llSingleId - 1) : -1 ?>;   // 0-based index of this site's logo
var llBust    = 0;

function llEsc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

function llBizWord1(){ return LL_BIZ.trim().split(/\s+/)[0] || ''; }
function llBizRest(){ return LL_BIZ.trim().split(/\s+/).slice(1).join(' '); }
function llResolveSource(source, custom){
    if (source === 'custom')         return custom;
    if (source === 'city')           return LL_CITY;
    if (source === 'business_word1') return llBizWord1();
    if (source === 'business_rest')  return llBizRest();
    return LL_BIZ;
}
function llPreviewSrc(i, type){
    var p = LL[i];
    return 'visual_preview.php?type=' + type
        + '&accent=' + encodeURIComponent(LL_ACCENT)
        + '&dark='   + encodeURIComponent(LL_DARK)
        + '&icon='   + encodeURIComponent(p.icon || '')
        + '&line1='  + encodeURIComponent(llResolveSource(LL_TEXT.line1_source, LL_TEXT.line1_custom))
        + '&line2='  + encodeURIComponent(llResolveSource(LL_TEXT.line2_source, LL_TEXT.line2_custom))
        + '&line1_color=' + encodeURIComponent(LL_TEXT.line1_color || 'accent')
        + '&line2_color=' + encodeURIComponent(LL_TEXT.line2_color || 'dark')
        + '&icon_bg='     + encodeURIComponent(p.icon_bg || 'dark')
        + '&_='      + llBust;
}
function llPreview(i){
    var card = document.querySelector('.ll-card[data-i="'+i+'"]'); if(!card) return;
    llBust++;
    var src = llPreviewSrc(i,'logo');
    card.querySelector('.ll-logo').src = src;
    card.querySelector('.ll-logo-mini').src = src;
    var fav = card.querySelector('.ll-fav');
    if (LL[i].icon){ fav.style.display=''; fav.src = llPreviewSrc(i,'favicon'); } else { fav.style.display='none'; }
}
function llAllPreviews(){ LL.forEach(function(_,i){ llPreview(i); }); }

function llSourceSelect(i, which, val){
    var opts = [['business','Business name'],['business_word1','Business name — word 1'],['business_rest','Business name — after word 1'],['city','City'],['custom','Custom text']];
    return '<select class="ll-f" data-i="'+i+'" data-k="'+which+'_source">'
        + opts.map(function(o){ return '<option value="'+o[0]+'"'+(o[0]===val?' selected':'')+'>'+o[1]+'</option>'; }).join('')
        + '</select>';
}
function llColorSelect(i, which, val){
    var opts = [['accent','Accent'],['dark','Dark']];
    return '<select class="ll-f" data-i="'+i+'" data-k="'+which+'_color">'
        + opts.map(function(o){ return '<option value="'+o[0]+'"'+(o[0]===val?' selected':'')+'>'+o[1]+'</option>'; }).join('')
        + '</select>';
}
function llIconBgSelect(i, val){
    var opts = [['dark','Dark tile, accent icon'],['accent','Accent tile, dark icon']];
    return '<select class="ll-f" data-i="'+i+'" data-k="icon_bg">'
        + opts.map(function(o){ return '<option value="'+o[0]+'"'+(o[0]===val?' selected':'')+'>'+o[1]+'</option>'; }).join('')
        + '</select>';
}
function llCardHtml(i){
    var p = LL[i];
    var icons = '<option value="">— none —</option>' + LL_ICONS.map(function(ic){ return '<option value="'+llEsc(ic)+'"'+(ic===p.icon?' selected':'')+'>'+llEsc(ic.replace(/\.svg$/,''))+'</option>'; }).join('');
    var isSingle = (LL_SINGLE === i);
    return '<div class="ll-card" data-i="'+i+'" style="position:relative;border:1px solid '+(isSingle?'#2563eb':'#e2e8f0')+';border-radius:8px;padding:14px;margin-bottom:12px;display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;'+(isSingle?'box-shadow:0 0 0 2px #dbeafe;':'')+'">'
      + '<span style="position:absolute;top:-10px;left:12px;background:#2563eb;color:#fff;border-radius:999px;min-width:22px;height:22px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;">'+(i+1)+'</span>'
      + '<div style="flex:0 0 250px;">'
      +   '<img class="ll-logo" alt="logo" style="width:100%;background:#fff;border:1px solid #eee;border-radius:6px;padding:8px;min-height:56px;">'
      +   '<div class="hint" style="margin-top:8px;margin-bottom:3px;">Sample header</div>'
      +   '<div style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;">'
      +     '<div style="background:'+llEsc(LL_TOPBG)+';padding:6px 8px;">'
      +       '<img class="ll-logo-mini" alt="logo" style="max-height:26px;max-width:100%;display:block;">'
      +     '</div>'
      +     '<div style="background:'+llEsc(LL_NAVBG)+';padding:5px 8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">'
      +       '<span style="color:'+llEsc(LL_NAVTXT)+';font-size:.68rem;font-weight:600;white-space:nowrap;">Home&nbsp;&nbsp;Services&nbsp;&nbsp;Contact</span>'
      +       '<span style="color:'+llEsc(LL_NAVTXT)+';font-size:.65rem;font-weight:700;border:1px solid currentColor;border-radius:4px;padding:2px 6px;white-space:nowrap;">Call Now</span>'
      +     '</div>'
      +   '</div>'
      +   '<div style="margin-top:8px;display:flex;align-items:center;gap:8px;"><img class="ll-fav" alt="favicon" width="44" height="44" style="border-radius:9px;border:1px solid #eee;"><span class="hint">favicon</span></div>'
      +   '<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">'
      +     (isSingle
                 ? '<div style="font-size:.85rem;color:#2563eb;font-weight:700;">★ This site’s logo</div>'
                   + '<button type="button" class="btn btn-secondary" style="padding:4px 8px;font-size:.78rem;align-self:flex-start;" onclick="llUse('+i+')" title="Re-generate the live logo + favicon from this config\'s current settings">↻ Regenerate</button>'
                 : '<button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:.85rem;align-self:flex-start;" onclick="llUse('+i+')">Use for this site →</button>')
      +     '<label style="margin:0;font-weight:400;display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.9rem;">'
      +       '<input type="checkbox" class="ll-rot" data-i="'+i+'"'+(p.in_rotation!==false?' checked':'')+'> In multisite rotation</label>'
      +   '</div>'
      + '</div>'
      + '<div style="flex:1;min-width:280px;">'
      +   '<label style="margin-top:0;">Config name</label><input type="text" class="ll-f" data-i="'+i+'" data-k="name" value="'+llEsc(p.name)+'">'
      +   '<div style="display:flex;gap:14px;margin-top:8px;">'
      +     '<div style="flex:1;"><label style="margin-top:0;">Icon</label><select class="ll-f" data-i="'+i+'" data-k="icon">'+icons+'</select></div>'
      +     '<div style="flex:1;"><label style="margin-top:0;">Icon colors</label>'+llIconBgSelect(i,p.icon_bg)+'</div>'
      +   '</div>'
      +   '<button type="button" onclick="llRemove('+i+')" style="margin-top:12px;background:none;border:0;color:#dc2626;cursor:pointer;font-size:.85rem;padding:0;">✕ Remove logo</button>'
      + '</div>'
      + '</div>';
}
function llRender(){
    document.getElementById('ll-list').innerHTML = LL.map(function(_,i){ return llCardHtml(i); }).join('');
    document.querySelectorAll('#ll-list .ll-f').forEach(function(el){
        var i = +el.getAttribute('data-i'); var k = el.getAttribute('data-k');
        var ev = (el.tagName === 'SELECT') ? 'change' : 'input';
        el.addEventListener(ev, function(){
            LL[i][k] = el.value;
            if (k !== 'name') llPreview(i);
        });
        el.addEventListener('change', llAutoSave);
    });
    document.querySelectorAll('#ll-list .ll-rot').forEach(function(el){
        el.addEventListener('change', function(){ LL[+el.getAttribute('data-i')].in_rotation = el.checked; llAutoSave(); });
    });
    document.getElementById('ll-add').disabled = LL.length >= 10;
    llAllPreviews();
}
function llAdd(){
    if (LL.length >= 10) return;
    LL.push({name:'Logo '+(LL.length+1), icon:(LL_ICONS[LL.length % Math.max(1,LL_ICONS.length)]||''), icon_bg:'dark', in_rotation:true});
    llRender();
    llAutoSave();
}
function llRemove(i){
    if (LL.length<=1){ alert('Keep at least one logo config.'); return; }
    var name = LL[i].name || 'this logo config';
    var extra = (LL_SINGLE === i) ? ' It is currently this site\'s applied logo.' : '';
    if (!confirm('Remove "'+name+'"? This cannot be undone.'+extra)) return;
    LL.splice(i,1);
    if (LL_SINGLE === i) LL_SINGLE = -1; else if (LL_SINGLE > i) LL_SINGLE--;
    llRender();
    llAutoSave();
}
function llPayload(){
    var fd = new FormData();
    fd.append('csrf_token', LL_CSRF);
    fd.append('logos', JSON.stringify(LL));
    fd.append('text', JSON.stringify(LL_TEXT));
    fd.append('single_logo_id', LL_SINGLE >= 0 ? (LL_SINGLE + 1) : 0);
    return fd;
}
// The shared Line 1/Line 2 block above the library — one control set, not one
// per logo (see LL_TEXT's own comment). Rendered once; its two selects use the
// same llSourceSelect()/llColorSelect() helpers every per-card field used to.
function llRenderText(){
    document.getElementById('ll-text-line1-select').innerHTML = llSourceSelect('text','line1',LL_TEXT.line1_source);
    document.getElementById('ll-text-line2-select').innerHTML = llSourceSelect('text','line2',LL_TEXT.line2_source);
    document.getElementById('ll-text-line1-color').innerHTML  = llColorSelect('text','line1',LL_TEXT.line1_color);
    document.getElementById('ll-text-line2-color').innerHTML  = llColorSelect('text','line2',LL_TEXT.line2_color);
    var c1 = document.getElementById('ll-text-line1-custom'), c2 = document.getElementById('ll-text-line2-custom');
    c1.value = LL_TEXT.line1_custom; c1.style.display = LL_TEXT.line1_source === 'custom' ? '' : 'none';
    c2.value = LL_TEXT.line2_custom; c2.style.display = LL_TEXT.line2_source === 'custom' ? '' : 'none';
    document.querySelectorAll('#ll-text-line1-select select, #ll-text-line2-select select, #ll-text-line1-color select, #ll-text-line2-color select')
        .forEach(function(el){
            el.addEventListener('change', function(){
                LL_TEXT[el.getAttribute('data-k')] = el.value;
                llRenderText();
                llAllPreviews();
                llAutoSave();
            });
        });
    c1.oninput = function(){ LL_TEXT.line1_custom = c1.value; llAllPreviews(); };
    c2.oninput = function(){ LL_TEXT.line2_custom = c2.value; llAllPreviews(); };
    c1.onchange = c2.onchange = llAutoSave;
}
var llSaveTimer = null;
function llAutoSave(){ clearTimeout(llSaveTimer); llSaveTimer = setTimeout(function(){ llSave(); }, 600); }
function llSave(cb){
    var msg = document.getElementById('ll-msg'); msg.style.color='#64748b'; msg.textContent='Saving…';
    fetch('logo_configs_save.php', {method:'POST', body:llPayload()})
      .then(function(r){ return r.json(); })
      .then(function(d){
          if(d.ok){ msg.style.color='#059669'; msg.textContent='Saved '+d.count+' logo configs.'; if(cb)cb(true); }
          else { msg.style.color='#dc2626'; msg.textContent='Error: '+(d.error||'save failed'); if(cb)cb(false); }
      })
      .catch(function(){ msg.style.color='#dc2626'; msg.textContent='Network error.'; if(cb)cb(false); });
}
function llUse(i){
    var p = LL[i];
    var isSingle = (LL_SINGLE === i);
    var msg = isSingle
        ? 'Regenerate this site\'s logo + favicon from this config\'s CURRENT settings?\n\nUseful after editing this config\'s icon/text/colors — editing alone only saves the config, it never refreshes the live files.'
        : 'Make "'+(p.name||'this logo')+'" this site\'s logo?\n\nThis regenerates the logo + favicon in this site\'s current colors.';
    if (!confirm(msg)) return;
    llSave(function(ok){
        if(!ok) return;
        document.getElementById('ll-apply-id').value = i + 1;
        document.getElementById('ll-apply-form').submit();
    });
}
llRenderText();
llRender();
</script>
</div>
