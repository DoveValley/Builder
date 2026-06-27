    <div class="tab-content" style="<?= $tab === 'theme' ? '' : 'display:none;' ?>">
        <?php tab_header('Theme / Colors', 'Set global colors, fonts, and button styles. Changes here update the entire site — blocks reference these values using keywords like "accent" or "header".', 'tab-theme'); ?>
        <form action="save.php" method="post" id="theme-form">
            <input type="hidden" name="section" value="theme">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Theme</button></div>

            <?php
            $colorGroups = [
                'Header' => [
                    'header_bg'     => 'Nav bar background color',
                    'header_top_bg' => 'Top announcement bar background',
                    'header_text'   => 'Text & menu color',
                ],
                'Site Defaults' => [
                    'content_bg'   => 'Page background color',
                    'border_color' => 'Border / divider color',
                ],
                'Footer' => [
                    'footer_bg'   => 'Background color',
                    'footer_text' => 'Text & link color',
                ],
            ];
            $colorHints = [
                'content_bg'   => 'The base background of the page itself (behind all blocks). Usually white.',
                'border_color' => 'Footer divider lines and structural borders. Use as <code>var(--color-border)</code> in custom HTML.',
            ];
            $colorDefaults = [
                'content_bg'   => '#ffffff',
                'border_color' => '#e5e7eb',
                'header_bg'    => '#120575',
                'header_top_bg'=> '#ffffff',
                'header_text'  => '#ffffff',
                'footer_bg'    => '#120575',
                'footer_text'  => '#ffffff',
            ];
            foreach ($colorGroups as $groupLabel => $fields):
            ?>
                <div class="card">
                    <h2><?= h($groupLabel) ?></h2>
                    <?php foreach ($fields as $key => $label):
                        $value = $theme[$key] ?? ($colorDefaults[$key] ?? '#000000');
                    ?>
                        <div class="form-group">
                            <label for="<?= $key ?>"><?= h($label) ?></label>
                            <div class="color-field">
                                <input type="color" id="<?= $key ?>_picker" value="<?= h($value) ?>"
                                       oninput="document.getElementById('<?= $key ?>').value=this.value;"
                                       onchange="document.getElementById('<?= $key ?>').value=this.value;">
                                <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($value) ?>"
                                       oninput="document.getElementById('<?= $key ?>_picker').value=this.value;">
                            </div>
                            <?php if (isset($colorHints[$key])): ?>
                            <span class="hint"><?= $colorHints[$key] ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="card">
                <h2>Accent &amp; Buttons</h2>
                <?php
                $accentFields = [
                    'accent_color'  => ['Primary accent',   'Drives links, icon backgrounds, badges, buttons, and the Accent skin background — all from one color.', '#2563eb'],
                    'accent2_color' => ['Highlight color',  'A contrasting brand color for standout words, badges, or decorative elements. Use as <code>var(--color-highlight)</code> in custom HTML.', '#f5a623'],
                    'btn_text'      => ['Button text color', 'Almost always white. Change only if your accent color is light enough that white text is unreadable.', '#ffffff'],
                ];
                foreach ($accentFields as $key => [$label, $hint, $default]):
                    $value = $theme[$key] ?? $default;
                ?>
                <div class="form-group">
                    <label for="<?= $key ?>"><?= $label ?></label>
                    <div class="color-field">
                        <input type="color" id="<?= $key ?>_picker" value="<?= h($value) ?>"
                               oninput="document.getElementById('<?= $key ?>').value=this.value;"
                               onchange="document.getElementById('<?= $key ?>').value=this.value;">
                        <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($value) ?>"
                               oninput="document.getElementById('<?= $key ?>_picker').value=this.value;">
                    </div>
                    <span class="hint"><?= $hint ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php
            $skinDefs = [
                'light'  => ['Light',  'White background — standard content sections.',       ['bg'=>'#ffffff','heading'=>'#1a2e5a','text'=>'#555e6d'], ['bg','heading','text']],
                'subtle' => ['Subtle', 'Off-white — soft alternating sections.',              ['bg'=>'#f8fafc','heading'=>'#1a2e5a','text'=>'#555e6d'], ['bg','heading','text']],
                'accent' => ['Accent', 'Brand color background — CTA and featured sections.', ['bg'=>'#2563eb','heading'=>'#ffffff', 'text'=>'#dbeafe'], ['heading','text']],
                'dark'   => ['Dark',   'Dark background — hero and dramatic sections.',        ['bg'=>'#0d1f3c','heading'=>'#ffffff', 'text'=>'#e2e8f0'], ['bg','heading','text']],
            ];
            $skinFieldLabels = ['bg'=>'Background','heading'=>'Heading text','text'=>'Body text'];
            foreach ($skinDefs as $skinKey => [$skinLabel, $skinHint, $skinDefaults, $editableProps]):
                $skinData = $theme['skins'][$skinKey] ?? $skinDefaults;
            ?>
            <div class="card">
                <h2>Skin: <?= $skinLabel ?></h2>
                <p class="hint" style="margin-bottom:14px;"><?= $skinHint ?> Pick this skin on any block from the <strong>Section skin</strong> picker.</p>
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

            <script>
            function updateSkinSwatch(skinKey) {
                var bg = document.getElementById('skin_'+skinKey+'_bg')?.value || '';
                document.querySelectorAll('.skin-swatch-'+skinKey).forEach(function(el) {
                    el.style.background = bg;
                });
            }
            // Ensure all color picker values are synced to their text inputs before submit.
            // Handles browsers that fire 'change' late or not at all during picker interaction.
            document.getElementById('theme-form').addEventListener('submit', function() {
                this.querySelectorAll('input[type="color"]').forEach(function(picker) {
                    var textId = picker.id.replace(/_picker$/, '');
                    var textInput = document.getElementById(textId);
                    if (textInput) textInput.value = picker.value;
                });
            });
            </script>

            <div class="card">
                <h2>Typography &amp; Buttons</h2>
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

            <div class="card">
                <h2>Analytics &amp; Tracking</h2>
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

            <button type="submit" class="btn">Save Theme</button>
        </form>

        <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Reset all colors to the default white backgrounds with black text?');">
            <input type="hidden" name="section" value="theme_reset">
            <button type="submit" class="btn btn-secondary">Reset to Defaults</button>
        </form>
    </div>
