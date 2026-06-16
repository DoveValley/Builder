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
