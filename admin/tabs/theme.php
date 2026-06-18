    <div class="tab-content" style="<?= $tab === 'theme' ? '' : 'display:none;' ?>">
        <form action="save.php" method="post">
            <input type="hidden" name="section" value="theme">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Theme</button></div>

            <?php
            $colorGroups = [
                'Header' => [
                    'header_bg'     => 'Nav bar background color',
                    'header_top_bg' => 'Top announcement bar background',
                    'header_text'   => 'Text & menu color',
                ],
                'Main Content' => [
                    'content_bg'    => 'Background color',
                    'content_text'  => 'Text color',
                    'heading_color' => 'Heading color',
                ],
                'Footer' => [
                    'footer_bg'   => 'Background color',
                    'footer_text' => 'Text & link color',
                ],
            ];
            foreach ($colorGroups as $groupLabel => $fields):
            ?>
                <div class="card">
                    <h2><?= h($groupLabel) ?></h2>
                    <?php foreach ($fields as $key => $label):
                        $value = $theme[$key] ?? '#000000';
                    ?>
                        <div class="form-group">
                            <label for="<?= $key ?>"><?= h($label) ?></label>
                            <div class="color-field">
                                <input type="color" id="<?= $key ?>_picker" value="<?= h($value) ?>"
                                       oninput="document.getElementById('<?= $key ?>').value = this.value;">
                                <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($value) ?>"
                                       oninput="document.getElementById('<?= $key ?>_picker').value = this.value;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="card">
                <h2>Accent Color</h2>
                <div class="form-group">
                    <label for="accent_color">Accent color</label>
                    <div class="color-field">
                        <input type="color" id="accent_color_picker" value="<?= h($theme['accent_color'] ?? '#2563eb') ?>"
                               oninput="document.getElementById('accent_color').value = this.value;">
                        <input type="text" id="accent_color" name="accent_color" value="<?= h($theme['accent_color'] ?? '#2563eb') ?>"
                               oninput="document.getElementById('accent_color_picker').value = this.value;">
                    </div>
                    <span class="hint">Used for links and buttons across the header, main content, and footer.</span>
                </div>
            </div>

            <div class="card">
                <h2>Typography &amp; Buttons</h2>
                <div class="form-group">
                    <label for="primary_font">Primary font</label>
                    <select id="primary_font" name="primary_font">
                        <?php
                        $currentFont = $theme['primary_font'] ?? 'sans-serif';
                        $fonts = [
                            'sans-serif'             => 'System sans-serif (default)',
                            'Arial, sans-serif'      => 'Arial',
                            'Helvetica, sans-serif'  => 'Helvetica',
                            'Verdana, sans-serif'    => 'Verdana',
                            'Trebuchet MS, sans-serif' => 'Trebuchet MS',
                            'Georgia, serif'         => 'Georgia (serif)',
                            'serif'                  => 'System serif',
                        ];
                        foreach ($fonts as $val => $label):
                        ?>
                            <option value="<?= h($val) ?>" <?= $val === $currentFont ? 'selected' : '' ?>><?= h($label) ?></option>
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
                                   min="0.5" max="6" step="0.05"
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
