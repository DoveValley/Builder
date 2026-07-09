    <div class="tab-content" style="<?= $tab === 'theme' ? '' : 'display:none;' ?>">
        <?php tab_header('Theme / Colors', 'Colors, fonts &amp; buttons for the whole site. Pick a Theme Preset to set everything at once, then fine-tune below.', 'tab-theme'); ?>

        <?php
        // Reusable color-field renderer (picker + synced text input).
        $colorField = function ($key, $label, $value, $hint = '') {
            ?>
            <div class="form-group">
                <label for="<?= h($key) ?>"><?= $label ?></label>
                <div class="color-field">
                    <input type="color" id="<?= h($key) ?>_picker" value="<?= h($value) ?>"
                           oninput="document.getElementById('<?= h($key) ?>').value=this.value;"
                           onchange="document.getElementById('<?= h($key) ?>').value=this.value;">
                    <input type="text" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($value) ?>"
                           oninput="var p=document.getElementById('<?= h($key) ?>_picker'); if(/^#[0-9a-fA-F]{6}$/.test(this.value))p.value=this.value;">
                </div>
                <?php if ($hint !== ''): ?><span class="hint"><?= $hint ?></span><?php endif; ?>
            </div>
            <?php
        };

        // This site's Theme Presets (per-niche, optional) for the picker.
        $presetData   = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/theme_presets.json'), true);
        $themePresets = is_array($presetData['presets'] ?? null) ? $presetData['presets'] : [];

        // Current header-bar color: 'accent' (follow brand) or a stored hex.
        $navBgCur   = $header['nav_bg'] ?? 'accent';
        $navIsAccent = ($navBgCur === 'accent' || $navBgCur === '');
        $navHex     = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$navBgCur) ? $navBgCur : '#fd783b';
        $navTextCur = $header['nav_text'] ?? ($theme['header_text'] ?? '#ffffff');
        ?>

        <!-- How it works -->
        <div class="card" style="background:#f8fafc;border-left:3px solid #2563eb;">
            <h2 style="margin-top:0;">How colors work here</h2>
            <p class="hint" style="margin:0;line-height:1.8;">
                <strong>① Theme Preset</strong> — one click sets everything below.<br>
                <strong>② Brand colors</strong> — the accent that buttons, links &amp; badges follow.<br>
                <strong>③ Header &amp; Footer</strong> — the bars at the very top and bottom of every page.<br>
                <strong>④ Section moods (block skins)</strong> — the 4 background looks a section can wear. Set the colors here once, then <em>pick a mood per block</em> in the block editor.
            </p>
        </div>

        <form action="save.php" method="post" id="theme-form">
            <input type="hidden" name="section" value="theme">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Theme</button></div>

            <!-- ① THEME PRESET -->
            <div class="card">
                <h2>① Theme Preset <span class="hint" style="font-weight:400;">— start here</span></h2>
                <?php if ($themePresets): ?>
                <p class="hint" style="margin-bottom:12px;">Loads a full palette (colors, font, button shape) into the fields below. Review, then <strong>Save Theme</strong>.</p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <select id="theme_preset_pick" style="min-width:220px;padding:6px;">
                        <?php foreach ($themePresets as $ti => $p): ?>
                        <option value="<?= (int)$ti ?>"><?= h($p['name'] ?? ('Preset ' . ($ti + 1))) ?><?= !empty($p['note']) ? ' — ' . h($p['note']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-secondary" onclick="applyThemePreset()">Apply preset →</button>
                    <span id="preset_applied_msg" class="hint" style="color:#059669;display:none;">Loaded — review the fields below, then Save Theme.</span>
                </div>
                <script>
                var THEME_PRESETS = <?= json_encode($themePresets, JSON_UNESCAPED_SLASHES) ?>;
                function _tpSet(id, v){ var el=document.getElementById(id); if(!el)return; el.value=v; var p=document.getElementById(id+'_picker'); if(p&&/^#[0-9a-fA-F]{6}$/.test(v))p.value=v; }
                function applyThemePreset(){
                    var p = THEME_PRESETS[document.getElementById('theme_preset_pick').value]; if(!p)return;
                    var t = p.theme || {};
                    ['footer_bg','footer_text','header_top_bg','content_bg','border_color','accent_color','accent2_color','btn_text'].forEach(function(k){ if(t[k]!=null)_tpSet(k,t[k]); });
                    if(t.button_radius!=null)_tpSet('button_radius', t.button_radius);
                    if(t.primary_font!=null)_tpSet('primary_font', t.primary_font);
                    if(t.heading_font!=null)_tpSet('heading_font', t.heading_font);
                    if(t.header_text!=null)_tpSet('nav_text', t.header_text);   // bar text mirrors header text
                    if(t.skins){ for(var sk in t.skins){ for(var pr in t.skins[sk]){ _tpSet('skin_'+sk+'_'+pr, t.skins[sk][pr]); if(window.updateSkinSwatch)updateSkinSwatch(sk); } } }
                    var nb = (p.header||{}).nav_bg || 'accent';
                    var mode = (nb==='accent'||nb==='') ? 'accent' : 'custom';
                    var r = document.querySelector('input[name=nav_bg_mode][value='+mode+']'); if(r){ r.checked=true; }
                    if(mode==='custom')_tpSet('nav_bg_custom', nb);
                    navBgModeToggle();
                    document.getElementById('preset_applied_msg').style.display='inline';
                }
                </script>
                <?php else: ?>
                <p class="hint" style="margin:0;">No presets defined for this site's niche yet. Theme Presets live in <code>sites/{site}/multisite/theme_presets.json</code> — they give each generated site a distinct palette.</p>
                <?php endif; ?>
            </div>

            <!-- ② BRAND COLORS -->
            <div class="card">
                <h2>② Brand colors</h2>
                <p class="hint" style="margin-bottom:14px;">Your signature colors. Buttons, links, badges and icons all follow the accent.</p>
                <?php
                $brandFields = [
                    'accent_color'  => ['Primary accent', 'Drives links, buttons, icon backgrounds, badges, and the Accent section mood — all from one color.', '#2563eb'],
                    'accent2_color' => ['Highlight',       'A contrasting color for standout words or decorative bits. Use as <code>var(--color-highlight)</code>.', '#f5a623'],
                    'btn_text'      => ['Button text',     'Almost always white. Change only if your accent is light enough that white text is unreadable.', '#ffffff'],
                ];
                foreach ($brandFields as $key => [$label, $hint, $def]) $colorField($key, $label, $theme[$key] ?? $def, $hint);
                ?>
            </div>

            <!-- Brand assets: generated logo + favicon (palette -> assets cascade) -->
            <div class="card">
                <h2>Brand — Logo &amp; Favicon</h2>
                <p class="hint" style="margin-bottom:12px;">
                    Generate a two-tone wordmark logo + a monogram favicon from your business name
                    (<code><?= h($data['site_vars']['business'] ?? '(set business name in Header)') ?></code>)
                    in the palette above. Change colors, then click Generate — it saves the palette <em>and</em> regenerates both.
                    (Prefer your own artwork? Upload it on the <strong>Header</strong> tab instead.)
                </p>
                <div style="display:flex;gap:24px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
                    <div>
                        <div class="hint" style="margin-bottom:4px;">Current logo</div>
                        <?php if (!empty($data['header']['logo'])): ?>
                            <img src="../<?= h($data['header']['logo']) ?>?v=<?= time() ?>" alt="logo" style="max-height:52px;max-width:300px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px;" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'hint',textContent:'(set — preview unavailable here)'}))">
                        <?php else: ?><span class="hint">none yet</span><?php endif; ?>
                    </div>
                    <div>
                        <div class="hint" style="margin-bottom:4px;">Favicon</div>
                        <?php if (!empty($data['header']['favicon'])): ?>
                            <img src="../<?= h($data['header']['favicon']) ?>?v=<?= time() ?>" alt="favicon" style="width:44px;height:44px;border:1px solid #e5e7eb;border-radius:6px;" onerror="this.style.display='none'">
                        <?php else: ?><span class="hint">none yet</span><?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn" onclick="var f=document.getElementById('theme-form'); f.querySelector('[name=section]').value='generate_brand'; f.submit();">
                    &#9881; Generate logo &amp; favicon from name + colors
                </button>
                <span class="hint" style="display:block;margin-top:6px;">Needs ImageMagick on the server. The wordmark uses the first word in the accent color and the rest in the heading color.</span>
            </div>

            <!-- ③ HEADER & FOOTER -->
            <div class="card">
                <h2>③ Header &amp; Footer</h2>
                <p class="hint" style="margin-bottom:14px;">The colored bars at the very top and bottom of every page.</p>

                <div class="form-group">
                    <label>Header bar color</label>
                    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
                        <label style="font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="radio" name="nav_bg_mode" value="accent" <?= $navIsAccent ? 'checked' : '' ?> onchange="navBgModeToggle()"> Match brand accent
                        </label>
                        <label style="font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="radio" name="nav_bg_mode" value="custom" <?= $navIsAccent ? '' : 'checked' ?> onchange="navBgModeToggle()"> Custom
                        </label>
                        <span id="nav_bg_custom_wrap" class="color-field" style="<?= $navIsAccent ? 'display:none;' : '' ?>">
                            <input type="color" id="nav_bg_custom_picker" value="<?= h($navHex) ?>"
                                   oninput="document.getElementById('nav_bg_custom').value=this.value;"
                                   onchange="document.getElementById('nav_bg_custom').value=this.value;">
                            <input type="text" id="nav_bg_custom" name="nav_bg_custom" value="<?= h($navHex) ?>"
                                   oninput="var p=document.getElementById('nav_bg_custom_picker'); if(/^#[0-9a-fA-F]{6}$/.test(this.value))p.value=this.value;">
                        </span>
                    </div>
                    <span class="hint">The nav bar and the sticky “call now” bar. “Match brand accent” keeps them on-brand automatically.</span>
                </div>

                <?php $colorField('nav_text', 'Header bar text', $navTextCur, 'Menu links and the phone button in the header bar.'); ?>
                <?php $colorField('header_top_bg', 'Top announcement bar', $theme['header_top_bg'] ?? '#ffffff', 'The thin strip above the nav bar (often white).'); ?>
                <hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0;">
                <?php $colorField('footer_bg', 'Footer background', $theme['footer_bg'] ?? '#120575', 'The large footer block at the bottom of every page.'); ?>
                <?php $colorField('footer_text', 'Footer text', $theme['footer_text'] ?? '#ffffff', 'Text and links inside the footer.'); ?>
            </div>

            <!-- ④ SECTION MOODS (BLOCK SKINS) -->
            <div class="card" style="background:#f8fafc;border-left:3px solid #64748b;">
                <h2 style="margin-top:0;">④ Section moods <span class="hint" style="font-weight:400;">— block skins</span></h2>
                <p class="hint" style="margin:0;">Every content section wears one of these four moods. Set the colors here <strong>once</strong>; choose which mood a section uses with the <strong>Block skin</strong> picker on each block. The <strong>Light</strong> mood’s heading color is also your default heading color site-wide.</p>
            </div>
            <?php
            $skinDefs = [
                'light'  => ['Light',  'White background — standard content sections. Its heading color = your site-wide heading color.', ['bg'=>'#ffffff','heading'=>'#1a2e5a','text'=>'#555e6d'], ['bg','heading','text']],
                'subtle' => ['Subtle', 'Off-white — soft alternating sections.',              ['bg'=>'#f8fafc','heading'=>'#1a2e5a','text'=>'#555e6d'], ['bg','heading','text']],
                'accent' => ['Accent', 'Brand color background — CTA and featured sections.', ['bg'=>'#2563eb','heading'=>'#ffffff', 'text'=>'#dbeafe'], ['heading','text']],
                'dark'   => ['Dark',   'Dark background — hero and dramatic sections.',        ['bg'=>'#0d1f3c','heading'=>'#ffffff', 'text'=>'#e2e8f0'], ['bg','heading','text']],
            ];
            $skinFieldLabels = ['bg'=>'Background','heading'=>'Heading text','text'=>'Body text'];
            foreach ($skinDefs as $skinKey => [$skinLabel, $skinHint, $skinDefaults, $editableProps]):
                $skinData = $theme['skins'][$skinKey] ?? $skinDefaults;
            ?>
            <div class="card">
                <h2>Block Skin: <?= $skinLabel ?></h2>
                <p class="hint" style="margin-bottom:14px;"><?= $skinHint ?> Pick this block skin on any block from the <strong>Block skin</strong> picker.</p>
                <?php if ($skinKey === 'accent'): ?>
                <p class="hint" style="margin-bottom:14px;color:#2563eb;">Background automatically follows <strong>Primary accent</strong> above — no separate field needed.</p>
                <?php endif; ?>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                    <div class="skin-swatch skin-swatch-<?= $skinKey ?>" style="width:56px;height:56px;border-radius:8px;border:1px solid rgba(0,0,0,.12);flex-shrink:0;margin-top:4px;"></div>
                    <div style="flex:1;min-width:260px;">
                        <?php foreach ($editableProps as $prop):
                            $fkey  = "skin_{$skinKey}_{$prop}";
                            $fval  = $skinData[$prop] ?? $skinDefaults[$prop];
                        ?>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label for="<?= $fkey ?>" style="font-size:0.82rem;"><?= $skinFieldLabels[$prop] ?></label>
                            <div class="color-field">
                                <input type="color" id="<?= $fkey ?>_picker" value="<?= h($fval) ?>"
                                       oninput="document.getElementById('<?= $fkey ?>').value=this.value;updateSkinSwatch('<?= $skinKey ?>');"
                                       onchange="document.getElementById('<?= $fkey ?>').value=this.value;updateSkinSwatch('<?= $skinKey ?>');">
                                <input type="text"  id="<?= $fkey ?>" name="<?= $fkey ?>" value="<?= h($fval) ?>"
                                       oninput="document.getElementById('<?= $fkey ?>_picker').value=this.value;updateSkinSwatch('<?= $skinKey ?>');">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- ⑤ PAGE BACKGROUND & BORDERS -->
            <div class="card">
                <h2>⑤ Page background &amp; borders</h2>
                <p class="hint" style="margin-bottom:14px;">Rarely changed — the base page color behind all sections, and structural divider lines.</p>
                <?php $colorField('content_bg', 'Page background', $theme['content_bg'] ?? '#ffffff', 'The base background behind every section. Usually white.'); ?>
                <?php $colorField('border_color', 'Border / divider', $theme['border_color'] ?? '#e5e7eb', 'Footer divider lines and structural borders. Use as <code>var(--color-border)</code>.'); ?>
            </div>

            <!-- ⑥ TYPOGRAPHY & BUTTONS -->
            <div class="card">
                <h2>⑥ Typography &amp; Buttons</h2>
                <div class="form-group">
                    <label for="primary_font">Body / nav font</label>
                    <select id="primary_font" name="primary_font">
                        <?php
                        $currentFont = $theme['primary_font'] ?? 'sans-serif';
                        $fonts = [
                            '— System fonts —'         => null,
                            'sans-serif'               => 'System sans-serif (default)',
                            'Arial, sans-serif'        => 'Arial',
                            'Helvetica, sans-serif'    => 'Helvetica',
                            'Verdana, sans-serif'      => 'Verdana',
                            'Trebuchet MS, sans-serif' => 'Trebuchet MS',
                            'Georgia, serif'           => 'Georgia (serif)',
                            'serif'                    => 'System serif',
                            '— Google Fonts (sans-serif) —' => null,
                            'Open Sans, sans-serif'    => 'Open Sans ★',
                            'Roboto, sans-serif'       => 'Roboto ★',
                            'Lato, sans-serif'         => 'Lato',
                            'Montserrat, sans-serif'   => 'Montserrat ★',
                            'Raleway, sans-serif'      => 'Raleway',
                            'Poppins, sans-serif'      => 'Poppins ★',
                            'Nunito, sans-serif'       => 'Nunito',
                            'Mulish, sans-serif'       => 'Mulish',
                            'Inter, sans-serif'        => 'Inter ★',
                            'Inclusive Sans, sans-serif' => 'Inclusive Sans',
                            '— Google Fonts (serif) —' => null,
                            'Noto Serif, serif'        => 'Noto Serif ★',
                            'Playfair Display, serif'  => 'Playfair Display',
                            'Merriweather, serif'      => 'Merriweather',
                        ];
                        foreach ($fonts as $val => $label):
                            if ($label === null): ?>
                            <optgroup label="<?= h($val) ?>"></optgroup>
                            <?php continue; endif;
                        ?>
                            <option value="<?= h($val) ?>" <?= $val === $currentFont ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="heading_font">Heading font <span class="hint">(leave blank to use same as body)</span></label>
                    <select id="heading_font" name="heading_font">
                        <?php
                        $currentHFont = $theme['heading_font'] ?? '';
                        $hfonts = [
                            ''                         => '— Same as body font —',
                            '— System fonts —'         => null,
                            'serif'                    => 'System serif',
                            'Georgia, serif'           => 'Georgia',
                            'sans-serif'               => 'System sans-serif',
                            '— Google Fonts (serif) —' => null,
                            'Noto Serif, serif'        => 'Noto Serif ★',
                            'Playfair Display, serif'  => 'Playfair Display',
                            'Merriweather, serif'      => 'Merriweather',
                            '— Google Fonts (sans-serif) —' => null,
                            'Open Sans, sans-serif'    => 'Open Sans ★',
                            'Roboto, sans-serif'       => 'Roboto ★',
                            'Montserrat, sans-serif'   => 'Montserrat ★',
                            'Poppins, sans-serif'      => 'Poppins ★',
                            'Inter, sans-serif'        => 'Inter ★',
                        ];
                        foreach ($hfonts as $val => $label):
                            if ($label === null): ?>
                            <optgroup label="<?= h($val) ?>"></optgroup>
                            <?php continue; endif;
                        ?>
                            <option value="<?= h($val) ?>" <?= $val === $currentHFont ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="font_size_body">Body / paragraph size</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" id="font_size_body" name="font_size_body" min="12" max="24" step="1"
                               value="<?= h($theme['font_size_body'] ?? '16') ?>" style="width:80px;">
                        <span class="hint">px &nbsp;(default 16)</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Heading sizes (rem)</label>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
                        <?php foreach (['h1'=>['H1','2.5'],'h2'=>['H2','2'],'h3'=>['H3','1.75'],'h4'=>['H4','1.5']] as $tag=>[$lbl,$def]): ?>
                        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                            <label for="font_size_<?= $tag ?>" style="font-weight:600;font-size:0.85rem;margin:0;"><?= $lbl ?></label>
                            <input type="number" id="font_size_<?= $tag ?>" name="font_size_<?= $tag ?>"
                                   min="0.5" max="6" step="0.01"
                                   value="<?= h($theme['font_size_'.$tag] ?? $def) ?>"
                                   style="width:72px;">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="hint" style="margin-top:6px;display:block;">rem units relative to body size. H1 default 2.5, H2 2, H3 1.75, H4 1.5.</span>
                </div>
                <div class="form-group">
                    <label for="button_radius">Button corner radius</label>
                    <input type="number" id="button_radius" name="button_radius" min="0" max="50"
                           value="<?= h($theme['button_radius'] ?? '4') ?>" style="width:90px;">
                    <span class="hint">Pixels. 0 = square corners, 4 = slightly rounded, 24+ = pill shape.</span>
                </div>
            </div>

            <!-- ⑦ TRACKING -->
            <div class="card">
                <h2>⑦ Analytics &amp; Tracking</h2>
                <p class="hint" style="margin-bottom:14px;">Paste your tracking code here. It will be added to the <code>&lt;head&gt;</code> of every page automatically.</p>
                <div class="form-group">
                    <label for="analytics_head">Google Analytics / GA4 snippet</label>
                    <textarea id="analytics_head" name="analytics_head" rows="5"
                              style="font-family:monospace;font-size:0.82rem;"><?= h($theme['analytics_head'] ?? '') ?></textarea>
                    <span class="hint">Paste the full <code>&lt;script&gt;...&lt;/script&gt;</code> block from Google Analytics or Tag Manager.</span>
                </div>
                <div class="form-group">
                    <label for="facebook_pixel">Facebook Pixel / Meta Pixel snippet</label>
                    <textarea id="facebook_pixel" name="facebook_pixel" rows="5"
                              style="font-family:monospace;font-size:0.82rem;"><?= h($theme['facebook_pixel'] ?? '') ?></textarea>
                    <span class="hint">Paste the full Pixel base code here.</span>
                </div>
            </div>

            <script>
            function updateSkinSwatch(skinKey) {
                var bg = document.getElementById('skin_'+skinKey+'_bg')?.value || '';
                document.querySelectorAll('.skin-swatch-'+skinKey).forEach(function(el) { el.style.background = bg; });
            }
            function navBgModeToggle() {
                var custom = document.querySelector('input[name=nav_bg_mode][value=custom]')?.checked;
                var wrap = document.getElementById('nav_bg_custom_wrap');
                if (wrap) wrap.style.display = custom ? '' : 'none';
            }
            // Sync all color pickers to their text inputs before submit.
            document.getElementById('theme-form').addEventListener('submit', function() {
                this.querySelectorAll('input[type="color"]').forEach(function(picker) {
                    var textInput = document.getElementById(picker.id.replace(/_picker$/, ''));
                    if (textInput) textInput.value = picker.value;
                });
            });
            </script>

            <button type="submit" class="btn">Save Theme</button>
        </form>

        <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Reset all colors to the default white backgrounds with black text?');">
            <input type="hidden" name="section" value="theme_reset">
            <button type="submit" class="btn btn-secondary">Reset to Defaults</button>
        </form>
    </div>
