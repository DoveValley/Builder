<?php
/* ============================================================
   ADMIN: JS for the block editor
   ============================================================ */
function content_editor_scripts() {
    $blockTypes = json_encode(array_keys(allowed_block_types()));
    ?>
    <script>
    const BLOCK_TYPES = <?= $blockTypes ?>;

    /* Show only the fields panel matching the selected block type */
    function switchBlockType(select) {
        const card = select.closest('.block-card');
        BLOCK_TYPES.forEach(t => {
            const panel = card.querySelector('.block-fields-' + t);
            if (panel) panel.classList.toggle('is-hidden', t !== select.value);
        });
        card.dataset.blockType = select.value;
    }

    /* Legacy alias */
    function toggleBlockImage(select) { switchBlockType(select); }

    /* Move block up/down */
    function moveBlock(btn, dir) {
        const card = btn.closest('.block-card');
        const container = card.parentElement;
        if (dir < 0) { const prev = card.previousElementSibling; if (prev) container.insertBefore(card, prev); }
        else         { const next = card.nextElementSibling;     if (next) container.insertBefore(next, card); }
    }

    /* Remove a block */
    function removeBlock(btn) {
        const container = document.getElementById('content-blocks');
        const card = btn.closest('.block-card');
        if (container.children.length > 1) card.remove();
    }

    /* Add a new blank block */
    function addBlock() {
        const container = document.getElementById('content-blocks');
        const idx = container.children.length;
        const card = document.createElement('div');
        card.className = 'block-card';
        card.dataset.blockType = 'text';

        let typeOptions = '';
        <?php foreach (allowed_block_types() as $k => $l): ?>
        typeOptions += `<option value="<?= h($k) ?>"><?= h($l) ?></option>`;
        <?php endforeach; ?>

        card.innerHTML = `
            <div class="block-card-header">
                <span class="block-label">New block</span>
                <select name="block_type[]" class="block-type-select" onchange="switchBlockType(this)">
                    ${typeOptions}
                </select>
                <input type="text" name="block_anchor[]"
                       placeholder="Section ID (e.g. about)"
                       title="Anchor ID — use in menu links as #about"
                       style="flex:1 1 160px;max-width:200px;font-size:0.82rem;padding:6px 10px;">
                <div class="block-actions">
                    <button type="button" class="icon-btn" onclick="moveBlock(this,-1)">&uarr;</button>
                    <button type="button" class="icon-btn" onclick="moveBlock(this,1)">&darr;</button>
                    <button type="button" class="icon-btn remove-row" onclick="removeBlock(this)">Remove</button>
                </div>
            </div>
            <div class="block-fields block-fields-text">
                <input type="hidden" name="block_photo_alt[]" value="">
                <input type="hidden" name="block_existing_photo[]" value="">
                <input type="hidden" name="block_photo_ratio[]" value="landscape">
                <input type="hidden" name="block_photo_position[]" value="center">
                <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                <div class="form-group">
                    <label>Heading level</label>
                    <select name="block_heading_level[]">
                        <option value="h1">H1 (Page title)</option>
                        <option value="h2" selected>H2 (Section heading)</option>
                        <option value="h3">H3 (Sub-section)</option>
                        <option value="p">Paragraph (no heading)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Text</label>
                    <textarea name="block_text[]" rows="5" placeholder="Write the text for this block..."></textarea>
                    <span class="hint">First line = heading. Leave a blank line between paragraphs.</span>
                </div>
            </div>
            <div class="block-fields block-fields-image_left block-fields-image_right is-hidden">
                <div class="form-group"><label>Text</label><textarea name="block_text[]" rows="4"></textarea></div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="block_photo_alt[]" placeholder="Describe the image"></div>
                <div class="current-image"><span class="none">No image uploaded yet.</span></div>
                <label>Upload image</label>
                <input type="file" name="block_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                <input type="hidden" name="block_existing_photo[]" value="">
                <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
                    <div style="flex:1 1 160px;"><label>Picture shape</label>
                        <select name="block_photo_ratio[]">
                            <option value="landscape" selected>Horizontal rectangle</option>
                            <option value="square">Square</option>
                            <option value="portrait">Vertical rectangle</option>
                            <option value="auto">Original size</option>
                        </select>
                    </div>
                    <div style="flex:1 1 160px;"><label>Crop focus</label>
                        <select name="block_photo_position[]">
                            <option value="center" selected>Center</option>
                            <option value="top">Top</option>
                            <option value="bottom">Bottom</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-hero is-hidden">
                <div class="form-group"><label>Headline (H1)</label><input type="text" name="hero_heading[]" placeholder="Main page headline"></div>
                <div class="form-group"><label>Subtext</label><textarea name="hero_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="hero_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="hero_btn_url[]"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background color</label><input type="color" name="hero_bg_color[]" value="#1e3a5f"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Text color</label><input type="color" name="hero_text_color[]" value="#ffffff"></div>
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="hero_bg_image[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hero_bg_image_existing[]" value="">
                </div>
            </div>
            <div class="block-fields block-fields-hero_split is-hidden">
                <div class="form-group"><label>H1 Headline</label><input type="text" name="hs_heading[]" placeholder="e.g. Trusted Local Pest Control in Katy, TX"></div>
                <div class="form-group"><label>Paragraph text</label><textarea name="hs_subtext[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Button text</label><input type="text" name="hs_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Button link</label><input type="text" name="hs_btn_url[]" placeholder="tel:+1..."></div>
                </div>
                <div class="form-group"><label>Background color</label><input type="color" name="hs_bg_color[]" value="#f3f6f7"></div>
                <div class="form-group"><label>Right-side image</label>
                    <input type="file" name="hs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hs_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="hs_photo_alt[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Caption line 1</label><input type="text" name="hs_caption1[]" placeholder="e.g. Pest Control"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Caption line 2</label><input type="text" name="hs_caption2[]" placeholder="e.g. Katy, TX"></div>
                </div>
            </div>
            <div class="block-fields block-fields-feature_split is-hidden">
                <div class="form-group"><label>Section heading</label><input type="text" name="fs_heading[]" placeholder="e.g. Full-Service Pest Management"></div>
                <div class="form-group"><label>Intro paragraph</label><textarea name="fs_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background color</label><input type="color" name="fs_bg_color[]" value="#f3f6f7"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Item heading color</label><input type="color" name="fs_accent[]" value="#fd783b"></div>
                </div>
                <div class="fs-items-editor" id="fs_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFsItem(this, 'new_${idx}')">+ Add item</button>
                <div class="form-group" style="margin-top:16px;"><label>Image side</label>
                    <select name="fs_image_side[]"><option value="right" selected>Image on right</option><option value="left">Image on left</option></select>
                </div>
                <div class="form-group"><label>Image</label>
                    <input type="file" name="fs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="fs_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="fs_photo_alt[]"></div>
                <div class="form-group"><label>Star badge text</label><input type="text" name="fs_star_text[]" placeholder="e.g. 5 Star Services"></div>
            </div>
            <div class="block-fields block-fields-feature_columns is-hidden">
                <div class="form-group"><label>Section heading</label><input type="text" name="fc_heading[]" placeholder="e.g. Our Services\"></div>
                <div class="form-group"><label>Number of columns</label>
                    <select name="fc_num_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div class="fc-columns-editor" id="fc_cols_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFcCol(this, 'new_${idx}')">+ Add column item</button>
            </div>
            <div class="block-fields block-fields-split_cta is-hidden">
                <div class="form-group"><label>Left heading</label><input type="text" name="sc_left_heading[]" placeholder="e.g. Serving the Greater Katy, TX Area"></div>
                <div class="form-group"><label>Left text</label><textarea name="sc_left_text[]" rows="3"></textarea></div>
                <div class="form-group"><label>Left background</label>
                    <select name="sc_left_bg[]"><option value="accent" selected>Accent (global)</option><option value="header">Header color (global)</option><option value="custom">Custom</option></select>
                </div>
                <div class="form-group"><label>Custom left color</label><input type="color" name="sc_left_bg_custom[]" value="#fd783b"></div>
                <div class="form-group"><label>Right label text</label><input type="text" name="sc_right_label[]" placeholder="e.g. Call The Katy Pest Pros Team"></div>
                <div class="form-group"><label>Right phone number</label><input type="text" name="sc_right_phone[]" placeholder="e.g. (281) 215-0160"></div>
                <div class="form-group"><label>Right phone link</label><input type="text" name="sc_right_phone_url[]" placeholder="tel:+12812150160"></div>
                <div class="form-group"><label>Right background</label>
                    <select name="sc_right_bg[]"><option value="header" selected>Header color (global)</option><option value="accent">Accent (global)</option><option value="custom">Custom</option></select>
                </div>
                <div class="form-group"><label>Custom right color</label><input type="color" name="sc_right_bg_custom[]" value="#120575"></div>
            </div>
            <div class="block-fields block-fields-cta_button is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="cta_text[]" value="Contact Us"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="cta_url[]" value="#"></div>
                </div>
                <div class="form-group"><label>Optional text above button</label><input type="text" name="cta_subtext[]"></div>
                <div class="form-group"><label>Alignment</label>
                    <select name="cta_align[]"><option value="left">Left</option><option value="center" selected>Center</option><option value="right">Right</option></select>
                </div>
            </div>
            <div class="block-fields block-fields-image_text is-hidden">
                <div class="form-group"><label>Image side</label>
                    <select name="it_image_side[]"><option value="left" selected>Image left, text right</option><option value="right">Text left, image right</option></select>
                </div>
                <div class="form-group"><label>Heading level</label>
                    <select name="it_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="p">Paragraph</option></select>
                </div>
                <div class="form-group"><label>Heading</label><input type="text" name="it_heading[]"></div>
                <div class="form-group"><label>Text</label><textarea name="it_text[]" rows="4"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="it_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="it_btn_url[]"></div>
                </div>
                <div class="form-group"><label>Image</label>
                    <input type="file" name="it_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="it_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="it_alt[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1 1 160px;"><label>Picture shape</label>
                        <select name="it_ratio[]"><option value="landscape" selected>Horizontal</option><option value="square">Square</option><option value="portrait">Vertical</option><option value="auto">Original</option></select>
                    </div>
                    <div style="flex:1 1 160px;"><label>Crop focus</label>
                        <select name="it_position[]"><option value="center" selected>Center</option><option value="top">Top</option><option value="bottom">Bottom</option></select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-faq is-hidden">
                <div class="form-group"><label>Section heading</label><input type="text" name="faq_heading[]" placeholder="e.g. Frequently Asked Questions"></div>
                <div class="faq-items-editor" id="faq_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFaqItem(this, 'new_${idx}')">+ Add Q&amp;A</button>
            </div>
            <div class="block-fields block-fields-custom_html is-hidden">
                <div class="form-group"><label>Custom HTML / Embed code</label>
                    <textarea name="custom_html[]" rows="6" style="font-family:monospace;font-size:0.83rem;"></textarea>
                    <span class="hint">Paste maps, widgets, scripts, etc.</span>
                </div>
            </div>
            <div class="block-fields block-fields-cta_card is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="cc_heading[]" placeholder="e.g. Contact Katy's Top Pest Control Company Today"></div>
                <div class="form-group"><label>Text</label><textarea name="cc_text[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Button text</label><input type="text" name="cc_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Button link</label><input type="text" name="cc_btn_url[]" placeholder="tel:+1..."></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Style</label>
                        <select name="cc_btn_style[]"><option value="outline" selected>Outline</option><option value="filled">Filled</option></select>
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Background</label>
                        <select name="cc_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="cc_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Border radius (px)</label><input type="number" name="cc_radius[]" value="12" min="0" max="40"></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Layout</label>
                        <select name="cc_align[]"><option value="split" selected>Split</option><option value="center">Centered</option></select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-map_info is-hidden">
                <div class="form-group"><label>Heading color</label>
                    <select name="mi_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                    <input type="color" name="mi_head_color_custom[]" value="#120575" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Map panel heading</label><input type="text" name="mi_map_heading[]" placeholder="e.g. Katy, Texas Map"></div>
                <div class="form-group"><label>Google Maps embed code</label>
                    <textarea name="mi_map_embed[]" rows="3" placeholder="Paste &lt;iframe&gt; embed code here"></textarea>
                </div>
                <div class="form-group"><label>Info panel heading</label><input type="text" name="mi_info_heading[]" placeholder="e.g. Katy, TX Information"></div>
                <div class="form-group"><label>Info text</label><textarea name="mi_info_text[]" rows="3"></textarea></div>
                <div class="form-group"><label>Info photo</label>
                    <input type="file" name="mi_info_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="mi_info_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Photo alt text</label><input type="text" name="mi_info_alt[]"></div>
            </div>
            <div class="block-fields block-fields-links_grid is-hidden">
                <div class="form-group"><label>Style</label>
                    <select name="lg_style[]" onchange="this.closest('.block-fields').querySelectorAll('.lg-dark-only,.lg-light-only').forEach(el=>el.style.display=this.value==='dark'?'':'none');this.closest('.block-fields').querySelectorAll('.lg-light-only').forEach(el=>el.style.display=this.value==='light'?'':'none')">
                        <option value="dark" selected>Dark (bg image + overlay)</option>
                        <option value="light">Light (white/colored bg)</option>
                    </select>
                </div>
                <div class="lg-light-only" style="display:none;">
                    <div class="form-group"><label>Small label text</label><input type="text" name="lg_sublabel[]" placeholder="e.g. Top Rated Katy, TX Pest Experts"></div>
                    <div class="form-group"><label>Background color</label><input type="color" name="lg_bg_color[]" value="#ffffff"></div>
                    <div class="form-group"><label>Accent color</label>
                        <select name="lg_accent[]"><option value="accent" selected>Accent (global)</option><option value="header">Header (global)</option><option value="custom">Custom</option></select>
                        <input type="color" name="lg_accent_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                </div>
                <div class="form-group"><label>Heading</label><input type="text" name="lg_heading[]" placeholder="Our Pest Control Services in Katy, TX"></div>
                <div class="lg-dark-only">
                    <div class="form-group"><label>Subtext</label><textarea name="lg_subtext[]" rows="2"></textarea></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Columns</label>
                        <select name="lg_cols[]"><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5" selected>5</option><option value="6">6</option></select>
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Overlay opacity</label>
                        <input type="range" name="lg_overlay[]" min="0" max="0.9" step="0.05" value="0.6" style="width:100%;">
                    </div>
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="lg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="lg_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="lg_photo_alt[]"></div>
                <div class="lg-links-editor" id="lg_links_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addLgLink(this, 'new_${idx}')">+ Add link</button>
                <div class="form-group" style="margin-top:10px;">
                    <label>Bulk add (one per line)</label>
                    <textarea id="lg_bulk_new_${idx}" rows="3" placeholder="Service 1&#10;Service 2"></textarea>
                    <button type="button" class="btn btn-secondary btn-small" style="margin-top:4px;" onclick="bulkAddLgLinks('new_${idx}')">Add all</button>
                </div>
            </div>
            <div class="block-fields block-fields-cta_banner is-hidden">
                <div class="form-group"><label>Banner text</label><input type="text" name="cb_text[]" placeholder="e.g. 24/7 Pest Control Services in Katy, TX"></div>
                <div class="form-group"><label>Subtext (optional)</label><input type="text" name="cb_subtext[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Button text</label><input type="text" name="cb_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Button link</label><input type="text" name="cb_btn_url[]"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Background</label>
                        <select name="cb_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="cb_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Text color</label><input type="color" name="cb_text_color[]" value="#ffffff"></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Padding</label>
                        <select name="cb_padding[]"><option value="compact">Compact</option><option value="normal" selected>Normal</option><option value="large">Large</option></select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-faq_two_col is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="fq_heading[]" placeholder="e.g. FAQs – Pest Control in Katy"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 120px;"><label>Background</label><input type="color" name="fq_bg_color[]" value="#ffffff"></div>
                    <div class="form-group" style="flex:1 1 120px;"><label>Item box color</label><input type="color" name="fq_item_bg[]" value="#f0f2f8"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <select name="fq_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                        <input type="color" name="fq_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Icon color</label>
                        <select name="fq_icon_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="fq_icon_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                </div>
                <div class="fq-items-editor" id="fq_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFqItem(this, 'new_${idx}')">+ Add Q&amp;A</button>
            </div>
            <div class="block-fields block-fields-image_features is-hidden">
                <div class="form-group"><label>Background color</label><input type="color" name="if_bg_color[]" value="#f3f6f7"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Check color</label>
                        <select name="if_check_color[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="if_check_color_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <select name="if_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                        <input type="color" name="if_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                </div>
                <div class="form-group"><label>Photo</label>
                    <input type="file" name="if_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="if_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Photo alt text</label><input type="text" name="if_photo_alt[]"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="if_heading[]" placeholder="e.g. Quality Pest Prevention"></div>
                <div class="form-group"><label>Intro paragraph</label><textarea name="if_intro[]" rows="3"></textarea></div>
                <div class="if-feats-editor" id="if_feats_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addIfFeat(this, 'new_${idx}')">+ Add feature</button>
                <div class="form-group" style="margin-top:10px;"><label>Closing paragraph</label><textarea name="if_closing[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Phone label</label><input type="text" name="if_phone_label[]" placeholder="Call Us 24/7"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Phone number</label><input type="text" name="if_phone[]" placeholder="(281) 215-0160"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Phone link</label><input type="text" name="if_phone_url[]" placeholder="tel:+1..."></div>
                </div>
            </div>
            <div class="block-fields block-fields-wide_banner is-hidden">
                <div class="form-group"><label>Badge text</label><input type="text" name="wb_badge[]" placeholder="e.g. KATY, TEXAS'S SPECIALISTS"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="wb_heading[]" placeholder="Your First Choice For Katy Pest Pros in Katy, TX"></div>
                <div class="form-group"><label>Subtext (optional)</label><textarea name="wb_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="wb_btn_text[]" placeholder="e.g. Call Us"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="wb_btn_url[]" placeholder="tel:+1..."></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Button style</label>
                        <select name="wb_btn_style[]"><option value="filled" selected>Filled</option><option value="outline">Outline</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Badge / button color</label>
                    <select name="wb_badge_bg[]"><option value="accent" selected>Accent (global)</option><option value="header">Header (global)</option><option value="custom">Custom</option></select>
                    <input type="color" name="wb_badge_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Solid background color</label>
                        <input type="color" name="wb_bg_color[]" value="#1a1a2e">
                        <span class="hint">Used when no image is set.</span>
                    </div>
                    <div class="form-group" style="flex:1 1 160px;padding-top:22px;">
                        <label><input type="checkbox" name="wb_centered[]" value="1"> Center all text</label>
                    </div>
                </div>
                <div class="form-group"><label>Background image (overrides solid color)</label>
                    <input type="file" name="wb_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="wb_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="wb_photo_alt[]"></div>
                <div class="form-group"><label>Overlay opacity (0=none, 0.9=very dark)</label>
                    <input type="range" name="wb_overlay[]" min="0" max="0.9" step="0.05" value="0.55" style="width:100%;">
                </div>
            </div>
            <div class="block-fields block-fields-service_cards is-hidden">
                <div class="form-group"><label>Badge text</label><input type="text" name="sc_badge[]" placeholder="e.g. PROFESSIONAL KATY, TX COMPANY"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="sc_heading[]" placeholder="e.g. Local Experts in Katy, TX"></div>
                <div class="form-group"><label>Columns</label>
                    <select name="sc_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Badge color</label>
                        <select name="sc_badge_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="sc_badge_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <select name="sc_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                        <input type="color" name="sc_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Icon circle bg</label>
                        <input type="color" name="sc_icon_bg[]" value="#fef0e7">
                    </div>
                </div>
                <div class="sc-items-editor" id="sc_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addScItem(this, 'new_${idx}')">+ Add card</button>
            </div>
            <div class="block-fields block-fields-hero_grid is-hidden">
                <div class="form-group"><label>Small label</label><input type="text" name="hg_label[]" placeholder="e.g. Katy Pest Pros"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="hg_heading[]" placeholder="Section heading"></div>
                <div class="form-group"><label>Body text</label><textarea name="hg_body[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="hg_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="hg_btn_url[]" placeholder="tel:+1..."></div>
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="hg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hg_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="hg_photo_alt[]" placeholder="e.g. Pest control technician Katy TX"></div>
                <div class="form-group"><label>Odd tile color</label>
                    <select name="hg_color1[]"><option value="accent" selected>Accent (global)</option><option value="header">Header (global)</option><option value="custom">Custom</option></select>
                    <input type="color" name="hg_color1_custom[]" value="#fd783b" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Even tile color</label>
                    <select name="hg_color2[]"><option value="header" selected>Header (global)</option><option value="accent">Accent (global)</option><option value="custom">Custom</option></select>
                    <input type="color" name="hg_color2_custom[]" value="#120575" style="margin-top:4px;">
                </div>
                <div class="hg-items-editor" id="hg_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addHgItem(this, 'new_${idx}')">+ Add grid item</button>
            </div>
            <div class="block-fields block-fields-tab_services is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Badge 1</label><input type="text" name="ts_badge1[]" placeholder="KATY PEST PROS"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Badge 2</label><input type="text" name="ts_badge2[]" placeholder="SERVICES KATY, TX"></div>
                </div>
                <div class="form-group"><label>Heading</label><input type="text" name="ts_heading[]" placeholder="Section heading"></div>
                <div class="form-group"><label>Active tab background</label>
                    <select name="ts_active_bg[]"><option value="header" selected>Header color (global)</option><option value="accent">Accent (global)</option><option value="custom">Custom</option></select>
                </div>
                <div class="form-group"><label>Custom active color</label><input type="color" name="ts_active_bg_custom[]" value="#120575"></div>
                <div class="ts-tabs-editor" id="ts_tabs_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addTsTab(this, 'new_${idx}')">+ Add tab</button>
            </div>
            <div class="block-fields block-fields-gallery is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="gallery_heading[]" placeholder="e.g. Gallery of Projects">
                </div>
                <div class="form-group"><label>Number of columns</label>
                    <select name="gallery_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div class="gallery-images-editor" id="gallery_imgs_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addGalleryImg(this, 'new_${idx}')">+ Add image</button>
            </div>
            <div class="block-fields block-fields-steps is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="steps_heading[]" placeholder="e.g. Our Recovery Process">
                </div>
                <div class="steps-items-editor" id="steps_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addStepItem(this, 'new_${idx}')">+ Add step</button>
            </div>
            <div class="block-fields block-fields-stats is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="stats_heading[]" placeholder="e.g. Why Choose Us">
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background color</label>
                        <input type="color" name="stats_bg_color[]" value="#1e3a5f">
                    </div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Text color</label>
                        <input type="color" name="stats_text_color[]" value="#ffffff">
                    </div>
                </div>
                <div class="stats-items-editor" id="stats_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addStatItem(this, 'new_${idx}')">+ Add stat</button>
            </div>
            <div class="block-fields block-fields-cards is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="cards_heading[]" placeholder="e.g. Our Services">
                </div>
                <div class="form-group"><label>Number of columns</label>
                    <select name="cards_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div class="form-group"><label>Block background color</label>
                        <input type="color" name="cards_bg[]" value="#f3f6f7" oninput="var t=this.nextElementSibling;if(t)t.value=this.value;">
                        <input type="text" value="#f3f6f7" placeholder="#f3f6f7" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(c&&/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                    </div>
                    <div class="form-group"><label>Card background color</label>
                        <input type="color" name="cards_card_bg[]" value="#ffffff" oninput="var t=this.nextElementSibling;if(t)t.value=this.value;">
                        <input type="text" value="#ffffff" placeholder="#ffffff" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(c&&/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                    </div>
                    <div class="form-group"><label>Section heading color</label>
                        <select name="cards_head_color[]"><option value="accent">Accent</option><option value="header" selected>Header/Navy</option><option value="custom">Custom</option></select>
                    </div>
                    <div class="form-group"><label>Card heading color</label>
                        <select name="cards_item_head_color[]"><option value="accent">Accent</option><option value="header" selected>Header/Navy</option><option value="custom">Custom</option></select>
                    </div>
                    <div class="form-group"><label>Card text color</label>
                        <input type="color" name="cards_text_color[]" value="#333333" oninput="var t=this.nextElementSibling;if(t)t.value=this.value;">
                        <input type="text" value="#333333" placeholder="#333333" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(c&&/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                    </div>
                </div>
                <div class="cards-items-editor" id="cards_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addCardItem(this, 'new_${idx}')">+ Add card</button>
            </div>
        `;
        container.appendChild(card);
        // Init TinyMCE on any new rich-editor textareas in this card
        if (typeof tinymce !== 'undefined') {
            card.querySelectorAll('.rich-editor').forEach(function(ta) {
                if (!tinymce.get(ta.id)) {
                    tinymce.init({
                        target: ta,
                        menubar: false,
                        plugins: 'link lists autolink',
                        toolbar: 'bold italic underline | link | bullist numlist | removeformat',
                        height: 200,
                        branding: false,
                        promotion: false,
                        statusbar: false,
                        skin: 'oxide',
                        content_css: false,
                        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 14px; color: #1a1a1a; margin: 8px; }',
                        setup: function(editor) { editor.on('change input', function() { editor.save(); }); }
                    });
                }
            });
        }
    }

    /* ---- Feature Columns helpers ---- */
    function addFcCol(btn, blockIdx) {
        const editor = document.getElementById('fc_cols_' + blockIdx);
        const uid = 'fc_col_img_' + blockIdx + '_' + Date.now();
        const row = document.createElement('div');
        row.className = 'fc-col-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div class="form-group" style="flex:0 0 80px;">
                    <label>Icon/image</label>
                    <input type="file" name="fc_col_image[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                    <input type="hidden" id="${uid}" name="fc_col_image_existing[${blockIdx}][]" value="">
                    <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('${uid}');if(i)i.value=url;})">📷 Library</button>
                </div>
                <div style="flex:1 1 160px;">
                    <div class="form-group"><label>Heading (H3)</label><input type="text" name="fc_col_heading[${blockIdx}][]" placeholder="e.g. Ants"></div>
                    <div class="form-group"><label>Alt text</label><input type="text" name="fc_col_alt[${blockIdx}][]" placeholder="Image description"></div>
                </div>
                <div style="flex:2 1 200px;">
                    <div class="form-group"><label>Description</label><textarea name="fc_col_text[${blockIdx}][]" rows="2"></textarea></div>
                </div>
                <button type="button" class="remove-row" onclick="removeFcCol(this)" style="align-self:flex-start;margin-top:24px;">&times;</button>
            </div>
        `;
        editor.appendChild(row);
    }
    function removeFcCol(btn) {
        btn.closest('.fc-col-row').remove();
    }

    /* ---- Feature Split helpers ---- */
    function addFsItem(btn, blockIdx) {
        const editor = document.getElementById('fs_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'fs-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 90px;">
                    <div class="form-group"><label>Icon image</label>
                        <input type="file" name="fs_item_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                        <input type="hidden" name="fs_item_icon_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="fs_item_alt[${blockIdx}][]" placeholder="Icon description" style="font-size:0.8rem;">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Heading</label>
                        <input type="text" name="fs_item_heading[${blockIdx}][]" placeholder="e.g. Ants">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="fs_item_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeFsItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeFsItem(btn) { btn.closest('.fs-item-row').remove(); }

    /* ---- Tab Services helpers ---- */
    function addTsTab(btn, blockIdx) {
        const editor = document.getElementById('ts_tabs_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'ts-tab-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:10px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 110px;">
                    <div class="form-group"><label>Tab icon</label>
                        <input type="file" name="ts_tab_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                        <input type="hidden" name="ts_tab_icon_existing[${blockIdx}][]" value="">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Tab label</label>
                        <input type="text" name="ts_tab_label[${blockIdx}][]" placeholder="e.g. Fleas">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="ts_tab_desc[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <div style="flex:0 0 130px;">
                    <div class="form-group"><label>Tab photo</label>
                        <input type="file" name="ts_tab_photo[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.75rem;">
                        <input type="hidden" name="ts_tab_photo_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Photo alt text</label>
                        <input type="text" name="ts_tab_alt[${blockIdx}][]" placeholder="Alt text" style="font-size:0.8rem;">
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeTsTab(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeTsTab(btn) { btn.closest('.ts-tab-row').remove(); }

    /* ---- Hero Grid helpers ---- */
    function addHgItem(btn, blockIdx) {
        const editor = document.getElementById('hg_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'hg-item-row';
        row.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;';
        row.innerHTML = `
            <div style="flex:0 0 90px;">
                <label style="font-size:0.8rem;font-weight:600;">Icon</label>
                <input type="file" name="hg_item_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.72rem;">
                <input type="hidden" name="hg_item_icon_existing[${blockIdx}][]" value="">
            </div>
            <div style="flex:1 1 160px;">
                <label style="font-size:0.8rem;font-weight:600;">Label</label>
                <input type="text" name="hg_item_label[${blockIdx}][]" placeholder="e.g. Carpenter Ants">
                <label style="font-size:0.8rem;font-weight:600;margin-top:4px;display:block;">Alt text</label>
                <input type="text" name="hg_item_alt[${blockIdx}][]" placeholder="Icon alt text" style="font-size:0.8rem;">
            </div>
            <button type="button" class="remove-row" onclick="removeHgItem(this)">&times;</button>`;
        editor.appendChild(row);
    }
    function removeHgItem(btn) { btn.closest('.hg-item-row').remove(); }

    /* ---- Service Cards helpers ---- */
    function addScItem(btn, blockIdx) {
        const editor = document.getElementById('sc_items_' + blockIdx);
        const uid = 'sc_icon_' + blockIdx + '_' + Date.now();
        const row = document.createElement('div');
        row.className = 'sc-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 100px;">
                    <div class="form-group"><label>Icon image</label>
                        <input type="file" name="sc_item_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                        <input type="hidden" id="${uid}" name="sc_item_icon_existing[${blockIdx}][]" value="">
                        <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('${uid}');if(i)i.value=url;})">📷 Library</button>
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="sc_item_alt[${blockIdx}][]" style="font-size:0.8rem;">
                    </div>
                </div>
                <div style="flex:1 1 220px;">
                    <div class="form-group"><label>Card heading</label>
                        <input type="text" name="sc_item_heading[${blockIdx}][]" placeholder="e.g. Roach Control & Extermination">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="sc_item_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                    <div class="form-group"><label>Link URL <span class="hint">(optional — makes the card clickable)</span></label>
                        <input type="text" name="sc_item_url[${blockIdx}][]" placeholder="e.g. /cockroach-exterminator-katy-tx">
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeScItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeScItem(btn) { btn.closest('.sc-item-row').remove(); }

    /* ---- Image Features helpers ---- */
    function addIfFeat(btn, blockIdx) {
        const editor = document.getElementById('if_feats_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'if-feat-row';
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
        row.innerHTML = `
            <input type="text" name="if_features[${blockIdx}][]" placeholder="e.g. Exterior treatments" style="flex:1;">
            <button type="button" class="remove-row" onclick="removeIfFeat(this)">&times;</button>`;
        editor.appendChild(row);
    }
    function removeIfFeat(btn) { btn.closest('.if-feat-row').remove(); }

    /* ---- FAQ Two Col item helpers ---- */
    function addFqItem(btn, blockIdx) {
        const editor = document.getElementById('fq_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'fq-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div class="form-group"><label>Question</label>
                <input type="text" name="fq_question[${blockIdx}][]" placeholder="e.g. What types of pests do you treat?">
            </div>
            <div class="form-group"><label>Answer</label>
                <textarea name="fq_answer[${blockIdx}][]" rows="2"></textarea>
            </div>
            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFqItem(this)" style="margin-bottom:4px;">Remove Q&A</button>`;
        editor.appendChild(row);
    }
    function removeFqItem(btn) { btn.closest('.fq-item-row').remove(); }

    /* ---- Links Grid helpers ---- */
    function addLgLink(btn, blockIdx) {
        const editor = document.getElementById('lg_links_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'lg-link-row';
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
        row.innerHTML = `
            <input type="text" name="lg_link_label[${blockIdx}][]" placeholder="Link text" style="flex:1;">
            <input type="text" name="lg_link_url[${blockIdx}][]"   placeholder="URL e.g. /service-page" style="flex:1;">
            <button type="button" class="remove-row" onclick="removeLgLink(this)">&times;</button>`;
        editor.appendChild(row);
    }
    function removeLgLink(btn) { btn.closest('.lg-link-row').remove(); }
    function bulkAddLgLinks(blockIdx) {
        const textarea = document.getElementById('lg_bulk_' + blockIdx);
        const editor   = document.getElementById('lg_links_' + blockIdx);
        if (!textarea || !editor) return;
        const lines = textarea.value.split('\n').map(l => l.trim()).filter(Boolean);
        lines.forEach(function(label) {
            const row = document.createElement('div');
            row.className = 'lg-link-row';
            row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
            row.innerHTML = `
                <input type="text" name="lg_link_label[${blockIdx}][]" value="${label.replace(/"/g,'&quot;')}" style="flex:1;">
                <input type="text" name="lg_link_url[${blockIdx}][]" placeholder="/url" style="flex:1;">
                <button type="button" class="remove-row" onclick="removeLgLink(this)">&times;</button>`;
            editor.appendChild(row);
        });
        textarea.value = '';
    }

    /* ---- Tab Services frontend switcher ---- */
    function switchTab(btn) {
        const uid = btn.dataset.uid;
        const layout = document.getElementById(uid);
        if (!layout) return;
        const activeBg = btn.dataset.activeBg || 'var(--color-header-bg,#120575)';
        // Reset all tabs
        layout.querySelectorAll('.ts-tab').forEach(function(t) {
            t.classList.remove('ts-tab-active');
            t.style.background = '';
            t.style.color = '';
        });
        // Reset all panels
        layout.querySelectorAll('.ts-panel').forEach(function(p) { p.setAttribute('hidden',''); });
        // Activate clicked tab
        btn.classList.add('ts-tab-active');
        btn.style.background = activeBg;
        btn.style.color = '#fff';
        const panel = layout.querySelector('.ts-panel[data-panel="' + btn.dataset.tab + '"]');
        if (panel) panel.removeAttribute('hidden');
    }

    /* ---- FAQ helpers ---- */
    function addFaqItem(btn, blockIdx) {
        const editor = document.getElementById('faq_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'faq-item-row';
        row.innerHTML = `
            <div class="form-group"><label>Question</label><input type="text" name="faq_question[${blockIdx}][]" placeholder="e.g. How much does it cost?"></div>
            <div class="form-group"><label>Answer</label><textarea name="faq_answer[${blockIdx}][]" rows="2"></textarea></div>
            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)" style="margin-bottom:12px;">Remove Q&amp;A</button>
        `;
        editor.appendChild(row);
    }
    function removeFaqItem(btn) {
        btn.closest('.faq-item-row').remove();
    }

    /* ---- Gallery helpers ---- */
    function addGalleryImg(btn, blockIdx) {
        const editor = document.getElementById('gallery_imgs_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'gallery-img-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Image</label>
                        <input type="file" name="gallery_photo[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="gallery_photo_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="gallery_alt[${blockIdx}][]" placeholder="Describe the photo for SEO">
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeGalleryImg(this)" style="margin-top:24px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeGalleryImg(btn) { btn.closest('.gallery-img-row').remove(); }

    /* ---- Steps helpers ---- */
    function addStepItem(btn, blockIdx) {
        const editor = document.getElementById('steps_items_' + blockIdx);
        const uid = 'steps_img_' + blockIdx + '_' + Date.now();
        const row = document.createElement('div');
        row.className = 'step-item-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:0 0 100px;">
                    <div class="form-group"><label>Icon/image (optional)</label>
                        <input type="file" name="steps_image[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                        <input type="hidden" id="${uid}" name="steps_image_existing[${blockIdx}][]" value="">
                        <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('${uid}');if(i)i.value=url;})">📷 Library</button>
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="steps_alt[${blockIdx}][]" placeholder="Step icon description" style="font-size:0.82rem;">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Step heading</label>
                        <input type="text" name="steps_heading_item[${blockIdx}][]" placeholder="e.g. Call Us">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="steps_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeStepItem(this)" style="margin-top:24px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeStepItem(btn) { btn.closest('.step-item-row').remove(); }

    /* ---- Stats helpers ---- */
    function addStatItem(btn, blockIdx) {
        const editor = document.getElementById('stats_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'stat-item-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <div class="form-group" style="flex:1 1 120px;"><label>Number / value</label>
                    <input type="text" name="stats_number[${blockIdx}][]" placeholder="e.g. 5,200+">
                </div>
                <div class="form-group" style="flex:2 1 200px;"><label>Label</label>
                    <input type="text" name="stats_label[${blockIdx}][]" placeholder="e.g. Jobs Completed">
                </div>
                <button type="button" class="remove-row" onclick="removeStatItem(this)">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeStatItem(btn) { btn.closest('.stat-item-row').remove(); }

    /* ---- Cards helpers ---- */
    function addCardItem(btn, blockIdx) {
        const editor = document.getElementById('cards_items_' + blockIdx);
        const uid = 'cards_img_' + blockIdx + '_' + Date.now();
        const row = document.createElement('div');
        row.className = 'card-item-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                <div style="flex:0 0 100px;">
                    <div class="form-group"><label>Image</label>
                        <input type="file" name="cards_image[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                        <input type="hidden" id="${uid}" name="cards_image_existing[${blockIdx}][]" value="">
                        <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var i=document.getElementById('${uid}');if(i)i.value=url;})">📷 Library</button>
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="cards_alt[${blockIdx}][]" placeholder="Image description" style="font-size:0.82rem;">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Heading</label>
                        <input type="text" name="cards_heading_item[${blockIdx}][]" placeholder="e.g. Water Damage Repair">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="cards_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 140px;"><label>Link URL</label>
                            <input type="text" name="cards_link[${blockIdx}][]" placeholder="/service-page">
                        </div>
                        <div class="form-group" style="flex:1 1 100px;"><label>Button text</label>
                            <input type="text" name="cards_btn[${blockIdx}][]" placeholder="Read More" value="Read More">
                        </div>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeCardItem(this)" style="align-self:flex-start;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeCardItem(btn) { btn.closest('.card-item-row').remove(); }

    /* ---- FAQ Two Col frontend toggle ---- */
    function toggleFq(id) {
        var answer = document.getElementById(id);
        var btn = answer ? answer.previousElementSibling : null;
        if (!answer) return;
        var isHidden = answer.hasAttribute('hidden');
        answer.toggleAttribute('hidden', !isHidden);
        if (btn) {
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            var icon = btn.querySelector('.fq-icon');
            if (icon) icon.textContent = isHidden ? '−' : '+';
        }
    }

    /* ---- FAQ frontend toggle ---- */
    function toggleFaq(id) {
        const answer = document.getElementById(id);
        const btn = answer ? answer.previousElementSibling : null;
        if (!answer) return;
        const isHidden = answer.hasAttribute('hidden');
        answer.toggleAttribute('hidden', !isHidden);
        if (btn) {
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            const icon = btn.querySelector('.faq-icon');
            if (icon) icon.textContent = isHidden ? '−' : '+';
        }
    }
    </script>
    <?php
}
