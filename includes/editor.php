<?php
/* ============================================================
   ADMIN: photo field helpers
   ============================================================ */
function photo_ratio_options_html($selected = 'landscape') {
    $html = '';
    foreach (photo_ratio_options() as $key => $label) {
        $sel   = ($key === $selected) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function photo_position_options_html($selected = 'center') {
    $html = '';
    foreach (photo_position_options() as $key => $label) {
        $sel   = ($key === $selected) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function heading_level_options_html($selected = 'h2') {
    $html = '';
    foreach (heading_level_options() as $key => $label) {
        $sel   = ($key === $selected) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

/* ============================================================
   ADMIN: render the full content blocks editor
   ============================================================ */
function render_content_blocks_editor($blocks) {
    $blockList = $blocks ?: [['type' => 'text', 'heading_level' => 'h2', 'text' => '', 'photo' => '']];
    ?>
    <div class="card">
        <h2>Content Blocks</h2>
        <p class="hint" style="margin-bottom:18px;">
            Build this page from any number of blocks. Choose a block type and fill in the fields.
        </p>
        <div id="content-blocks">
            <?php foreach ($blockList as $i => $block):
                $type = $block['type'] ?? 'text';
                if (!array_key_exists($type, allowed_block_types())) $type = 'text';
            ?>
            <div class="block-card" data-block-type="<?= h($type) ?>">
                <div class="block-card-header">
                    <span class="block-label">Block <?= $i + 1 ?></span>
                    <select name="block_type[]" class="block-type-select" style="display:none;" onchange="switchBlockType(this)">
                        <?php
                        $grouped = grouped_block_types();
                        $inGrouped = false;
                        foreach ($grouped as $gItems) { if (array_key_exists($type, $gItems)) { $inGrouped = true; break; } }
                        if (!$inGrouped && array_key_exists($type, allowed_block_types())):
                        ?>
                            <optgroup label="Existing (legacy)">
                                <option value="<?= h($type) ?>" selected><?= h(allowed_block_types()[$type]) ?></option>
                            </optgroup>
                        <?php endif; ?>
                        <?php foreach ($grouped as $groupLabel => $groupItems): ?>
                            <optgroup label="<?= h($groupLabel) ?>">
                                <?php foreach ($groupItems as $key => $label): ?>
                                    <option value="<?= h($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <?php $thumbs = block_thumbnails(); ?>
                    <button type="button" class="bp-trigger" onclick="openBlockPicker(this)">
                        <span class="bp-trigger-thumb"><?= $thumbs[$type] ?? $thumbs['text'] ?></span>
                        <span class="bp-trigger-label"><?= h(allowed_block_types()[$type] ?? $type) ?></span>
                        <span class="bp-trigger-arrow">&#9660;</span>
                    </button>
                    <input type="text" name="block_anchor[]"
                           value="<?= h($block['anchor'] ?? '') ?>"
                           placeholder="Section ID (e.g. pest_services)"
                           title="Anchor ID — use in menu links as #pest_services"
                           style="flex:1 1 160px;max-width:200px;font-size:0.82rem;padding:6px 10px;">
                    <div class="block-actions">
                        <button type="button" class="icon-btn" onclick="moveBlock(this,-1)" title="Move up">&uarr;</button>
                        <button type="button" class="icon-btn" onclick="moveBlock(this,1)"  title="Move down">&darr;</button>
                        <button type="button" class="icon-btn remove-row" onclick="removeBlock(this)" title="Remove">Remove</button>
                    </div>
                </div>

                <?php /* ---- TEXT ONLY FIELDS ---- */ ?>
                <div class="block-fields block-fields-text <?= $type !== 'text' ? 'is-hidden' : '' ?>">
                    <input type="hidden" name="block_photo_alt[]" value="">
                    <input type="hidden" name="block_existing_photo[]" value="">
                    <input type="hidden" name="block_photo_ratio[]" value="landscape">
                    <input type="hidden" name="block_photo_position[]" value="center">
                    <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                        <div class="form-group" style="flex:1 1 200px;">
                            <label>Heading text (optional)</label>
                            <input type="text" name="block_heading_text[]" value="<?= h($block['heading_text'] ?? '') ?>" placeholder="e.g. Why Choose Us">
                        </div>
                        <div class="form-group" style="flex:0 0 180px;">
                            <label>Heading level</label>
                            <select name="block_heading_level[]">
                                <?= heading_level_options_html($block['heading_level'] ?? 'h2') ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Body text</label>
                        <textarea name="block_text[]" rows="5" class="rich-editor"><?= $block['text'] ?? '' ?></textarea>
                    </div>
                </div>

                <?php /* ---- IMAGE LEFT / RIGHT FIELDS ---- */ ?>
                <div class="block-fields block-fields-image_left block-fields-image_right <?= !in_array($type, ['image_left','image_right']) ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Image side</label>
                            <select name="image_side[]">
                                <option value="left"  <?= ($block['image_side'] ?? ($type === 'image_right' ? 'right' : 'left')) === 'left'  ? 'selected' : '' ?>>Image left, text right</option>
                                <option value="right" <?= ($block['image_side'] ?? ($type === 'image_right' ? 'right' : 'left')) === 'right' ? 'selected' : '' ?>>Text left, image right</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Layout</label>
                            <select name="ir_layout[]">
                                <option value="side"    <?= ($block['ir_layout'] ?? 'side') === 'side'    ? 'selected' : '' ?>>Side by side</option>
                                <option value="stacked" <?= ($block['ir_layout'] ?? 'side') === 'stacked' ? 'selected' : '' ?>>Stacked (text above, image below)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Text</label>
                        <textarea name="block_text[]" rows="4" class="rich-editor"><?= h($block['text'] ?? '') ?></textarea>
                    </div>
                    <?php render_photo_upload_fields('block_photo', $block['photo'] ?? '', $block['photo_ratio'] ?? 'landscape', $block['photo_position'] ?? 'center', $block['photo_alt'] ?? '', $i); ?>
                </div>

                <?php /* ---- HERO FIELDS ---- */ ?>
                <div class="block-fields block-fields-hero <?= $type !== 'hero' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Main headline (H1)</label>
                        <input type="text" name="hero_heading[]" value="<?= h($block['hero_heading'] ?? '') ?>" placeholder="e.g. Trusted Local Pest Control in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Subtext</label>
                        <textarea name="hero_subtext[]" rows="2" class="rich-editor"><?= h($block['hero_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button text</label>
                            <input type="text" name="hero_btn_text[]" value="<?= h($block['hero_btn_text'] ?? '') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button link</label>
                            <input type="text" name="hero_btn_url[]" value="<?= h($block['hero_btn_url'] ?? '') ?>" placeholder="e.g. tel:+15551234567">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Background color</label>
                            <input type="color" name="hero_bg_color[]" value="<?= h($block['hero_bg_color'] ?? '#1e3a5f') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Text color</label>
                            <input type="color" name="hero_text_color[]" value="<?= h($block['hero_text_color'] ?? '#ffffff') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image (optional — overrides background color)</label>
                        <?php if (!empty($block['hero_bg_image'])): ?>
                            <img src="/<?= h($block['hero_bg_image']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="hero_bg_image[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="hero_bg_image_existing_<?= $i ?>" name="hero_bg_image_existing[]" value="<?= h($block['hero_bg_image'] ?? '') ?>">
                        <?php photo_picker_btn('hero_bg_image_existing[]', '', 'hero_bg_image_existing_' . $i); ?>
                    </div>
                </div>

                <?php /* ---- HERO SPLIT FIELDS ---- */ ?>
                <div class="block-fields block-fields-hero_split <?= $type !== 'hero_split' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>H1 Headline</label>
                        <input type="text" name="hs_heading[]" value="<?= h($block['hs_heading'] ?? '') ?>" placeholder="e.g. Trusted Local Pest Control in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Paragraph text</label>
                        <textarea name="hs_subtext[]" rows="3" class="rich-editor"><?= h($block['hs_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button text</label>
                            <input type="text" name="hs_btn_text[]" value="<?= h($block['hs_btn_text'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button link</label>
                            <input type="text" name="hs_btn_url[]" value="<?= h($block['hs_btn_url'] ?? '') ?>" placeholder="e.g. tel:+12812150160">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <input type="color" name="hs_bg_color[]" value="<?= h($block['hs_bg_color'] ?? '#f3f6f7') ?>">
                            <span class="hint">Light gray (#f3f6f7) matches katypestpros.com</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background texture / pattern (optional — overlays on color)</label>
                        <?php if (!empty($block['hs_bg_photo'])): ?>
                            <img src="/<?= h($block['hs_bg_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="hidden" id="hs_bg_photo_existing_<?= $i ?>" name="hs_bg_photo_existing[]" value="<?= h($block['hs_bg_photo'] ?? '') ?>">
                        <?php photo_picker_btn('hs_bg_photo_existing[]', '', 'hs_bg_photo_existing_' . $i); ?>
                        <span class="hint">Use a vector/texture image for a full-width background effect.</span>
                    </div>
                    <div class="form-group">
                        <label>Image position</label>
                        <select name="hs_image_side[]">
                            <option value="right" <?= ($block['hs_image_side'] ?? 'right') === 'right' ? 'selected' : '' ?>>Text left, image right</option>
                            <option value="left"  <?= ($block['hs_image_side'] ?? 'right') === 'left'  ? 'selected' : '' ?>>Image left, text right</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <div id="hs_photo_preview_<?= $i ?>">
                        <?php if (!empty($block['hs_photo'])): ?>
                            <img src="/<?= h($block['hs_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        </div>
                        <input type="file" name="hs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="hs_photo_existing_<?= $i ?>" name="hs_photo_existing[]" value="<?= h($block['hs_photo'] ?? '') ?>">
                        <?php photo_picker_btn('hs_photo_existing[]', 'hs_photo_preview_' . $i, 'hs_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Image alt text (SEO)</label>
                        <input type="text" name="hs_photo_alt[]" value="<?= h($block['hs_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control services in Katy TX">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Image caption line 1</label>
                            <input type="text" name="hs_caption1[]" value="<?= h($block['hs_caption1'] ?? '') ?>" placeholder="e.g. Pest Control">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Image caption line 2</label>
                            <input type="text" name="hs_caption2[]" value="<?= h($block['hs_caption2'] ?? '') ?>" placeholder="e.g. Katy, TX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Mobile stacking order</label>
                        <select name="hs_mobile_order[]">
                            <option value=""           <?= ($block['hs_mobile_order'] ?? '') === ''           ? 'selected' : '' ?>>Default (HTML source order)</option>
                            <option value="img_first"  <?= ($block['hs_mobile_order'] ?? '') === 'img_first'  ? 'selected' : '' ?>>Image first</option>
                            <option value="text_first" <?= ($block['hs_mobile_order'] ?? '') === 'text_first' ? 'selected' : '' ?>>Text first</option>
                        </select>
                    </div>
                </div>

                <?php /* ---- FEATURE SPLIT FIELDS ---- */ ?>
                <div class="block-fields block-fields-feature_split <?= $type !== 'feature_split' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (H2)</label>
                        <input type="text" name="fs_heading[]" value="<?= h($block['fs_heading'] ?? '') ?>" placeholder="e.g. Full-Service Pest Management">
                    </div>
                    <div class="form-group">
                        <label>Intro paragraph</label>
                        <textarea name="fs_subtext[]" rows="2"><?= h($block['fs_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <input type="color" name="fs_bg_color[]" value="<?= h($block['fs_bg_color'] ?? '#f3f6f7') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Item heading color</label>
                            <input type="color" name="fs_accent[]" value="<?= h($block['fs_accent'] ?? '#fd783b') ?>">
                        </div>
                    </div>

                    <h4 style="margin:16px 0 8px;font-size:0.95rem;">Icon Grid Items (2 columns)</h4>
                    <div class="fs-items-editor" id="fs_items_<?= $i ?>">
                        <?php foreach (($block['fs_items'] ?? []) as $fi => $fitem): ?>
                        <div class="fs-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:0 0 90px;">
                                    <div class="form-group">
                                        <label>Icon image</label>
                                        <?php if (!empty($fitem['icon'])): ?>
                                            <img src="<?= str_starts_with($fitem['icon'], 'http') ? h($fitem['icon']) : '/'.h($fitem['icon']) ?>" style="max-height:40px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="fs_item_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                                        <input type="hidden" name="fs_item_icon_existing[<?= $i ?>][]" value="<?= h($fitem['icon'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="fs_item_alt[<?= $i ?>][]" value="<?= h($fitem['alt'] ?? '') ?>" placeholder="Icon description" style="font-size:0.8rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Heading</label>
                                        <input type="text" name="fs_item_heading[<?= $i ?>][]" value="<?= h($fitem['heading'] ?? '') ?>" placeholder="e.g. Ants">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="fs_item_text[<?= $i ?>][]" rows="2"><?= h($fitem['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeFsItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFsItem(this, <?= $i ?>)">+ Add item</button>

                    <h4 style="margin:20px 0 8px;font-size:0.95rem;">Side Image</h4>
                    <div class="form-group">
                        <label>Image side</label>
                        <select name="fs_image_side[]">
                            <option value="right" <?= ($block['fs_image_side'] ?? 'right') === 'right' ? 'selected' : '' ?>>Image on right</option>
                            <option value="left"  <?= ($block['fs_image_side'] ?? 'right') === 'left'  ? 'selected' : '' ?>>Image on left</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <?php if (!empty($block['fs_photo'])): ?>
                            <img src="<?= str_starts_with($block['fs_photo'], 'http') ? h($block['fs_photo']) : '/'.h($block['fs_photo']) ?>" style="max-height:80px;border-radius:6px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="fs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="fs_photo_existing_<?= $i ?>" name="fs_photo_existing[]" value="<?= h($block['fs_photo'] ?? '') ?>">
                        <?php photo_picker_btn('fs_photo_existing[]', '', 'fs_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="fs_photo_alt[]" value="<?= h($block['fs_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician in Katy TX">
                    </div>
                    <div class="form-group">
                        <label>Star badge text (shown below image)</label>
                        <input type="text" name="fs_star_text[]" value="<?= h($block['fs_star_text'] ?? '') ?>" placeholder="e.g. 5 Star Services">
                        <span class="hint">★★★★★ stars are shown automatically. Leave blank to hide the badge.</span>
                    </div>
                    <div class="form-group">
                        <label>Mobile stacking order</label>
                        <select name="fs_mobile_order[]">
                            <option value=""           <?= ($block['fs_mobile_order'] ?? '') === ''           ? 'selected' : '' ?>>Default (HTML source order)</option>
                            <option value="img_first"  <?= ($block['fs_mobile_order'] ?? '') === 'img_first'  ? 'selected' : '' ?>>Image first</option>
                            <option value="text_first" <?= ($block['fs_mobile_order'] ?? '') === 'text_first' ? 'selected' : '' ?>>Text / icon grid first</option>
                        </select>
                    </div>
                </div>

                <?php /* ---- FEATURE COLUMNS FIELDS ---- */ ?>
                <div class="block-fields block-fields-feature_columns <?= $type !== 'feature_columns' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (H2)</label>
                        <input type="text" name="fc_heading[]" value="<?= h($block['fc_heading'] ?? '') ?>" placeholder="e.g. Our Pest Control Services">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="fc_num_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['fc_num_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fc-columns-editor" id="fc_cols_<?= $i ?>">
                        <?php $cols = $block['columns'] ?? [['image'=>'','heading'=>'','text'=>'','alt'=>'']]; ?>
                        <?php foreach ($cols as $ci => $col): ?>
                        <div class="fc-col-row">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div class="form-group" style="flex:0 0 80px;">
                                    <label>Icon/image</label>
                                    <?php if (!empty($col['image'])): ?>
                                        <img src="../<?= h($col['image']) ?>" style="max-height:48px;display:block;margin-bottom:4px;">
                                    <?php endif; ?>
                                    <input type="file" name="fc_col_image[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                                    <input type="hidden" id="fc_col_img_<?= $i ?>_<?= $ci ?>" name="fc_col_image_existing[<?= $i ?>][]" value="<?= h($col['image'] ?? '') ?>">
                                    <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('fc_col_img_<?= $i ?>_<?= $ci ?>');if(i)i.value=url;})">📷 Library</button>
                                </div>
                                <div style="flex:1 1 160px;">
                                    <div class="form-group">
                                        <label>Heading (H3)</label>
                                        <input type="text" name="fc_col_heading[<?= $i ?>][]" value="<?= h($col['heading'] ?? '') ?>" placeholder="e.g. Ants">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="fc_col_alt[<?= $i ?>][]" value="<?= h($col['alt'] ?? '') ?>" placeholder="Image description for SEO">
                                    </div>
                                </div>
                                <div style="flex:2 1 200px;">
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="fc_col_text[<?= $i ?>][]" rows="2"><?= h($col['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeFcCol(this)" style="align-self:flex-start;margin-top:24px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFcCol(this, <?= $i ?>)">+ Add column item</button>
                </div>

                <?php /* ---- SPLIT CTA FIELDS ---- */ ?>
                <div class="block-fields block-fields-split_cta <?= $type !== 'split_cta' ? 'is-hidden' : '' ?>">
                    <p class="hint" style="margin-bottom:14px;">Two equal panels side by side — left is content, right is phone CTA. Colors pull from your global theme by default.</p>

                    <h4 style="margin:0 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Panel</h4>
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="sc_left_heading[]" value="<?= h($block['sc_left_heading'] ?? '') ?>" placeholder="e.g. Serving the Greater Katy, TX Area">
                    </div>
                    <div class="form-group">
                        <label>Paragraph text</label>
                        <textarea name="sc_left_text[]" rows="3"><?= h($block['sc_left_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Left panel background</label>
                        <select name="sc_left_bg[]">
                            <option value="accent" <?= ($block['sc_left_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent color (global theme)</option>
                            <option value="header" <?= ($block['sc_left_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header/nav color (global theme)</option>
                            <option value="custom" <?= ($block['sc_left_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom color</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom left color (only if Custom selected above)</label>
                        <input type="color" name="sc_left_bg_custom[]" value="<?= h($block['sc_left_bg_custom'] ?? '#fd783b') ?>">
                    </div>

                    <h4 style="margin:16px 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Panel</h4>
                    <div class="form-group">
                        <label>Label text (above phone)</label>
                        <input type="text" name="sc_right_label[]" value="<?= h($block['sc_right_label'] ?? '') ?>" placeholder="e.g. Call The Katy Pest Pros Team">
                    </div>
                    <div class="form-group">
                        <label>Phone number (displayed)</label>
                        <input type="text" name="sc_right_phone[]" value="<?= h($block['sc_right_phone'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                    </div>
                    <div class="form-group">
                        <label>Phone link (optional — auto-generated if blank)</label>
                        <input type="text" name="sc_right_phone_url[]" value="<?= h($block['sc_right_phone_url'] ?? '') ?>" placeholder="e.g. tel:+12812150160">
                    </div>
                    <div class="form-group">
                        <label>Right panel background</label>
                        <select name="sc_right_bg[]">
                            <option value="header" <?= ($block['sc_right_bg'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header/nav color (global theme)</option>
                            <option value="accent" <?= ($block['sc_right_bg'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent color (global theme)</option>
                            <option value="custom" <?= ($block['sc_right_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom color</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom right color (only if Custom selected above)</label>
                        <input type="color" name="sc_right_bg_custom[]" value="<?= h($block['sc_right_bg_custom'] ?? '#120575') ?>">
                    </div>
                </div>

                <?php /* ---- CTA BUTTON FIELDS ---- */ ?>
                <div class="block-fields block-fields-cta_button <?= $type !== 'cta_button' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button text</label>
                            <input type="text" name="cta_text[]" value="<?= h($block['cta_text'] ?? 'Contact Us') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button link</label>
                            <input type="text" name="cta_url[]" value="<?= h($block['cta_url'] ?? '#') ?>" placeholder="e.g. tel:+15551234567 or /contact">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Optional text above button</label>
                        <input type="text" name="cta_subtext[]" value="<?= h($block['cta_subtext'] ?? '') ?>" placeholder="e.g. Ready to get started?">
                    </div>
                    <div class="form-group">
                        <label>Alignment</label>
                        <select name="cta_align[]">
                            <?php foreach (['left'=>'Left','center'=>'Center','right'=>'Right'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($block['cta_align'] ?? 'center') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php /* ---- IMAGE & TEXT SIDE BY SIDE FIELDS ---- */ ?>
                <div class="block-fields block-fields-image_text <?= $type !== 'image_text' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Image side</label>
                        <select name="it_image_side[]">
                            <option value="left"  <?= ($block['it_image_side'] ?? 'left') === 'left'  ? 'selected' : '' ?>>Image on left, text on right</option>
                            <option value="right" <?= ($block['it_image_side'] ?? 'left') === 'right' ? 'selected' : '' ?>>Text on left, image on right</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Heading level</label>
                        <select name="it_heading_level[]"><?= heading_level_options_html($block['it_heading_level'] ?? 'h2') ?></select>
                    </div>
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="it_heading[]" value="<?= h($block['it_heading'] ?? '') ?>" placeholder="Section heading">
                    </div>
                    <div class="form-group">
                        <label>Text</label>
                        <textarea name="it_text[]" rows="4" class="rich-editor"><?= h($block['it_text'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text (optional)</label>
                            <input type="text" name="it_btn_text[]" value="<?= h($block['it_btn_text'] ?? '') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="it_btn_url[]" value="<?= h($block['it_btn_url'] ?? '') ?>" placeholder="e.g. tel:+15551234567">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <?php if (!empty($block['it_photo'])): ?>
                            <img src="../<?= h($block['it_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;">
                        <?php endif; ?>
                        <input type="file" name="it_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="it_photo_existing_<?= $i ?>" name="it_photo_existing[]" value="<?= h($block['it_photo'] ?? '') ?>">
                        <?php photo_picker_btn('it_photo_existing[]', '', 'it_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Image alt text (SEO)</label>
                        <input type="text" name="it_alt[]" value="<?= h($block['it_alt'] ?? '') ?>" placeholder="Describe the image for search engines">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div style="flex:1 1 160px;">
                            <label>Picture shape</label>
                            <select name="it_ratio[]"><?= photo_ratio_options_html($block['it_ratio'] ?? 'landscape') ?></select>
                        </div>
                        <div style="flex:1 1 160px;">
                            <label>Crop focus</label>
                            <select name="it_position[]"><?= photo_position_options_html($block['it_position'] ?? 'center') ?></select>
                        </div>
                    </div>
                </div>

                <?php /* ---- FAQ FIELDS ---- */ ?>
                <div class="block-fields block-fields-faq <?= $type !== 'faq' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 200px;">
                            <label>Section heading (H2)</label>
                            <input type="text" name="faq_heading[]" value="<?= h($block['faq_heading'] ?? '') ?>" placeholder="e.g. Frequently Asked Questions">
                        </div>
                        <div class="form-group" style="flex:0 0 160px;">
                            <label>Columns</label>
                            <select name="faq_cols[]">
                                <option value="1" <?= ($block['faq_cols'] ?? 1) != 2 ? 'selected' : '' ?>>Single column</option>
                                <option value="2" <?= ($block['faq_cols'] ?? 1) == 2 ? 'selected' : '' ?>>Two columns</option>
                            </select>
                        </div>
                    </div>
                    <div class="faq-items-editor" id="faq_items_<?= $i ?>">
                        <?php $faqItems = $block['faq_items'] ?? [['question'=>'','answer'=>'']]; ?>
                        <?php foreach ($faqItems as $fi => $fitem): ?>
                        <div class="faq-item-row">
                            <div class="form-group">
                                <label>Question</label>
                                <input type="text" name="faq_question[<?= $i ?>][]" value="<?= h($fitem['question'] ?? '') ?>" placeholder="e.g. How much does pest control cost?">
                            </div>
                            <div class="form-group">
                                <label>Answer</label>
                                <textarea name="faq_answer[<?= $i ?>][]" rows="2" class="rich-editor"><?= h($fitem['answer'] ?? '') ?></textarea>
                            </div>
                            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)" style="margin-bottom:12px;">Remove Q&amp;A</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFaqItem(this, <?= $i ?>)">+ Add Q&amp;A</button>
                </div>

                <?php /* ---- CUSTOM HTML FIELDS ---- */ ?>
                <div class="block-fields block-fields-custom_html <?= $type !== 'custom_html' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Custom HTML / Embed code</label>
                        <textarea name="custom_html[]" rows="6" style="font-family:monospace;font-size:0.83rem;"><?= h($block['html'] ?? '') ?></textarea>
                        <span class="hint">Paste any raw HTML here — Google Maps embeds, review widgets, booking scripts, etc. Output is not escaped.</span>
                    </div>
                </div>

                <?php /* ---- TWO-COLUMN HTML FIELDS ---- */ ?>
                <div class="block-fields block-fields-html_two_col <?= $type !== 'html_two_col' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 240px;">
                            <label>Left column HTML</label>
                            <textarea name="htc_left[]" rows="8" style="font-family:monospace;font-size:0.83rem;"><?= h($block['htc_left'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group" style="flex:1 1 240px;">
                            <label>Right column HTML</label>
                            <textarea name="htc_right[]" rows="8" style="font-family:monospace;font-size:0.83rem;"><?= h($block['htc_right'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="form-group" style="max-width:200px;">
                        <label>Background color <span class="hint">(optional)</span></label>
                        <input type="color" name="htc_bg[]" value="<?= h($block['htc_bg'] ?? '#ffffff') ?>">
                    </div>
                </div>

                <?php /* ---- CTA CARD FIELDS ---- */ ?>
                <div class="block-fields block-fields-cta_card <?= $type !== 'cta_card' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="cc_heading[]" value="<?= h($block['cc_heading'] ?? '') ?>" placeholder="e.g. Contact Katy's Top Pest Control Company Today">
                    </div>
                    <div class="form-group">
                        <label>Paragraph text</label>
                        <textarea name="cc_text[]" rows="3"><?= h($block['cc_text'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text</label>
                            <input type="text" name="cc_btn_text[]" value="<?= h($block['cc_btn_text'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="cc_btn_url[]" value="<?= h($block['cc_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Button style</label>
                            <select name="cc_btn_style[]">
                                <option value="outline" <?= ($block['cc_btn_style'] ?? 'outline') === 'outline' ? 'selected' : '' ?>>Outline (white border)</option>
                                <option value="filled"  <?= ($block['cc_btn_style'] ?? '') === 'filled' ? 'selected' : '' ?>>Filled (white bg)</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Card background</label>
                            <select name="cc_bg[]">
                                <option value="accent" <?= ($block['cc_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['cc_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['cc_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="cc_bg_custom[]" value="<?= h($block['cc_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Card border radius (px)</label>
                            <input type="number" name="cc_radius[]" value="<?= h($block['cc_radius'] ?? '12') ?>" min="0" max="40" placeholder="12">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Layout</label>
                            <select name="cc_align[]">
                                <option value="split"  <?= ($block['cc_align'] ?? 'split') === 'split'  ? 'selected' : '' ?>>Split (text left, button right)</option>
                                <option value="center" <?= ($block['cc_align'] ?? 'split') === 'center' ? 'selected' : '' ?>>Centered (full width text)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php /* ---- MAP + INFO FIELDS ---- */ ?>
                <div class="block-fields block-fields-map_info <?= $type !== 'map_info' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Heading color</label>
                        <select name="mi_head_color[]">
                            <option value="header" <?= ($block['mi_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                            <option value="accent" <?= ($block['mi_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                            <option value="custom" <?= ($block['mi_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                        <input type="color" name="mi_head_color_custom[]" value="<?= h($block['mi_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Panel — Map</h4>
                    <div class="form-group">
                        <label>Map panel heading</label>
                        <input type="text" name="mi_map_heading[]" value="<?= h($block['mi_map_heading'] ?? '') ?>" placeholder="e.g. Katy, Texas Map">
                    </div>
                    <div class="form-group">
                        <label>Google Maps embed code</label>
                        <textarea name="mi_map_embed[]" rows="4" placeholder='Paste your Google Maps <iframe ...> embed code here'><?= h($block['mi_map_embed'] ?? '') ?></textarea>
                        <span class="hint">Go to Google Maps → Share → Embed a map → copy the &lt;iframe&gt; code.</span>
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Panel — Info</h4>
                    <div class="form-group">
                        <label>Info panel heading</label>
                        <input type="text" name="mi_info_heading[]" value="<?= h($block['mi_info_heading'] ?? '') ?>" placeholder="e.g. Katy, TX Information">
                    </div>
                    <div class="form-group">
                        <label>Info text</label>
                        <textarea name="mi_info_text[]" rows="4" class="rich-editor"><?= h($block['mi_info_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Info photo (optional)</label>
                        <?php if (!empty($block['mi_info_photo'])): ?>
                            <img src="<?= str_starts_with($block['mi_info_photo'],'http') ? h($block['mi_info_photo']) : '/'.h($block['mi_info_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="mi_info_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="mi_info_photo_existing_<?= $i ?>" name="mi_info_photo_existing[]" value="<?= h($block['mi_info_photo'] ?? '') ?>">
                        <?php photo_picker_btn('mi_info_photo_existing[]', '', 'mi_info_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Photo alt text</label>
                        <input type="text" name="mi_info_alt[]" value="<?= h($block['mi_info_alt'] ?? '') ?>" placeholder="e.g. Katy TX shopping center">
                    </div>
                </div>

                <?php /* ---- LINKS GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-links_grid <?= $type !== 'links_grid' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Style</label>
                        <select name="lg_style[]" onchange="this.closest('.block-fields').querySelectorAll('.lg-dark-only,.lg-light-only').forEach(el=>el.style.display=this.value==='dark'?'':'none');this.closest('.block-fields').querySelectorAll('.lg-light-only').forEach(el=>el.style.display=this.value==='light'?'':'none')">
                            <option value="dark"  <?= ($block['lg_style'] ?? 'dark') === 'dark'  ? 'selected' : '' ?>>Dark (background image + overlay)</option>
                            <option value="light" <?= ($block['lg_style'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light (white/colored bg, gray bordered boxes)</option>
                        </select>
                    </div>

                    <!-- Light style fields -->
                    <div class="lg-light-only" <?= ($block['lg_style'] ?? 'dark') !== 'light' ? 'style="display:none;"' : '' ?>>
                        <div class="form-group">
                            <label>Small label text above heading (accent color)</label>
                            <input type="text" name="lg_sublabel[]" value="<?= h($block['lg_sublabel'] ?? '') ?>" placeholder="e.g. Top Rated Katy, TX Pest Experts">
                        </div>
                        <div class="form-group">
                            <label>Background color</label>
                            <input type="color" name="lg_bg_color[]" value="<?= h($block['lg_bg_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group">
                            <label>Label / accent color</label>
                            <select name="lg_accent[]">
                                <option value="accent" <?= ($block['lg_accent'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['lg_accent'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['lg_accent'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="lg_accent_custom[]" value="<?= h($block['lg_accent_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="lg_heading[]" value="<?= h($block['lg_heading'] ?? '') ?>" placeholder="e.g. Our Pest Control Services in Katy, TX">
                    </div>

                    <!-- Dark style only fields -->
                    <div class="lg-dark-only" <?= ($block['lg_style'] ?? 'dark') !== 'dark' ? 'style="display:none;"' : '' ?>>
                        <div class="form-group">
                            <label>Subtext paragraph</label>
                            <textarea name="lg_subtext[]" rows="2"><?= h($block['lg_subtext'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Number of columns</label>
                            <select name="lg_cols[]">
                                <?php foreach ([2,3,4,5,6] as $n): ?>
                                    <option value="<?= $n ?>" <?= ($block['lg_cols'] ?? 5) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Dark overlay opacity: <strong id="lg_ov_val_<?= $i ?>"><?= h($block['lg_overlay'] ?? '0.6') ?></strong></label>
                            <input type="range" name="lg_overlay[]" min="0" max="0.9" step="0.05"
                                   value="<?= h($block['lg_overlay'] ?? '0.6') ?>"
                                   oninput="document.getElementById('lg_ov_val_<?= $i ?>').textContent=this.value"
                                   style="width:100%;accent-color:var(--color-accent,#2563eb);">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image</label>
                        <?php if (!empty($block['lg_photo'])): ?>
                            <img src="<?= str_starts_with($block['lg_photo'],'http') ? h($block['lg_photo']) : '/'.h($block['lg_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="lg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="lg_photo_existing_<?= $i ?>" name="lg_photo_existing[]" value="<?= h($block['lg_photo'] ?? '') ?>">
                        <?php photo_picker_btn('lg_photo_existing[]', '', 'lg_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="lg_photo_alt[]" value="<?= h($block['lg_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control services Katy TX">
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;">Links (label + URL)</h4>
                    <p class="hint" style="margin-bottom:10px;">Each link becomes a bordered button. Typically used for internal SEO links to service pages.</p>
                    <div class="lg-links-editor" id="lg_links_<?= $i ?>">
                        <?php foreach (($block['lg_links'] ?? []) as $li => $lnk): ?>
                        <div class="lg-link-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
                            <input type="text" name="lg_link_label[<?= $i ?>][]" value="<?= h($lnk['label'] ?? '') ?>" placeholder="Link text" style="flex:1;">
                            <input type="text" name="lg_link_url[<?= $i ?>][]"   value="<?= h($lnk['url']   ?? '') ?>" placeholder="URL e.g. /cockroach-exterminator" style="flex:1;">
                            <button type="button" class="remove-row" onclick="removeLgLink(this)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addLgLink(this, <?= $i ?>)">+ Add link</button>
                    <p class="hint" style="margin-top:8px;">Tip: Add all links first, then use "Bulk add" to paste a list.</p>
                    <div class="form-group" style="margin-top:10px;">
                        <label>Bulk add (one link label per line — no URLs, all link to #)</label>
                        <textarea id="lg_bulk_<?= $i ?>" rows="4" placeholder="Cockroach Exterminator&#10;Termite Treatment&#10;Mosquito Control"></textarea>
                        <button type="button" class="btn btn-secondary btn-small" style="margin-top:6px;" onclick="bulkAddLgLinks(<?= $i ?>)">Add all as links</button>
                    </div>
                </div>

                <?php /* ---- CTA BANNER FIELDS ---- */ ?>
                <div class="block-fields block-fields-cta_banner <?= $type !== 'cta_banner' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Banner text (centered, bold)</label>
                        <input type="text" name="cb_text[]" value="<?= h($block['cb_text'] ?? '') ?>"
                               placeholder="e.g. 24/7 Pest Control Services in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Subtext (optional, smaller below main text)</label>
                        <input type="text" name="cb_subtext[]" value="<?= h($block['cb_subtext'] ?? '') ?>"
                               placeholder="Optional tagline or second line">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text (optional)</label>
                            <input type="text" name="cb_btn_text[]" value="<?= h($block['cb_btn_text'] ?? '') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="cb_btn_url[]" value="<?= h($block['cb_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <select name="cb_bg[]">
                                <option value="accent" <?= ($block['cb_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['cb_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['cb_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="cb_bg_custom[]" value="<?= h($block['cb_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Text color</label>
                            <input type="color" name="cb_text_color[]" value="<?= h($block['cb_text_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Padding / height</label>
                            <select name="cb_padding[]">
                                <option value="compact" <?= ($block['cb_padding'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Compact (thin strip)</option>
                                <option value="normal"  <?= ($block['cb_padding'] ?? 'normal') === 'normal'  ? 'selected' : '' ?>>Normal</option>
                                <option value="large"   <?= ($block['cb_padding'] ?? 'normal') === 'large'   ? 'selected' : '' ?>>Large</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php /* ---- FAQ TWO COLUMN FIELDS ---- */ ?>
                <div class="block-fields block-fields-faq_two_col <?= $type !== 'faq_two_col' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="fq_heading[]" value="<?= h($block['fq_heading'] ?? '') ?>" placeholder="e.g. FAQs – Pest Control in Katy">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 140px;">
                            <label>Background color</label>
                            <input type="color" name="fq_bg_color[]" value="<?= h($block['fq_bg_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 140px;">
                            <label>Item box color</label>
                            <input type="color" name="fq_item_bg[]" value="<?= h($block['fq_item_bg'] ?? '#f0f2f8') ?>">
                            <span class="hint">Light blue-gray (#f0f2f8)</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Heading color</label>
                            <select name="fq_head_color[]">
                                <option value="header" <?= ($block['fq_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="accent" <?= ($block['fq_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['fq_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="fq_head_color_custom[]" value="<?= h($block['fq_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>+ Icon background</label>
                            <select name="fq_icon_bg[]">
                                <option value="accent" <?= ($block['fq_icon_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['fq_icon_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['fq_icon_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="fq_icon_bg_custom[]" value="<?= h($block['fq_icon_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;">Q&amp;A Items</h4>
                    <div class="fq-items-editor" id="fq_items_<?= $i ?>">
                        <?php foreach (($block['fq_items'] ?? []) as $fi => $fitem): ?>
                        <div class="fq-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div class="form-group">
                                <label>Question</label>
                                <input type="text" name="fq_question[<?= $i ?>][]" value="<?= h($fitem['question'] ?? '') ?>" placeholder="e.g. What types of pests do you treat?">
                            </div>
                            <div class="form-group">
                                <label>Answer</label>
                                <textarea name="fq_answer[<?= $i ?>][]" rows="2" class="rich-editor"><?= h($fitem['answer'] ?? '') ?></textarea>
                            </div>
                            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFqItem(this)" style="margin-bottom:4px;">Remove Q&amp;A</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFqItem(this, <?= $i ?>)">+ Add Q&amp;A</button>
                </div>

                <?php /* ---- IMAGE FEATURES FIELDS ---- */ ?>
                <div class="block-fields block-fields-image_features <?= $type !== 'image_features' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Background color</label>
                        <input type="color" name="if_bg_color[]" value="<?= h($block['if_bg_color'] ?? '#f3f6f7') ?>">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Checkmark / accent color</label>
                            <select name="if_check_color[]">
                                <option value="accent" <?= ($block['if_check_color'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['if_check_color'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['if_check_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="if_check_color_custom[]" value="<?= h($block['if_check_color_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Heading / text color</label>
                            <select name="if_head_color[]">
                                <option value="header" <?= ($block['if_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="accent" <?= ($block['if_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['if_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="if_head_color_custom[]" value="<?= h($block['if_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                        </div>
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Photo</h4>
                    <div class="form-group">
                        <label>Photo</label>
                        <?php if (!empty($block['if_photo'])): ?>
                            <img src="<?= str_starts_with($block['if_photo'],'http') ? h($block['if_photo']) : '/'.h($block['if_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="if_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="if_photo_existing_<?= $i ?>" name="if_photo_existing[]" value="<?= h($block['if_photo'] ?? '') ?>">
                        <?php photo_picker_btn('if_photo_existing[]', '', 'if_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Photo alt text</label>
                        <input type="text" name="if_photo_alt[]" value="<?= h($block['if_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician Katy TX">
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Content</h4>
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="if_heading[]" value="<?= h($block['if_heading'] ?? '') ?>" placeholder="e.g. Quality Pest Prevention">
                    </div>
                    <div class="form-group">
                        <label>Intro paragraph</label>
                        <textarea name="if_intro[]" rows="3" class="rich-editor"><?= h($block['if_intro'] ?? '') ?></textarea>
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;">Feature checkboxes (2 per row)</h4>
                    <div class="if-feats-editor" id="if_feats_<?= $i ?>">
                        <?php foreach (($block['if_features'] ?? []) as $fi => $feat): ?>
                        <div class="if-feat-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="if_features[<?= $i ?>][]" value="<?= h($feat) ?>" placeholder="e.g. Exterior treatments" style="flex:1;">
                            <button type="button" class="remove-row" onclick="removeIfFeat(this)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addIfFeat(this, <?= $i ?>)">+ Add feature</button>

                    <div class="form-group" style="margin-top:14px;">
                        <label>Closing paragraph (below features)</label>
                        <textarea name="if_closing[]" rows="2"><?= h($block['if_closing'] ?? '') ?></textarea>
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Phone CTA Row</h4>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Label (above phone)</label>
                            <input type="text" name="if_phone_label[]" value="<?= h($block['if_phone_label'] ?? '') ?>" placeholder="e.g. Call Us 24/7">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Phone number</label>
                            <input type="text" name="if_phone[]" value="<?= h($block['if_phone'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Phone link (optional)</label>
                            <input type="text" name="if_phone_url[]" value="<?= h($block['if_phone_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                    </div>
                </div>

                <?php /* ---- WIDE BANNER FIELDS ---- */ ?>
                <div class="block-fields block-fields-wide_banner <?= $type !== 'wide_banner' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Badge text (small pill, optional)</label>
                        <input type="text" name="wb_badge[]" value="<?= h($block['wb_badge'] ?? '') ?>" placeholder="e.g. KATY, TEXAS'S SPECIALISTS">
                    </div>
                    <div class="form-group">
                        <label>Heading (H2)</label>
                        <input type="text" name="wb_heading[]" value="<?= h($block['wb_heading'] ?? '') ?>" placeholder="e.g. Your First Choice For Katy Pest Pros in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Subtext (optional — shown below heading)</label>
                        <textarea name="wb_subtext[]" rows="2"><?= h($block['wb_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text</label>
                            <input type="text" name="wb_btn_text[]" value="<?= h($block['wb_btn_text'] ?? '') ?>" placeholder="e.g. Call Us">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="wb_btn_url[]" value="<?= h($block['wb_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Button style</label>
                            <select name="wb_btn_style[]">
                                <option value="filled"  <?= ($block['wb_btn_style'] ?? 'filled') === 'filled'  ? 'selected' : '' ?>>Filled</option>
                                <option value="outline" <?= ($block['wb_btn_style'] ?? '')        === 'outline' ? 'selected' : '' ?>>Outline</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Badge / button color</label>
                        <select name="wb_badge_bg[]">
                            <option value="accent" <?= ($block['wb_badge_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                            <option value="header" <?= ($block['wb_badge_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                            <option value="custom" <?= ($block['wb_badge_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                        <input type="color" name="wb_badge_bg_custom[]" value="<?= h($block['wb_badge_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Solid background color (if no image)</label>
                            <input type="color" name="wb_bg_color[]" value="<?= h($block['wb_bg_color'] ?? '#1a1a2e') ?>">
                            <span class="hint">Used when no background image is set.</span>
                        </div>
                        <div class="form-group" style="flex:1 1 160px;padding-top:22px;">
                            <label><input type="checkbox" name="wb_centered[]" value="1" <?= !empty($block['wb_centered']) ? 'checked' : '' ?>> Center all text</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image (optional — overrides solid color)</label>
                        <?php if (!empty($block['wb_photo'])): ?>
                            <img src="<?= str_starts_with($block['wb_photo'],'http') ? h($block['wb_photo']) : '/'.h($block['wb_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="wb_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="wb_photo_existing_<?= $i ?>" name="wb_photo_existing[]" value="<?= h($block['wb_photo'] ?? '') ?>">
                        <?php photo_picker_btn('wb_photo_existing[]', '', 'wb_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="wb_photo_alt[]" value="<?= h($block['wb_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician Katy TX">
                    </div>
                    <div class="form-group">
                        <label>Dark overlay opacity: <strong id="wb_overlay_val_<?= $i ?>"><?= h($block['wb_overlay'] ?? '0.55') ?></strong></label>
                        <input type="range" name="wb_overlay[]" min="0" max="0.9" step="0.05"
                               value="<?= h($block['wb_overlay'] ?? '0.55') ?>"
                               oninput="document.getElementById('wb_overlay_val_<?= $i ?>').textContent=this.value"
                               style="width:100%;accent-color:var(--color-accent,#2563eb);">
                        <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#888;"><span>0 (no overlay)</span><span>0.9 (very dark)</span></div>
                    </div>
                </div>

                <?php /* ---- SERVICE CARDS FIELDS ---- */ ?>
                <div class="block-fields block-fields-service_cards <?= $type !== 'service_cards' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Badge text (orange pill above heading)</label>
                        <input type="text" name="sc_badge[]" value="<?= h($block['sc_badge'] ?? '') ?>" placeholder="e.g. PROFESSIONAL KATY, TX COMPANY">
                    </div>
                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="sc_heading[]" value="<?= h($block['sc_heading'] ?? '') ?>" placeholder="e.g. Local Experts in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="sc_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['sc_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Badge background</label>
                            <select name="sc_badge_bg[]">
                                <option value="accent" <?= ($block['sc_badge_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['sc_badge_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['sc_badge_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="sc_badge_bg_custom[]" value="<?= h($block['sc_badge_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Heading color</label>
                            <select name="sc_head_color[]">
                                <option value="header" <?= ($block['sc_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="accent" <?= ($block['sc_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['sc_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="sc_head_color_custom[]" value="<?= h($block['sc_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Icon circle background color</label>
                            <input type="color" name="sc_icon_bg[]" value="<?= h($block['sc_icon_bg'] ?? '#fef0e7') ?>">
                            <span class="hint">Light peach (#fef0e7) matches katypestpros.com</span>
                        </div>
                    </div>

                    <h4 style="margin:16px 0 8px;font-size:0.95rem;">Cards</h4>
                    <div class="sc-items-editor" id="sc_items_<?= $i ?>">
                        <?php foreach (($block['sc_items'] ?? []) as $si => $sitem): ?>
                        <div class="sc-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:0 0 100px;">
                                    <div class="form-group">
                                        <label>Icon image</label>
                                        <?php if (!empty($sitem['icon'])): ?>
                                            <img src="<?= str_starts_with($sitem['icon'],'http') ? h($sitem['icon']) : '/'.h($sitem['icon']) ?>" style="max-height:40px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                                        <input type="file" name="sc_item_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                                        <input type="hidden" id="sc_icon_<?= $i ?>_<?= $si ?>" name="sc_item_icon_existing[<?= $i ?>][]" value="<?= h($sitem['icon'] ?? '') ?>">
                                        <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('sc_icon_<?= $i ?>_<?= $si ?>');if(i)i.value=url;})">📷 Library</button>
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="sc_item_alt[<?= $i ?>][]" value="<?= h($sitem['alt'] ?? '') ?>" style="font-size:0.8rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 220px;">
                                    <div class="form-group">
                                        <label>Card heading</label>
                                        <input type="text" name="sc_item_heading[<?= $i ?>][]" value="<?= h($sitem['heading'] ?? '') ?>" placeholder="e.g. Roach Control & Extermination">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="sc_item_text[<?= $i ?>][]" rows="2"><?= h($sitem['text'] ?? '') ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>Link URL <span class="hint">(optional — makes the card clickable)</span></label>
                                        <input type="text" name="sc_item_url[<?= $i ?>][]" value="<?= h($sitem['url'] ?? '') ?>" placeholder="e.g. /cockroach-exterminator-katy-tx">
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeScItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addScItem(this, <?= $i ?>)">+ Add card</button>
                </div>

                <?php /* ---- HERO GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-hero_grid <?= $type !== 'hero_grid' ? 'is-hidden' : '' ?>">
                    <p class="hint" style="margin-bottom:12px;">Left side: background image + text overlay. Right side: 3×2 icon grid with alternating colors.</p>

                    <h4 style="margin:0 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Panel</h4>
                    <div class="form-group">
                        <label>Small label (above heading)</label>
                        <input type="text" name="hg_label[]" value="<?= h($block['hg_label'] ?? '') ?>" placeholder="e.g. Katy Pest Pros">
                    </div>
                    <div class="form-group">
                        <label>Heading (H2)</label>
                        <input type="text" name="hg_heading[]" value="<?= h($block['hg_heading'] ?? '') ?>" placeholder="e.g. Top-Notch Katy Pest Pros in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Body text (leave blank line between paragraphs)</label>
                        <textarea name="hg_body[]" rows="4" class="rich-editor"><?= h($block['hg_body'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text</label>
                            <input type="text" name="hg_btn_text[]" value="<?= h($block['hg_btn_text'] ?? '') ?>" placeholder="e.g. Call Us">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="hg_btn_url[]" value="<?= h($block['hg_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image</label>
                        <?php if (!empty($block['hg_photo'])): ?>
                            <img src="<?= str_starts_with($block['hg_photo'],'http') ? h($block['hg_photo']) : '/'.h($block['hg_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="hg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" id="hg_photo_existing_<?= $i ?>" name="hg_photo_existing[]" value="<?= h($block['hg_photo'] ?? '') ?>">
                        <?php photo_picker_btn('hg_photo_existing[]', '', 'hg_photo_existing_' . $i); ?>
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="hg_photo_alt[]" value="<?= h($block['hg_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician Katy TX">
                    </div>

                    <h4 style="margin:16px 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Grid — Tile Colors (alternating)</h4>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Odd tiles (1st, 3rd, 5th…)</label>
                            <select name="hg_color1[]">
                                <option value="accent" <?= ($block['hg_color1'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['hg_color1'] ?? '') === 'header' ? 'selected' : '' ?>>Header color (global)</option>
                                <option value="custom" <?= ($block['hg_color1'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="hg_color1_custom[]" value="<?= h($block['hg_color1_custom'] ?? '#fd783b') ?>" style="margin-top:6px;">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Even tiles (2nd, 4th, 6th…)</label>
                            <select name="hg_color2[]">
                                <option value="header" <?= ($block['hg_color2'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header color (global)</option>
                                <option value="accent" <?= ($block['hg_color2'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['hg_color2'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="hg_color2_custom[]" value="<?= h($block['hg_color2_custom'] ?? '#120575') ?>" style="margin-top:6px;">
                        </div>
                    </div>

                    <h4 style="margin:16px 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Grid Items (icon + label, 3 per row)</h4>
                    <div class="hg-items-editor" id="hg_items_<?= $i ?>">
                        <?php foreach (($block['hg_items'] ?? []) as $gi => $gitem): ?>
                        <div class="hg-item-row" style="display:flex;gap:10px;align-items:center;margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;">
                            <div style="flex:0 0 90px;">
                                <label style="font-size:0.8rem;font-weight:600;">Icon</label>
                                <?php if (!empty($gitem['icon'])): ?>
                                    <img src="<?= str_starts_with($gitem['icon'],'http') ? h($gitem['icon']) : '/'.h($gitem['icon']) ?>" style="max-height:32px;display:block;margin:4px 0;" onerror="this.style.display='none'">
                                <?php endif; ?>
                                <input type="file" name="hg_item_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.72rem;">
                                <input type="hidden" name="hg_item_icon_existing[<?= $i ?>][]" value="<?= h($gitem['icon'] ?? '') ?>">
                            </div>
                            <div style="flex:1 1 160px;">
                                <label style="font-size:0.8rem;font-weight:600;">Label</label>
                                <input type="text" name="hg_item_label[<?= $i ?>][]" value="<?= h($gitem['label'] ?? '') ?>" placeholder="e.g. Carpenter Ants">
                                <label style="font-size:0.8rem;font-weight:600;margin-top:4px;display:block;">Alt text</label>
                                <input type="text" name="hg_item_alt[<?= $i ?>][]" value="<?= h($gitem['alt'] ?? '') ?>" placeholder="Icon alt text" style="font-size:0.8rem;">
                            </div>
                            <button type="button" class="remove-row" onclick="removeHgItem(this)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addHgItem(this, <?= $i ?>)">+ Add grid item</button>
                </div>

                <?php /* ---- TAB SERVICES FIELDS ---- */ ?>
                <div class="block-fields block-fields-tab_services <?= $type !== 'tab_services' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 200px;">
                            <label>Badge 1 text (filled pill)</label>
                            <input type="text" name="ts_badge1[]" value="<?= h($block['ts_badge1'] ?? '') ?>" placeholder="e.g. KATY PEST PROS">
                        </div>
                        <div class="form-group" style="flex:1 1 200px;">
                            <label>Badge 2 text (outline pill)</label>
                            <input type="text" name="ts_badge2[]" value="<?= h($block['ts_badge2'] ?? '') ?>" placeholder="e.g. SERVICES KATY, TX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="ts_heading[]" value="<?= h($block['ts_heading'] ?? '') ?>" placeholder="e.g. Professional Katy Pest Pros Team in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Active tab background color</label>
                        <select name="ts_active_bg[]">
                            <option value="header" <?= ($block['ts_active_bg'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header/nav color (global)</option>
                            <option value="accent" <?= ($block['ts_active_bg'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent color (global)</option>
                            <option value="custom" <?= ($block['ts_active_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom active color (if Custom above)</label>
                        <input type="color" name="ts_active_bg_custom[]" value="<?= h($block['ts_active_bg_custom'] ?? '#120575') ?>">
                    </div>

                    <h4 style="margin:16px 0 8px;font-size:0.95rem;">Tabs (each has icon, label, photo, description)</h4>
                    <div class="ts-tabs-editor" id="ts_tabs_<?= $i ?>">
                        <?php foreach (($block['ts_tabs'] ?? []) as $ti => $tab): ?>
                        <div class="ts-tab-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:10px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:0 0 110px;">
                                    <div class="form-group">
                                        <label>Tab icon</label>
                                        <?php if (!empty($tab['icon'])): ?>
                                            <img src="<?= str_starts_with($tab['icon'],'http') ? h($tab['icon']) : '/'.h($tab['icon']) ?>" style="max-height:36px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="ts_tab_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                                        <input type="hidden" name="ts_tab_icon_existing[<?= $i ?>][]" value="<?= h($tab['icon'] ?? '') ?>">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Tab label</label>
                                        <input type="text" name="ts_tab_label[<?= $i ?>][]" value="<?= h($tab['label'] ?? '') ?>" placeholder="e.g. Fleas">
                                    </div>
                                    <div class="form-group">
                                        <label>Description (shown below photo)</label>
                                        <textarea name="ts_tab_desc[<?= $i ?>][]" rows="2"><?= h($tab['desc'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <div style="flex:0 0 130px;">
                                    <div class="form-group">
                                        <label>Tab photo</label>
                                        <?php if (!empty($tab['photo'])): ?>
                                            <img src="<?= str_starts_with($tab['photo'],'http') ? h($tab['photo']) : '/'.h($tab['photo']) ?>" style="max-height:60px;display:block;margin-bottom:4px;border-radius:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="ts_tab_photo[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.75rem;">
                                        <input type="hidden" name="ts_tab_photo_existing[<?= $i ?>][]" value="<?= h($tab['photo'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Photo alt text</label>
                                        <input type="text" name="ts_tab_alt[<?= $i ?>][]" value="<?= h($tab['alt'] ?? '') ?>" placeholder="Alt text" style="font-size:0.8rem;">
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeTsTab(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addTsTab(this, <?= $i ?>)">+ Add tab</button>
                </div>

                <?php /* ---- GALLERY FIELDS ---- */ ?>
                <div class="block-fields block-fields-gallery <?= $type !== 'gallery' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="gallery_heading[]" value="<?= h($block['gallery_heading'] ?? '') ?>" placeholder="e.g. Gallery of Restoration Projects">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="gallery_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['gallery_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gallery-images-editor" id="gallery_imgs_<?= $i ?>">
                        <?php foreach (($block['gallery_images'] ?? []) as $gi => $gimg): ?>
                        <div class="gallery-img-row">
                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                                <?php if (!empty($gimg['photo'])): ?>
                                    <img src="/<?= h($gimg['photo']) ?>" style="max-height:60px;border-radius:4px;" onerror="this.style.display='none'">
                                <?php endif; ?>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Image</label>
                                        <input type="file" name="gallery_photo[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp">
                                        <input type="hidden" name="gallery_photo_existing[<?= $i ?>][]" value="<?= h($gimg['photo'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="gallery_alt[<?= $i ?>][]" value="<?= h($gimg['alt'] ?? '') ?>" placeholder="Describe the photo for SEO">
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeGalleryImg(this)" style="margin-top:24px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addGalleryImg(this, <?= $i ?>)">+ Add image</button>
                </div>

                <?php /* ---- STEPS FIELDS ---- */ ?>
                <div class="block-fields block-fields-steps <?= $type !== 'steps' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="steps_heading[]" value="<?= h($block['steps_heading'] ?? '') ?>" placeholder="e.g. Our Recovery Process">
                    </div>
                    <span class="hint" style="display:block;margin-bottom:10px;">Leave the image blank to show an auto-numbered circle instead.</span>
                    <div class="steps-items-editor" id="steps_items_<?= $i ?>">
                        <?php foreach (($block['steps_items'] ?? []) as $si => $step): ?>
                        <div class="step-item-row">
                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                                <div style="flex:0 0 100px;">
                                    <div class="form-group">
                                        <label>Icon/image (optional)</label>
                                        <?php if (!empty($step['image'])): ?>
                                            <img src="/<?= h($step['image']) ?>" style="max-height:48px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="steps_image[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                                        <input type="hidden" id="steps_img_<?= $i ?>_<?= $si ?>" name="steps_image_existing[<?= $i ?>][]" value="<?= h($step['image'] ?? '') ?>">
                                        <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('steps_img_<?= $i ?>_<?= $si ?>');if(i)i.value=url;})">📷 Library</button>
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="steps_alt[<?= $i ?>][]" value="<?= h($step['alt'] ?? '') ?>" placeholder="Step icon description" style="font-size:0.82rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Step heading</label>
                                        <input type="text" name="steps_heading_item[<?= $i ?>][]" value="<?= h($step['heading'] ?? '') ?>" placeholder="e.g. Call Us">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="steps_text[<?= $i ?>][]" rows="2"><?= h($step['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeStepItem(this)" style="margin-top:24px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addStepItem(this, <?= $i ?>)">+ Add step</button>
                </div>

                <?php /* ---- STATS FIELDS ---- */ ?>
                <div class="block-fields block-fields-stats <?= $type !== 'stats' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="stats_heading[]" value="<?= h($block['stats_heading'] ?? '') ?>" placeholder="e.g. Why Choose Us">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <input type="color" name="stats_bg_color[]" value="<?= h($block['stats_bg_color'] ?? '#1e3a5f') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Text color</label>
                            <input type="color" name="stats_text_color[]" value="<?= h($block['stats_text_color'] ?? '#ffffff') ?>">
                        </div>
                    </div>
                    <div class="stats-items-editor" id="stats_items_<?= $i ?>">
                        <?php foreach (($block['stats_items'] ?? []) as $stat): ?>
                        <div class="stat-item-row">
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <div class="form-group" style="flex:1 1 120px;">
                                    <label>Number / value</label>
                                    <input type="text" name="stats_number[<?= $i ?>][]" value="<?= h($stat['number'] ?? '') ?>" placeholder="e.g. 5,200+">
                                </div>
                                <div class="form-group" style="flex:2 1 200px;">
                                    <label>Label</label>
                                    <input type="text" name="stats_label[<?= $i ?>][]" value="<?= h($stat['label'] ?? '') ?>" placeholder="e.g. Jobs Completed">
                                </div>
                                <button type="button" class="remove-row" onclick="removeStatItem(this)">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addStatItem(this, <?= $i ?>)">+ Add stat</button>
                </div>

                <?php /* ---- CARDS GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-cards <?= $type !== 'cards' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="cards_heading[]" value="<?= h($block['cards_heading'] ?? '') ?>" placeholder="e.g. Our Services">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="cards_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['cards_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <?php $cbgVal = h($block['cards_bg'] ?? '#f3f6f7'); $ccbgVal = h($block['cards_card_bg'] ?? '#ffffff'); $ctcVal = h($block['cards_text_color'] ?? '#333333'); ?>
                        <div class="form-group">
                            <label>Block background color</label>
                            <input type="color" name="cards_bg[]" value="<?= $cbgVal ?>" oninput="this.nextElementSibling.value=this.value;">
                            <input type="text" value="<?= $cbgVal ?>" placeholder="#f3f6f7" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                        </div>
                        <div class="form-group">
                            <label>Card background color</label>
                            <input type="color" name="cards_card_bg[]" value="<?= $ccbgVal ?>" oninput="this.nextElementSibling.value=this.value;">
                            <input type="text" value="<?= $ccbgVal ?>" placeholder="#ffffff" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                        </div>
                        <div class="form-group">
                            <label>Section heading color</label>
                            <select name="cards_head_color[]" onchange="toggleCustomColor(this, 'cards_head_color_custom_<?= $i ?>')">
                                <?php foreach (['accent'=>'Accent','header'=>'Header/Navy','custom'=>'Custom'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($block['cards_head_color'] ?? 'header') === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="color" id="cards_head_color_custom_<?= $i ?>" name="cards_head_color_custom[]" value="<?= h($block['cards_head_color_custom'] ?? '#1a1a2e') ?>" style="display:<?= ($block['cards_head_color'] ?? 'header') === 'custom' ? 'inline-block' : 'none' ?>;">
                        </div>
                        <div class="form-group">
                            <label>Card heading color</label>
                            <select name="cards_item_head_color[]" onchange="toggleCustomColor(this, 'cards_item_head_color_custom_<?= $i ?>')">
                                <?php foreach (['accent'=>'Accent','header'=>'Header/Navy','custom'=>'Custom'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($block['cards_item_head_color'] ?? 'header') === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="color" id="cards_item_head_color_custom_<?= $i ?>" name="cards_item_head_color_custom[]" value="<?= h($block['cards_item_head_color_custom'] ?? '#1a1a2e') ?>" style="display:<?= ($block['cards_item_head_color'] ?? 'header') === 'custom' ? 'inline-block' : 'none' ?>;">
                        </div>
                        <div class="form-group">
                            <label>Card text color</label>
                            <input type="color" name="cards_text_color[]" value="<?= $ctcVal ?>" oninput="this.nextElementSibling.value=this.value;">
                            <input type="text" value="<?= $ctcVal ?>" placeholder="#333333" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                        </div>
                    </div>
                    <div class="cards-items-editor" id="cards_items_<?= $i ?>">
                        <?php foreach (($block['cards_items'] ?? []) as $ci => $card): ?>
                        <div class="card-item-row">
                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                                <div style="flex:0 0 100px;">
                                    <div class="form-group">
                                        <label>Image</label>
                                        <?php if (!empty($card['image'])): ?>
                                            <img src="/<?= h($card['image']) ?>" style="max-height:60px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="cards_image[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                                        <input type="hidden" id="cards_img_<?= $i ?>_<?= $ci ?>" name="cards_image_existing[<?= $i ?>][]" value="<?= h($card['image'] ?? '') ?>">
                                        <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('cards_img_<?= $i ?>_<?= $ci ?>');if(i)i.value=url;})">📷 Library</button>
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="cards_alt[<?= $i ?>][]" value="<?= h($card['alt'] ?? '') ?>" placeholder="Image description" style="font-size:0.82rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Heading</label>
                                        <input type="text" name="cards_heading_item[<?= $i ?>][]" value="<?= h($card['heading'] ?? '') ?>" placeholder="e.g. Water Damage Repair">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="cards_text[<?= $i ?>][]" rows="2"><?= h($card['text'] ?? '') ?></textarea>
                                    </div>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <div class="form-group" style="flex:1 1 140px;">
                                            <label>Link URL</label>
                                            <input type="text" name="cards_link[<?= $i ?>][]" value="<?= h($card['link'] ?? '') ?>" placeholder="/service-page">
                                        </div>
                                        <div class="form-group" style="flex:1 1 100px;">
                                            <label>Button text</label>
                                            <input type="text" name="cards_btn[<?= $i ?>][]" value="<?= h($card['btn_text'] ?? 'Read More') ?>" placeholder="Read More">
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeCardItem(this)" style="align-self:flex-start;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addCardItem(this, <?= $i ?>)">+ Add card</button>
                </div>

                <?php /* ---- PRICING CARDS FIELDS ---- */ ?>
                <div class="block-fields block-fields-pricing_cards <?= $type !== 'pricing_cards' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;">
                        <div class="form-group" style="flex:1 1 220px;">
                            <label>Section heading (optional)</label>
                            <input type="text" name="pc_heading[]" value="<?= h($block['pc_heading'] ?? '') ?>" placeholder="e.g. Six Classes. Every Career Stage.">
                        </div>
                        <div class="form-group" style="flex:0 0 120px;">
                            <label>Columns</label>
                            <select name="pc_cols[]">
                                <?php foreach ([2,3,4] as $n): ?>
                                    <option value="<?= $n ?>" <?= ($block['pc_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 140px;">
                            <label>Background color</label>
                            <input type="color" name="pc_bg[]" value="<?= h($block['pc_bg'] ?? '#f8fafc') ?>">
                        </div>
                    </div>
                    <div class="pc-items-editor" id="pc_items_<?= $i ?>">
                        <?php foreach (($block['pc_items'] ?? []) as $ci => $card): ?>
                        <div class="pc-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:1 1 180px;">
                                    <div class="form-group"><label>Card name / title</label>
                                        <input type="text" name="pc_name[<?= $i ?>][]" value="<?= h($card['name'] ?? '') ?>" placeholder="e.g. PMP®">
                                    </div>
                                    <div class="form-group"><label>Badge text <span class="hint">(optional — shown at top)</span></label>
                                        <input type="text" name="pc_badge[<?= $i ?>][]" value="<?= h($card['badge'] ?? '') ?>" placeholder="e.g. MOST POPULAR">
                                    </div>
                                    <div class="form-group"><label>Sub-label <span class="hint">(optional)</span></label>
                                        <input type="text" name="pc_sublabel[<?= $i ?>][]" value="<?= h($card['sublabel'] ?? '') ?>" placeholder="e.g. PMI FLAGSHIP CREDENTIAL">
                                    </div>
                                    <div class="form-group">
                                        <label><input type="checkbox" name="pc_featured[<?= $i ?>][]" value="<?= $ci ?>" <?= !empty($card['featured']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">Featured / highlighted card</label>
                                    </div>
                                </div>
                                <div style="flex:2 1 240px;">
                                    <div class="form-group"><label>Description</label>
                                        <textarea name="pc_desc[<?= $i ?>][]" rows="2"><?= h($card['desc'] ?? '') ?></textarea>
                                    </div>
                                    <div class="form-group"><label>Feature checklist <span class="hint">(one item per line)</span></label>
                                        <textarea name="pc_features[<?= $i ?>][]" rows="4" style="font-size:0.85rem;"><?= h($card['features'] ?? '') ?></textarea>
                                    </div>
                                    <div class="form-group"><label>Meta line <span class="hint">(e.g. 4 Days · Live online)</span></label>
                                        <input type="text" name="pc_meta[<?= $i ?>][]" value="<?= h($card['meta'] ?? '') ?>" placeholder="4 Days · Live online · Flexible scheduling">
                                    </div>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <div class="form-group" style="flex:1 1 100px;"><label>Button text</label>
                                            <input type="text" name="pc_btn_text[<?= $i ?>][]" value="<?= h($card['btn_text'] ?? 'Get Started') ?>">
                                        </div>
                                        <div class="form-group" style="flex:2 1 160px;"><label>Button URL</label>
                                            <input type="text" name="pc_btn_url[<?= $i ?>][]" value="<?= h($card['btn_url'] ?? '') ?>" placeholder="/enroll">
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="this.closest('.pc-item-row').remove()" style="align-self:flex-start;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addPcItem(this, <?= $i ?>)">+ Add card</button>
                </div>

                <?php /* ---- BUTTONS GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-buttons_grid <?= $type !== 'buttons_grid' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Heading (optional)</label>
                        <input type="text" name="bg_heading[]" value="<?= h($block['bg_heading'] ?? '') ?>" placeholder="e.g. Our Services">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 130px;">
                            <label>Background color</label>
                            <input type="color" name="bg_bg_color[]" value="<?= h($block['bg_bg_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group" style="flex:0 0 100px;">
                            <label>Columns</label>
                            <select name="bg_cols[]">
                                <option value="2" <?= (int)($block['bg_cols'] ?? 3) === 2 ? 'selected' : '' ?>>2</option>
                                <option value="3" <?= (int)($block['bg_cols'] ?? 3) === 3 ? 'selected' : '' ?>>3</option>
                                <option value="4" <?= (int)($block['bg_cols'] ?? 3) === 4 ? 'selected' : '' ?>>4</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 120px;">
                            <label>Button style</label>
                            <select name="bg_style[]">
                                <option value="filled"  <?= ($block['bg_style'] ?? 'filled') === 'filled'  ? 'selected' : '' ?>>Filled</option>
                                <option value="outline" <?= ($block['bg_style'] ?? '') === 'outline' ? 'selected' : '' ?>>Outline</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0 0 140px;">
                            <label>Button color</label>
                            <select name="bg_color[]">
                                <option value="accent" <?= ($block['bg_color'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['bg_color'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['bg_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="bg_color_custom[]" value="<?= h($block['bg_color_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                    </div>
                    <div id="bg_items_<?= $i ?>">
                        <?php foreach (($block['bg_items'] ?? [['label'=>'','url'=>'']]) as $bitem): ?>
                        <div class="repeat-row" style="display:flex;gap:10px;align-items:center;margin-bottom:8px;">
                            <input type="text" name="bg_label[<?= $i ?>][]" value="<?= h($bitem['label'] ?? '') ?>" placeholder="e.g. Termite Treatment" style="flex:1;">
                            <input type="text" name="bg_url[<?= $i ?>][]"   value="<?= h($bitem['url']   ?? '') ?>" placeholder="/termite-treatment" style="flex:1;">
                            <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addBgItem(this, <?= $i ?>)">+ Add button</button>
                </div>

                <?php /* ---- TESTIMONIALS FIELDS ---- */ ?>
                <div class="block-fields block-fields-testimonials <?= $type !== 'testimonials' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="tm_heading[]" value="<?= h($block['tm_heading'] ?? '') ?>" placeholder="e.g. What Our Customers Say">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 130px;">
                            <label>Background color</label>
                            <input type="color" name="tm_bg_color[]" value="<?= h($block['tm_bg_color'] ?? '#f8fafc') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 130px;">
                            <label>Text color</label>
                            <input type="color" name="tm_text_color[]" value="<?= h($block['tm_text_color'] ?? '#374151') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 130px;">
                            <label>Star color</label>
                            <select name="tm_accent[]">
                                <option value="accent" <?= ($block['tm_accent'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['tm_accent'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['tm_accent'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="tm_accent_custom[]" value="<?= h($block['tm_accent_custom'] ?? '#f59e0b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:0 0 90px;">
                            <label>Columns</label>
                            <select name="tm_cols[]">
                                <option value="2" <?= (int)($block['tm_cols'] ?? 3) === 2 ? 'selected' : '' ?>>2</option>
                                <option value="3" <?= (int)($block['tm_cols'] ?? 3) === 3 ? 'selected' : '' ?>>3</option>
                            </select>
                        </div>
                    </div>
                    <div class="tm-items-editor" id="tm_items_<?= $i ?>">
                        <?php $tmItems = $block['tm_items'] ?? [['quote' => '', 'name' => '', 'location' => '']]; ?>
                        <?php foreach ($tmItems as $titem): ?>
                        <div class="faq-item-row">
                            <div class="form-group">
                                <label>Review text</label>
                                <textarea name="tm_quote[<?= $i ?>][]" rows="3" placeholder="e.g. Fast, professional service. Got rid of our ant problem in one visit."><?= h($titem['quote'] ?? '') ?></textarea>
                            </div>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                <div class="form-group" style="flex:1 1 160px;">
                                    <label>Customer name</label>
                                    <input type="text" name="tm_name[<?= $i ?>][]" value="<?= h($titem['name'] ?? '') ?>" placeholder="e.g. Sarah M.">
                                </div>
                                <div class="form-group" style="flex:1 1 160px;">
                                    <label>Location (optional)</label>
                                    <input type="text" name="tm_location[<?= $i ?>][]" value="<?= h($titem['location'] ?? '') ?>" placeholder="e.g. Katy, TX">
                                </div>
                            </div>
                            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)" style="margin-bottom:12px;">Remove review</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addTmItem(this, <?= $i ?>)">+ Add review</button>
                </div>

                <?php /* ---- VIDEO FIELDS ---- */ ?>
                <div class="block-fields block-fields-video <?= $type !== 'video' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Heading (optional)</label>
                        <input type="text" name="vid_heading[]" value="<?= h($block['vid_heading'] ?? '') ?>" placeholder="e.g. See Us In Action">
                    </div>
                    <div class="form-group">
                        <label>YouTube or Vimeo URL</label>
                        <input type="url" name="vid_url[]" value="<?= h($block['vid_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=...">
                        <span class="hint">Paste a YouTube or Vimeo link — the embed is generated automatically.</span>
                    </div>
                    <div class="form-group">
                        <label>Caption (optional)</label>
                        <input type="text" name="vid_caption[]" value="<?= h($block['vid_caption'] ?? '') ?>" placeholder="Optional text shown below the video">
                    </div>
                    <div class="form-group">
                        <label>Width</label>
                        <select name="vid_width[]">
                            <option value="contained" <?= ($block['vid_width'] ?? 'contained') === 'contained' ? 'selected' : '' ?>>Contained (800px max)</option>
                            <option value="full"      <?= ($block['vid_width'] ?? '') === 'full' ? 'selected' : '' ?>>Full width</option>
                        </select>
                    </div>
                </div>

            </div><!-- .block-card -->
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-small" onclick="addBlock()">+ Add content block</button>
    </div>

    <?php /* ---- BLOCK PICKER MODAL ---- */ ?>
    <div id="block-picker-modal" role="dialog" aria-modal="true" aria-label="Choose block type">
        <div class="bp-overlay" onclick="closeBlockPicker()"></div>
        <div class="bp-panel">
            <button type="button" class="bp-close" onclick="closeBlockPicker()" aria-label="Close">&times;</button>
            <h3 style="margin:0 0 18px;font-size:1.1rem;">Choose a block type</h3>
            <?php foreach (grouped_block_types() as $gLabel => $gItems): ?>
            <div class="bp-group">
                <div class="bp-group-label"><?= h($gLabel) ?></div>
                <div class="bp-grid">
                    <?php foreach ($gItems as $bKey => $bLabel):
                        $svg = $thumbs[$bKey] ?? $thumbs['text'];
                    ?>
                    <div class="bp-card" data-type="<?= h($bKey) ?>"
                         onclick="selectBlockType('<?= h($bKey) ?>')"
                         onkeydown="if(event.key==='Enter'||event.key===' ')selectBlockType('<?= h($bKey) ?>')"
                         role="button" tabindex="0">
                        <span class="bp-card-thumb"><?= $svg ?></span>
                        <div class="bp-card-name"><?= h($bLabel) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/* helper: photo upload sub-form (used inside image_left/right fields) */
function photo_picker_btn(string $hiddenName, string $previewId = '', string $inputId = ''): void {
    $safe = htmlspecialchars($hiddenName, ENT_QUOTES, 'UTF-8');
    $pid  = $previewId ? htmlspecialchars($previewId, ENT_QUOTES, 'UTF-8') : '';
    $iid  = $inputId   ? htmlspecialchars($inputId,   ENT_QUOTES, 'UTF-8') : '';
    // Use getElementById when an inputId is provided (precise targeting for repeated block fields).
    // Fall back to querySelector for unique names like popup_info_image_existing.
    $setInput = $iid
        ? "var i=document.getElementById('{$iid}');if(i)i.value=url;"
        : "var i=document.querySelector('[name=\"{$safe}\"]');if(i)i.value=url;";
    $js = "openImgPicker(function(url,alt){"
        . $setInput
        . ($pid ? "var p=document.getElementById('{$pid}');if(p){p.innerHTML='<img src=\"../'+url+'\" style=\"max-height:80px;border-radius:4px;margin-bottom:6px;display:block;\">';}" : "")
        . "})";
    echo '<button type="button" class="btn btn-small btn-secondary" style="margin-left:8px;" onclick="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '">📷 Library</button>';
}

function render_photo_upload_fields($fieldBaseName, $existingPhoto, $ratio, $position, $alt, $index) {
    $uid = 'pf_' . $index . '_' . substr(md5($fieldBaseName.$index), 0, 4);
    ?>
    <div class="form-group">
        <label>Image alt text (SEO)</label>
        <input type="text" name="block_photo_alt[]" value="<?= h($alt) ?>" placeholder="Describe the image">
    </div>
    <div class="current-image" id="preview_<?= h($uid) ?>">
        <?php if (!empty($existingPhoto)): ?>
            <img src="../<?= h($existingPhoto) ?>" alt="Block image">
        <?php else: ?>
            <span class="none">No image uploaded yet.</span>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
        <label style="margin:0;">Upload image</label>
        <button type="button" class="btn btn-small btn-secondary" onclick="openImgPicker(function(url,alt){setBlockPhoto('<?= h($uid) ?>',url,alt)})">📷 Pick from Library</button>
    </div>
    <input type="file" name="<?= $fieldBaseName ?>[]" accept="image/png,image/jpeg,image/gif,image/webp">
    <input type="hidden" id="existing_<?= h($uid) ?>" name="block_existing_photo[]" value="<?= h($existingPhoto) ?>">
    <?php if (!empty($existingPhoto)): ?>
        <label style="margin-top:8px;font-weight:400;">
            <input type="checkbox" name="block_remove_photo[]" value="1"> Remove current image
        </label>
    <?php else: ?>
        <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
    <?php endif; ?>
    <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
        <div style="flex:1 1 160px;">
            <label>Picture shape</label>
            <select name="block_photo_ratio[]"><?= photo_ratio_options_html($ratio) ?></select>
        </div>
        <div style="flex:1 1 160px;">
            <label>Crop focus</label>
            <select name="block_photo_position[]"><?= photo_position_options_html($position) ?></select>
        </div>
    </div>
    <?php
}
