<?php
/* ============================================================
   ADMIN: JS for the block editor
   ============================================================ */
function content_editor_scripts() {
    $blockTypes = json_encode(array_keys(allowed_block_types()));
    $bpThumbs   = json_encode(block_thumbnails());
    $bpLabels   = json_encode(allowed_block_types());
    // AI enrich types that write into a map_info block's info text (e.g. city_spotlight),
    // as [type_id => label], for the "AI City Info text" dropdown on new map_info blocks.
    $miAiTypes  = [];
    foreach (ai_load_registry() as $tid => $def) {
        if (is_array($def) && ($def['ai_inject_field'] ?? '') === 'mi_info_text') {
            $miAiTypes[$tid] = $def['label'] ?? $tid;
        }
    }
    ?>
    <script>
    const BLOCK_TYPES = <?= $blockTypes ?>;
    const BP_THUMBS   = <?= $bpThumbs ?>;
    const BP_LABELS   = <?= $bpLabels ?>;
    const MI_AI_TYPES = <?= json_encode($miAiTypes, JSON_UNESCAPED_UNICODE) ?>;

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

    /* Re-index every block field's block-key to its current DOM position, right before
       submit. This makes the position the single source of truth for the positional POST
       arrays that parse_blocks_from_post() reads as $_POST['field'][$i] — immune to
       reorder / delete / clone / new-block insertion, and to whatever placeholder key
       (positional [], baked [<i>], or [new_N]) the panel emitted. The FIRST [..] group
       (block key) is rewritten; any nested [] (repeater item index) is left untouched. */
    function reindexBlockFields() {
        const container = document.getElementById('content-blocks');
        if (!container) return;
        const cards = container.querySelectorAll(':scope > .block-card');
        cards.forEach(function (card, pos) {
            card.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace(/^([A-Za-z_][A-Za-z0-9_]*)\[[^\]]*\]/, '$1[' + pos + ']');
            });
        });
    }

    /* Wire re-indexing to the block-editor form's submit (fires for the Save button and
       for moveBlock's requestSubmit; harmlessly idempotent if run twice). */
    (function () {
        function wire() {
            const cb = document.getElementById('content-blocks');
            const form = cb && cb.closest('form');
            if (form) form.addEventListener('submit', reindexBlockFields);
        }
        if (document.readyState !== 'loading') wire();
        else document.addEventListener('DOMContentLoaded', wire);
    })();

    /* Move block up/down — auto-saves immediately to avoid data loss */
    function moveBlock(e, btn, dir) {
        e.stopPropagation();
        const card = btn.closest('.block-card');
        if (!card) return;
        const container = card.parentElement;
        if (!container) return;
        let moved = false;
        if (dir < 0) {
            const prev = card.previousElementSibling;
            if (prev) { container.insertBefore(card, prev); moved = true; }
        } else {
            const next = card.nextElementSibling;
            if (next) { container.insertBefore(next, card); moved = true; }
        }
        if (moved) {
            // Re-index explicitly (covers the no-requestSubmit fallback), then submit via
            // requestSubmit() so the form's submit listeners fire (reindex + CSRF) — a
            // plain .submit() would fire neither.
            reindexBlockFields();
            const form = btn.closest('form');
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        }
    }

    /* Clone a block — deep-copies the block accordion and inserts it below */
    function cloneBlock(e, btn) {
        e.stopPropagation();
        const card  = btn.closest('.block-card');
        if (!card) return;
        const clone = card.cloneNode(true);
        clone.open  = true;
        card.parentElement.insertBefore(clone, card.nextSibling);
        clone.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /* Remove a block */
    function removeBlock(e, btn) {
        e.stopPropagation();
        const card = btn.closest('.block-card');
        if (!card) return;
        const container = card.parentElement;
        if (!container) return;
        if (container.children.length > 1) {
            if (!confirm('Remove this block? Click Save to make it permanent.')) return;
            card.remove();
        }
    }

    /* Block picker state */
    let _bpTargetSelect = null, _bpTargetCard = null, _bpNewBlock = false;

    function openBlockPicker(triggerBtn) {
        _bpTargetSelect = triggerBtn.previousElementSibling;
        _bpTargetCard   = triggerBtn;
        _bpNewBlock     = false;
        _highlightPicker(_bpTargetSelect ? _bpTargetSelect.value : null);
        document.getElementById('block-picker-modal').classList.add('is-open');
        setTimeout(() => { const s = document.getElementById('bp-search'); if (s) s.focus(); }, 50);
    }

    function _openPickerForNew() {
        _bpTargetSelect = null; _bpTargetCard = null; _bpNewBlock = true;
        _highlightPicker(null);
        document.getElementById('block-picker-modal').classList.add('is-open');
        setTimeout(() => { const s = document.getElementById('bp-search'); if (s) s.focus(); }, 50);
    }

    function _highlightPicker(currentType) {
        document.querySelectorAll('#block-picker-modal .bp-card').forEach(c => {
            c.classList.toggle('is-selected', c.dataset.type === currentType);
        });
    }

    function selectBlockType(type) {
        if (_bpNewBlock) {
            closeBlockPicker();
            _createBlock(type);
        } else if (_bpTargetSelect) {
            _bpTargetSelect.value = type;
            switchBlockType(_bpTargetSelect);
            if (_bpTargetCard) {
                _bpTargetCard.querySelector('.bp-trigger-thumb').innerHTML = BP_THUMBS[type] || BP_THUMBS['text'] || '';
                _bpTargetCard.querySelector('.bp-trigger-label').textContent = BP_LABELS[type] || type;
            }
            closeBlockPicker();
        }
    }

    function closeBlockPicker() {
        document.getElementById('block-picker-modal').classList.remove('is-open');
        _bpTargetSelect = null; _bpTargetCard = null; _bpNewBlock = false;
        const s = document.getElementById('bp-search');
        if (s) { s.value = ''; filterBlockPicker(''); }
    }

    function filterBlockPicker(q) {
        q = q.trim().toLowerCase();
        document.querySelectorAll('#block-picker-modal .bp-group').forEach(group => {
            let any = false;
            group.querySelectorAll('.bp-card').forEach(card => {
                const match = !q || card.dataset.name.includes(q) || card.dataset.desc.includes(q) || card.dataset.type.includes(q);
                card.style.display = match ? '' : 'none';
                if (match) any = true;
            });
            group.style.display = any ? '' : 'none';
        });
    }

    /* Add a new blank block — opens the picker first */
    function addBlock() { _openPickerForNew(); }

    function _createBlock(type) {
        const container = document.getElementById('content-blocks');
        const idx = container.children.length;
        const card = document.createElement('details');
        card.className = 'block-card';
        card.dataset.blockType = type;
        card.open = true;

        let typeOptions = '';
        <?php foreach (grouped_block_types() as $groupLabel => $groupItems): ?>
        typeOptions += `<optgroup label="<?= h($groupLabel) ?>">`;
        <?php foreach ($groupItems as $k => $l): ?>
        typeOptions += `<option value="<?= h($k) ?>"><?= h($l) ?></option>`;
        <?php endforeach; ?>
        typeOptions += `</optgroup>`;
        <?php endforeach; ?>
        typeOptions = typeOptions.replace(`value="${type}"`, `value="${type}" selected`);

        const thumbHtml = BP_THUMBS[type] || BP_THUMBS['text'] || '';
        const typeLabel  = BP_LABELS[type]  || type;

        // AI enrich control — visible dropdown for map_info (attach City Spotlight etc.),
        // hidden field for every other type. Emitting exactly one enrich_ai_type_id[] per
        // block keeps the positional POST arrays aligned with block_type[].
        let enrichField;
        if (type === 'map_info') {
            let opts = '<option value="">— None (write the info text yourself) —</option>';
            for (const tid in MI_AI_TYPES) {
                opts += `<option value="${tid}">${MI_AI_TYPES[tid]}</option>`;
            }
            enrichField = `<div class="form-group" style="margin-top:6px;padding-top:12px;border-top:1px dashed #e2e8f0;">
                <label>✦ AI City Info text <span class="hint">(optional)</span></label>
                <select name="enrich_ai_type_id[]">${opts}</select>
                <span class="hint">Attach an AI type, click Save, then run AI &rarr; Generate. AI writes only the info paragraph; the map, photo &amp; headings stay as set.</span>
            </div>`;
        } else {
            enrichField = '<input type="hidden" name="enrich_ai_type_id[]" value="">';
        }

        card.innerHTML = `
            <summary class="block-card-header">
                <span class="block-label">New block</span>
                <select name="block_type[]" class="block-type-select" style="display:none;" onchange="switchBlockType(this)">
                    ${typeOptions}
                </select>
                <button type="button" class="bp-trigger" onclick="openBlockPicker(this)">
                    <span class="bp-trigger-thumb">${thumbHtml}</span>
                    <span class="bp-trigger-label">${typeLabel}</span>
                    <span class="bp-trigger-arrow">&#9660;</span>
                </button>
                <input type="text" name="block_anchor[]"
                       placeholder="Section ID (e.g. about)"
                       title="Anchor ID — use in menu links as #about"
                       style="flex:1 1 160px;max-width:200px;font-size:0.82rem;padding:6px 10px;">
                <div class="block-actions">
                    <button type="button" class="icon-btn" onclick="moveBlock(event,this,-1)">&uarr;</button>
                    <button type="button" class="icon-btn" onclick="moveBlock(event,this,1)">&darr;</button>
                    <button type="button" class="icon-btn" onclick="cloneBlock(event,this)" title="Clone block">Clone</button>
                    <button type="button" class="icon-btn remove-row" onclick="removeBlock(event,this)">Remove</button>
                </div>
            </summary>
            <div style="padding:10px 16px 0;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span style="font-size:0.8rem;font-weight:600;color:#6b7280;white-space:nowrap;">Section skin:</span>
                    <div class="skin-picker">
                        <label class="skin-opt"><input type="radio" name="block_skin[]" value="" checked><span class="skin-swatch skin-swatch-default"></span>Default</label>
                        <label class="skin-opt"><input type="radio" name="block_skin[]" value="light"><span class="skin-swatch skin-swatch-light"></span>Light</label>
                        <label class="skin-opt"><input type="radio" name="block_skin[]" value="subtle"><span class="skin-swatch skin-swatch-subtle"></span>Subtle</label>
                        <label class="skin-opt"><input type="radio" name="block_skin[]" value="accent"><span class="skin-swatch skin-swatch-accent"></span>Accent</label>
                        <label class="skin-opt"><input type="radio" name="block_skin[]" value="dark"><span class="skin-swatch skin-swatch-dark"></span>Dark</label>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-text is-hidden">
                <input type="hidden" name="block_photo_alt[]" value="">
                <input type="hidden" name="block_existing_photo[]" value="">
                <input type="hidden" name="block_photo_ratio[]" value="landscape">
                <input type="hidden" name="block_photo_position[]" value="center">
                <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1 1 200px;">
                        <label>Heading text (optional)</label>
                        <input type="text" name="block_heading_text[]" placeholder="e.g. Why Choose Us">
                    </div>
                    <div class="form-group" style="flex:0 0 180px;">
                        <label>Heading level</label>
                        <select name="block_heading_level[]">
                            <option value="h1">H1 (Page title)</option>
                            <option value="h2" selected>H2 (Section heading)</option>
                            <option value="h3">H3 (Sub-section)</option>
                            <option value="p">Paragraph (no heading)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Body text</label>
                    <textarea name="block_text[]" rows="5" class="rich-editor" placeholder="Write the body text for this block..."></textarea>
                </div>
            </div>
            <div class="block-fields block-fields-image_left block-fields-image_right is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Image side</label>
                        <select name="image_side[]">
                            <option value="left" selected>Image left, text right</option>
                            <option value="right">Text left, image right</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Layout</label>
                        <select name="ir_layout[]">
                            <option value="side" selected>Side by side</option>
                            <option value="stacked">Stacked (text above, image below)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Text</label><textarea name="ir_text[]" rows="4" class="rich-editor"></textarea></div>
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
                <div class="form-group"><label>Tagline</label><input type="text" name="hs_tagline[]" placeholder="e.g. 6 PMI Certifications. Live Online."></div>
                <div class="form-group"><label>Paragraph text</label><textarea name="hs_subtext[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Button 1 text</label><input type="text" name="hs_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Button 1 link</label><input type="text" name="hs_btn_url[]" placeholder="e.g. /#courses"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Button 2 text (outline)</label><input type="text" name="hs_btn2_text[]"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Button 2 link</label><input type="text" name="hs_btn2_url[]" placeholder="e.g. /about"></div>
                </div>
                <div class="form-group"><label>Background color</label><input type="color" name="hs_bg_color[]" value="#f3f6f7"></div>
                <div class="form-group"><label>Background texture / pattern (optional)</label>
                    <input type="hidden" name="hs_bg_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image position</label>
                    <select name="hs_image_side[]"><option value="right" selected>Text left, image right</option><option value="left">Image left, text right</option></select>
                </div>
                <div class="form-group"><label>Right-side image</label>
                    <input type="file" name="hs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hs_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="hs_photo_alt[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Caption line 1</label><input type="text" name="hs_caption1[]" placeholder="e.g. Pest Control"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Caption line 2</label><input type="text" name="hs_caption2[]" placeholder="e.g. Katy, TX"></div>
                </div>
                <div class="form-group"><label>Mobile stacking order</label>
                    <select name="hs_mobile_order[]"><option value="" selected>Default (HTML source order)</option><option value="img_first">Image first</option><option value="text_first">Text first</option></select>
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
                <div class="form-group"><label>Mobile stacking order</label>
                    <select name="fs_mobile_order[]"><option value="" selected>Default (HTML source order)</option><option value="img_first">Image first</option><option value="text_first">Text / icon grid first</option></select>
                </div>
            </div>
            <div class="block-fields block-fields-feature_columns is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Section heading</label><input type="text" name="fc_heading[]" placeholder="e.g. Our Services"></div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="fc_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Subheading</label><input type="text" name="fc_subheading[]" placeholder="e.g. 6 PMI certifications. Live instruction."></div>
                <div class="form-group"><label>Background color</label><input type="color" name="fc_bg_color[]" value="#f3f6f7"></div>
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
                    <?= color_mode_select('sc_left_bg', '', 'accent') ?>
                </div>
                <div class="form-group"><label>Custom left color</label><input type="color" name="sc_left_bg_custom[]" value="#fd783b"></div>
                <div class="form-group"><label>Right label text</label><input type="text" name="sc_right_label[]" placeholder="e.g. Call The Katy Pest Pros Team"></div>
                <div class="form-group"><label>Right phone number</label><input type="text" name="sc_right_phone[]" placeholder="e.g. (281) 215-0160"></div>
                <div class="form-group"><label>Right phone link</label><input type="text" name="sc_right_phone_url[]" placeholder="tel:+12812150160"></div>
                <div class="form-group"><label>Right background</label>
                    <?= color_mode_select('sc_right_bg', '', 'header') ?>
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
            <div class="block-fields block-fields-html_two_col is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 240px;"><label>Left column HTML</label>
                        <textarea name="htc_left[]" rows="8" style="font-family:monospace;font-size:0.83rem;"></textarea>
                    </div>
                    <div class="form-group" style="flex:1 1 240px;"><label>Right column HTML</label>
                        <textarea name="htc_right[]" rows="8" style="font-family:monospace;font-size:0.83rem;"></textarea>
                    </div>
                </div>
                <div class="form-group" style="max-width:200px;">
                    <label>Background color <span class="hint">(optional)</span></label>
                    <input type="color" name="htc_bg[]" value="#ffffff">
                </div>
            </div>
            <div class="block-fields block-fields-cta_card is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="cc_heading[]" placeholder="e.g. Contact Katy's Top Pest Control Company Today"></div>
                <div class="form-group"><label>Text</label><textarea name="cc_text[]" rows="3"></textarea></div>
                <div class="form-group"><label>Checklist items <span class="hint">(optional — one item per line, shown with ✓)</span></label><textarea name="cc_checklist[]" rows="4" placeholder="Free re-enrollment in the next available course&#10;No additional charge — $0 out of pocket"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Button text</label><input type="text" name="cc_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Button link</label><input type="text" name="cc_btn_url[]" placeholder="tel:+1..."></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Style</label>
                        <select name="cc_btn_style[]"><option value="outline" selected>Outline</option><option value="filled">Filled</option></select>
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Background</label>
                        <?= color_mode_select('cc_bg', '', 'accent') ?>
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
                    <?= color_mode_select('mi_head_color', '', 'header') ?>
                    <input type="color" name="mi_head_color_custom[]" value="#120575" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Map position</label>
                    <select name="mi_map_side[]"><option value="left" selected>Map on left, info on right (default)</option><option value="right">Map on right, info on left</option></select>
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
                        <?= color_mode_select('lg_accent', '', 'accent') ?>
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
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Banner text</label><input type="text" name="cb_text[]" placeholder="e.g. 24/7 Pest Control Services in Katy, TX"></div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="cb_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Subtext (optional)</label><input type="text" name="cb_subtext[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Button text</label><input type="text" name="cb_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Button link</label><input type="text" name="cb_btn_url[]"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Background</label>
                        <?= color_mode_select('cb_bg', '', 'accent') ?>
                        <input type="color" name="cb_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Text color</label><input type="color" name="cb_text_color[]" value="#ffffff"></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Padding</label>
                        <select name="cb_padding[]"><option value="compact">Compact</option><option value="normal" selected>Normal</option><option value="large">Large</option></select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-faq_two_col is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Heading</label><input type="text" name="fq_heading[]" placeholder="e.g. FAQs – Pest Control in Katy"></div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="fq_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                    <div class="form-group" style="flex:0 0 150px;"><label>Answers open?</label>
                        <select name="fq_open[]"><option value="0" selected>Click to expand</option><option value="1">Open (no click)</option></select>
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 120px;"><label>Background</label><input type="color" name="fq_bg_color[]" value="#ffffff"></div>
                    <div class="form-group" style="flex:1 1 120px;"><label>Item box color</label><input type="color" name="fq_item_bg[]" value="#f0f2f8"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <?= color_mode_select('fq_head_color', '', 'header') ?>
                        <input type="color" name="fq_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Icon color</label>
                        <?= color_mode_select('fq_icon_bg', '', 'accent') ?>
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
                        <?= color_mode_select('if_check_color', '', 'accent') ?>
                        <input type="color" name="if_check_color_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <?= color_mode_select('if_head_color', '', 'header') ?>
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
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Heading</label><input type="text" name="wb_heading[]" placeholder="Your First Choice For Katy Pest Pros in Katy, TX"></div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="wb_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Subtext (optional)</label><textarea name="wb_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="wb_btn_text[]" placeholder="e.g. Call Us"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="wb_btn_url[]" placeholder="tel:+1..."></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Button style</label>
                        <select name="wb_btn_style[]"><option value="filled" selected>Filled</option><option value="outline">Outline</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Badge / button color</label>
                    <?= color_mode_select('wb_badge_bg', '', 'accent') ?>
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
                        <?= color_mode_select('sc_badge_bg', '', 'accent') ?>
                        <input type="color" name="sc_badge_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <?= color_mode_select('sc_head_color', '', 'header') ?>
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
                    <?= color_mode_select('hg_color1', '', 'accent') ?>
                    <input type="color" name="hg_color1_custom[]" value="#fd783b" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Even tile color</label>
                    <?= color_mode_select('hg_color2', '', 'header') ?>
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
                    <?= color_mode_select('ts_active_bg', '', 'header') ?>
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
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Section heading (optional)</label>
                        <input type="text" name="steps_heading[]" placeholder="e.g. Our Recovery Process">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="steps_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                </div>
                <div class="steps-items-editor" id="steps_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addStepItem(this, 'new_${idx}')">+ Add step</button>
            </div>
            <div class="block-fields block-fields-stats is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Section heading (optional)</label>
                        <input type="text" name="stats_heading[]" placeholder="e.g. Why Choose Us">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="stats_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
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
            <div class="block-fields block-fields-pricing_cards is-hidden">
                <div class="form-group"><label>Section label <span class="hint">(small caps above heading)</span></label>
                    <input type="text" name="pc_label[]" placeholder="e.g. ALL PMI CERTIFICATIONS">
                </div>
                <div class="form-group"><label>Intro paragraph <span class="hint">(appears below heading)</span></label>
                    <textarea name="pc_subheading[]" rows="2"></textarea>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;">
                    <div class="form-group" style="flex:1 1 220px;"><label>Section heading (optional)</label>
                        <input type="text" name="pc_heading[]" placeholder="e.g. Six Classes. Every Career Stage.">
                    </div>
                    <div class="form-group" style="flex:0 0 120px;"><label>Columns</label>
                        <select name="pc_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                    </div>
                    <div class="form-group" style="flex:0 0 140px;"><label>Background color</label>
                        <input type="color" name="pc_bg[]" value="#f8fafc">
                    </div>
                </div>
                <div class="pc-items-editor" id="pc_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addPcItem(this, 'new_${idx}')">+ Add card</button>
            </div>
            <div class="block-fields block-fields-stage_cards is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Label (small caps above heading)</label>
                        <input type="text" name="sc_section_label[]" placeholder="FIND YOUR PATH">
                    </div>
                    <div class="form-group" style="flex:2 1 220px;"><label>Main heading</label>
                        <input type="text" name="sc_heading[]" placeholder="e.g. Every stage of your career.">
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Colored sub-heading line (accent color)</label>
                        <input type="text" name="sc_subhead[]" placeholder="e.g. We have the right class.">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Columns</label>
                        <select name="sc_cols[]"><option value="2">2</option><option value="3">3</option><option value="4" selected>4</option><option value="5">5</option></select>
                    </div>
                    <div class="form-group" style="flex:0 0 100px;"><label>Background</label>
                        <input type="color" name="sc_bg[]" value="#f8fafc">
                    </div>
                    <div class="form-group" style="flex:1 1 120px;"><label>Number color</label>
                        <?= color_mode_select('sc_accent', '', 'accent') ?>
                        <input type="color" name="sc_accent_custom[]" value="" style="margin-top:4px;">
                    </div>
                </div>
                <div class="form-group"><label>Intro text (optional)</label>
                    <input type="text" name="sc_subtext[]" placeholder="Optional sentence below the heading">
                </div>
                <div class="sc-stages-editor" id="sc_stages_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addStageCard(this, 'new_${idx}')">+ Add stage</button>
            </div>
            <div class="block-fields block-fields-logo_bar is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:2 1 200px;"><label>Label (optional)</label>
                        <input type="text" name="lb_heading[]" placeholder="e.g. Trusted by, As seen in, Our Partners">
                    </div>
                    <div class="form-group" style="flex:0 0 100px;"><label>Background</label>
                        <input type="color" name="lb_bg[]" value="#ffffff">
                    </div>
                    <div class="form-group" style="flex:1 1 120px;"><label>Logo height: <strong id="lb_height_val_new_${idx}">60px</strong></label>
                        <input type="range" name="lb_height[]" min="30" max="160" step="5" value="60"
                               oninput="document.getElementById('lb_height_val_new_${idx}').textContent=this.value+'px'"
                               style="width:100%;accent-color:var(--color-accent,#2563eb);">
                    </div>
                    <div class="form-group" style="flex:0 0 auto;padding-bottom:4px;"><label>
                        <input type="checkbox" name="lb_grayscale[new_${idx}]" value="1"> Grayscale logos
                    </label></div>
                </div>
                <div class="lb-items-editor" id="lb_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addLbItem(this, 'new_${idx}')">+ Add logo</button>
            </div>
            <div class="block-fields block-fields-trust_bar is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background</label>
                        <select name="tb_bg[]">
                            <option value="subtle" selected>Subtle (theme skin)</option>
                            <option value="accent">Accent color</option>
                            <option value="header">Header color</option>
                            <option value="footer">Footer color</option>
                            <option value="custom">Custom…</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 100px;"><label>Custom color</label>
                        <input type="color" name="tb_bg_custom[]" value="#f3f6f7">
                    </div>
                    <div class="form-group" style="flex:0 0 auto;">
                        <label><input type="checkbox" name="tb_show_icons[]" value="1" style="width:auto;margin-right:6px;" checked>Show icons</label>
                    </div>
                </div>
                <div class="tb-items-editor" id="tb_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addTbItem(this, 'new_${idx}')">+ Add badge</button>
            </div>
            <div class="block-fields block-fields-cards is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Label (small caps above heading)</label>
                        <input type="text" name="cards_label[]" placeholder="WHY CHOOSE US">
                    </div>
                    <div class="form-group" style="flex:2 1 220px;"><label>Main heading</label>
                        <input type="text" name="cards_heading[]" placeholder="e.g. Our Services">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="cards_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Blue sub-heading line (accent color)</label>
                    <input type="text" name="cards_subhead[]" placeholder="e.g. The authority.">
                </div>
                <div class="form-group"><label>Intro paragraph (optional)</label>
                    <textarea name="cards_subtext[]" rows="2"></textarea>
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
                        <input type="color" name="cards_head_color_custom[]" value="#1a1a2e" style="display:none;">
                    </div>
                    <div class="form-group"><label>Card heading color</label>
                        <select name="cards_item_head_color[]"><option value="accent">Accent</option><option value="header" selected>Header/Navy</option><option value="custom">Custom</option></select>
                        <input type="color" name="cards_item_head_color_custom[]" value="#1a1a2e" style="display:none;">
                    </div>
                    <div class="form-group"><label>Card text color</label>
                        <input type="color" name="cards_text_color[]" value="#333333" oninput="var t=this.nextElementSibling;if(t)t.value=this.value;">
                        <input type="text" value="#333333" placeholder="#333333" style="width:90px;margin-left:6px;font-size:0.82rem;" oninput="var c=this.previousElementSibling;if(c&&/^#[0-9a-fA-F]{6}$/.test(this.value))c.value=this.value;">
                    </div>
                </div>
                <div class="cards-items-editor" id="cards_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addCardItem(this, 'new_${idx}')">+ Add card</button>
            </div>
            <div class="block-fields block-fields-contact_form is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="cf_heading[]" value="Contact Us"></div>
                <div class="form-group"><label>Subtext (optional)</label><input type="text" name="cf_subtext[]" value=""></div>
                <div class="form-group"><label>Submit button text</label><input type="text" name="cf_btn_text[]" value="Send Message"></div>
                <div class="form-group"><label><input type="checkbox" name="cf_show_phone[]" value="1" style="width:auto;margin-right:6px;">Show phone number field</label></div>
                <p class="hint" style="margin-top:8px;">Submissions go to <code>CONTACT_EMAIL</code> in <code>config.php</code>.</p>
            </div>
            <div class="block-fields block-fields-comparison_table is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Section heading</label>
                        <input type="text" name="ct_heading[]" placeholder="e.g. Not All PMP Certification Training Is Equal">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="ct_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="h4">H4</option></select>
                    </div>
                    <div class="form-group" style="flex:0 0 120px;"><label>Background</label>
                        <input type="color" name="ct_bg[]" value="#ffffff">
                    </div>
                </div>
                <div class="form-group"><label>Eyebrow label <span class="hint">(optional)</span></label>
                    <input type="text" name="ct_label[]" placeholder="e.g. WHY GRANITE PM">
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 200px;"><label>Column 1 header <span class="hint">(competitor)</span></label>
                        <input type="text" name="ct_col1_header[]" value="Other Online Courses">
                    </div>
                    <div class="form-group" style="flex:1 1 200px;"><label>Column 2 header <span class="hint">(your brand)</span></label>
                        <input type="text" name="ct_col2_header[]" value="Granite PM Academy">
                    </div>
                </div>
                <div class="form-group"><label>Rows <span class="hint">(one per line: Feature | Competitor value | Your value)</span></label>
                    <textarea name="ct_rows_raw[]" rows="8" style="font-family:monospace;font-size:0.85rem;" placeholder="PMI Authorization | ATP or none | Premier ATP — highest tier&#10;Instruction | Pre-recorded video | Live, instructor-led daily"></textarea>
                </div>
                <div class="form-group"><label>Callout note <span class="hint">(optional)</span></label>
                    <textarea name="ct_callout[]" rows="2" placeholder="PMI's Premier ATP designation is held by fewer than 1% of training providers worldwide."></textarea>
                </div>
            </div>
            <div class="block-fields block-fields-ai_block is-hidden">
                <div style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:20px;font-size:.78rem;font-weight:600;color:#6b7280;margin-bottom:12px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:#d1d5db;flex-shrink:0;"></span>
                    Not generated yet
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
                    <div class="form-group" style="flex:1 1 180px;"><label>AI Block Type ID</label>
                        <input type="text" name="ai_type_id[]" value="" placeholder="e.g. city_market_intro">
                        <span class="hint">Key in ai_block_types.json registry</span>
                    </div>
                    <div class="form-group" style="flex:0 0 150px;"><label>Mode</label>
                        <select name="ai_mode[]">
                            <option value="standalone" selected>Standalone</option>
                            <option value="inject">Inject into adjacent</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 150px;"><label>Render as block type</label>
                        <input type="text" name="ai_render_as[]" value="text" placeholder="e.g. text">
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:0 0 150px;"><label>Inject target <span class="hint">(inject mode only)</span></label>
                        <select name="ai_inject_target[]">
                            <option value="previous" selected>Previous block</option>
                            <option value="next">Next block</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Target field <span class="hint">(inject mode only)</span></label>
                        <input type="text" name="ai_inject_field[]" value="" placeholder="e.g. text">
                    </div>
                    <div class="form-group" style="flex:0 0 150px;"><label>Inject mode</label>
                        <select name="ai_inject_mode[]">
                            <option value="replace">Replace</option>
                            <option value="append" selected>Append</option>
                            <option value="prepend">Prepend</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Prompt override <span class="hint">(optional — overrides registry prompt)</span></label>
                    <textarea name="ai_prompt_override[]" rows="3" placeholder="Leave blank to use the prompt from ai_block_types.json for the selected type ID."></textarea>
                </div>
                <details>
                    <summary style="cursor:pointer;font-weight:600;font-size:.85rem;padding:8px 0;">Generated content <span class="hint">(run the generator script first)</span></summary>
                    <div style="margin-top:8px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <div class="form-group" style="flex:2 1 220px;"><label>Heading</label>
                                <input type="text" name="ai_heading_text[]" value="">
                            </div>
                            <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                                <select name="ai_heading_level[]">
                                    <option value="h2" selected>H2</option>
                                    <option value="h3">H3</option>
                                    <option value="h4">H4</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group"><label>Body text <span class="hint">(HTML)</span></label>
                            <textarea name="ai_text[]" rows="6"></textarea>
                        </div>
                        <div class="form-group">
                            <label><input type="checkbox" name="ai_locked[]" value="1" style="width:auto;margin-right:6px;">Lock block — skip on regeneration</label>
                        </div>
                    </div>
                </details>
                <input type="hidden" name="ai_meta_generated[]" value="">
                <input type="hidden" name="ai_meta_generated_at[]" value="">
                <input type="hidden" name="ai_meta_model_used[]" value="">
                <input type="hidden" name="ai_meta_ai_type[]" value="">
            </div>
            <div class="block-fields block-fields-video is-hidden">
                <div class="form-group"><label>Heading (optional)</label>
                    <input type="text" name="vid_heading[]" placeholder="e.g. See Us In Action">
                </div>
                <div class="form-group"><label>YouTube or Vimeo URL</label>
                    <input type="url" name="vid_url[]" placeholder="https://www.youtube.com/watch?v=...">
                    <span class="hint">Paste a YouTube or Vimeo link — the embed is generated automatically.</span>
                </div>
                <div class="form-group"><label>Caption (optional)</label>
                    <input type="text" name="vid_caption[]" placeholder="Optional text shown below the video">
                </div>
                <div class="form-group"><label>Width</label>
                    <select name="vid_width[]">
                        <option value="contained" selected>Contained (800px max)</option>
                        <option value="full">Full width</option>
                    </select>
                </div>
            </div>
            <div class="block-fields block-fields-testimonials is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Section heading (optional)</label>
                        <input type="text" name="tm_heading[]" placeholder="e.g. What Our Customers Say">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Level</label>
                        <select name="tm_heading_level[]">
                            <option value="h2" selected>H2</option>
                            <option value="h3">H3</option>
                            <option value="h4">H4</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 130px;"><label>Background color</label>
                        <input type="color" name="tm_bg_color[]" value="#f8fafc">
                    </div>
                    <div class="form-group" style="flex:1 1 130px;"><label>Text color</label>
                        <input type="color" name="tm_text_color[]" value="#374151">
                    </div>
                    <div class="form-group" style="flex:1 1 130px;"><label>Star color</label>
                        <?= color_mode_select('tm_accent', '', 'accent') ?>
                        <input type="color" name="tm_accent_custom[]" value="#f59e0b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:0 0 90px;"><label>Columns</label>
                        <select name="tm_cols[]">
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                        </select>
                    </div>
                </div>
                <div class="tm-items-editor" id="tm_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addTmItem(this, 'new_${idx}')">+ Add review</button>
            </div>
            <div class="block-fields block-fields-team is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Section heading</label>
                        <input type="text" name="team_heading[]" placeholder="e.g. Meet Our Instructors">
                    </div>
                    <div class="form-group" style="flex:0 0 80px;"><label>Columns</label>
                        <select name="team_cols[]">
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Section subheading (optional)</label>
                    <input type="text" name="team_subheading[]" placeholder="e.g. PMI-certified instructors with real-world PM experience">
                </div>
                <div class="team-members-editor" id="team_members_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addTeamMember(this, 'new_${idx}')">+ Add person</button>
            </div>
            <div class="block-fields block-fields-email_banner is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:2 1 220px;"><label>Heading</label>
                        <input type="text" name="eb_heading[]" placeholder="Free PMP Practice Exam — See If You're Ready">
                    </div>
                    <div class="form-group" style="flex:0 0 100px;"><label>Background</label>
                        <input type="color" name="eb_bg[]" value="#2563eb">
                    </div>
                </div>
                <div class="form-group"><label>Subtext (below heading)</label>
                    <input type="text" name="eb_subtext[]" placeholder="50 questions, instant scoring, personalized study plan.">
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Email input placeholder</label>
                        <input type="text" name="eb_placeholder[]" value="Enter your email">
                    </div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Button text</label>
                        <input type="text" name="eb_btn_text[]" value="Get My Free Exam →">
                    </div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Button URL</label>
                        <input type="text" name="eb_btn_url[]" value="#" placeholder="https://...">
                    </div>
                </div>
                <div class="form-group"><label>Form action URL <span class="hint">(optional — leave blank to use button URL)</span></label>
                    <input type="text" name="eb_form_action[]" placeholder="https://your-email-service.com/subscribe">
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                    <div class="form-group" style="flex:0 0 120px;"><label>Trust badge image</label>
                        <input type="file" name="eb_badge_image[new_${idx}]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                        <input type="hidden" name="eb_badge_existing[]" value="">
                    </div>
                    <div class="form-group" style="flex:1 1 220px;"><label>Trust badge text</label>
                        <input type="text" name="eb_badge_text[]" placeholder="Premier Authorized Training Partner (ATP) of the Project Management Institute®">
                    </div>
                </div>
            </div>
            ${enrichField}
            <input type="hidden" name="enrich_ai_inject_field[]" value="">
            <input type="hidden" name="enrich_ai_inject_mode[]" value="replace">
            <input type="hidden" name="enrich_ai_model[]" value="">
            <input type="hidden" name="enrich_ai_locked[]" value="0">
            <input type="hidden" name="enrich_ai_meta_generated[]" value="">
            <input type="hidden" name="enrich_ai_meta_generated_at[]" value="">
            <input type="hidden" name="enrich_ai_meta_model[]" value="">
            <input type="hidden" name="enrich_ai_meta_type[]" value="">
        `;
        container.appendChild(card);
        const sel = card.querySelector('.block-type-select');
        if (sel) switchBlockType(sel);
        if (typeof initRichEditors === 'function') initRichEditors(card);
    }

    /* ---- Feature Columns helpers ---- */
    function addFcCol(btn, blockIdx) {
        const editor = document.getElementById('fc_cols_' + blockIdx);
        const uid = 'fc_col_img_' + blockIdx + '_' + Date.now();
        const row = document.createElement('div');
        row.className = 'fc-col-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div class="form-group" style="flex:0 0 120px;">
                    <label>SVG icon <span class="hint">(paste inline SVG)</span></label>
                    <textarea name="fc_col_icon[${blockIdx}][]" rows="3" style="font-size:0.72rem;font-family:monospace;"></textarea>
                </div>
                <div class="form-group" style="flex:0 0 80px;">
                    <label>Photo</label>
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

    /* ---- Buttons Grid helpers ---- */
    function addBgItem(btn, blockIdx) {
        const editor = document.getElementById('bg_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'repeat-row';
        row.style = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;';
        row.innerHTML = `
            <input type="text" name="bg_label[${blockIdx}][]" placeholder="e.g. Termite Treatment" style="flex:1;">
            <input type="text" name="bg_url[${blockIdx}][]" placeholder="/termite-treatment" style="flex:1;">
            <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
        `;
        editor.appendChild(row);
    }

    /* ---- Testimonials helpers ---- */
    function addTeamMember(btn, blockIdx) {
        const editor = document.getElementById('team_members_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'faq-item-row';
        row.style.cssText = 'background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;';
        row.innerHTML = `
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                <div class="form-group" style="flex:1 1 180px;">
                    <label>Photo</label>
                    <input type="file" name="team_photo[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="team_photo_existing[${blockIdx}][]" value="">
                    <button type="button" class="btn btn-small btn-secondary" style="margin-top:4px;" onclick="openImgPicker(function(url){var ins=this.closest('.faq-item-row').querySelectorAll('input[type=hidden]');ins[ins.length-1].value=url;}.bind(this))">Library</button>
                </div>
                <div style="flex:2 1 260px;">
                    <div class="form-group"><label>Name</label><input type="text" name="team_name[${blockIdx}][]" placeholder="e.g. Sarah Chen, PMP"></div>
                    <div class="form-group"><label>Title / Role</label><input type="text" name="team_title[${blockIdx}][]" placeholder="e.g. Senior PMP Instructor"></div>
                    <div class="form-group"><label>Photo alt text</label><input type="text" name="team_photo_alt[${blockIdx}][]" placeholder="e.g. Sarah Chen headshot"></div>
                </div>
            </div>
            <div class="form-group"><label>Bio (optional)</label><textarea name="team_bio[${blockIdx}][]" rows="2" placeholder="Short bio — 1-2 sentences."></textarea></div>
            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)">Remove person</button>
        `;
        editor.appendChild(row);
    }

    function addTmItem(btn, blockIdx) {
        const editor = document.getElementById('tm_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'faq-item-row';
        row.innerHTML = `
            <div class="form-group"><label>Review text</label><textarea name="tm_quote[${blockIdx}][]" rows="3" placeholder="e.g. Fast, professional service. Got rid of our ant problem in one visit."></textarea></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:1 1 160px;"><label>Customer name</label><input type="text" name="tm_name[${blockIdx}][]" placeholder="e.g. Sarah M."></div>
                <div class="form-group" style="flex:1 1 160px;"><label>Location / role (optional)</label><input type="text" name="tm_location[${blockIdx}][]" placeholder="e.g. Katy, TX or IT Project Lead"></div>
                <div class="form-group" style="flex:0 0 80px;"><label>Initials</label><input type="text" name="tm_initials[${blockIdx}][]" placeholder="MR" maxlength="2" style="font-weight:700;text-transform:uppercase;"><span class="hint">Avatar</span></div>
                <div class="form-group" style="flex:0 0 80px;"><label>Avatar color</label><input type="color" name="tm_avatar_color[${blockIdx}][]" value="#2563eb"></div>
            </div>
            <div class="form-group"><label>Result badge (optional)</label><input type="text" name="tm_result_badge[${blockIdx}][]" placeholder="e.g. ✓ PMP® — Passed first attempt"><span class="hint">Shown as a bordered pill below the reviewer name.</span></div>
            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)" style="margin-bottom:12px;">Remove review</button>
        `;
        editor.appendChild(row);
    }

    function addStageCard(btn, blockIdx) {
        const editor = document.getElementById('sc_stages_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'sc-stage-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div class="form-group" style="flex:0 0 56px;"><label>No.</label>
                    <input type="text" name="sc_num[${blockIdx}][]" placeholder="01" maxlength="4" style="font-weight:700;">
                </div>
                <div class="form-group" style="flex:1 1 140px;"><label>Stage label</label>
                    <input type="text" name="sc_label[${blockIdx}][]" placeholder="e.g. Starting Out">
                </div>
                <div class="form-group" style="flex:2 1 200px;"><label>Stage heading</label>
                    <input type="text" name="sc_shead[${blockIdx}][]" placeholder="e.g. Project Fundamentals">
                </div>
                <button type="button" class="remove-row" onclick="this.closest('.sc-stage-row').remove()" style="align-self:flex-start;">&times;</button>
            </div>
            <div class="form-group"><label>Course / item list <span class="hint">(one per line — add | /url to make a link)</span></label>
                <textarea name="sc_items[${blockIdx}][]" rows="5" style="font-family:monospace;font-size:.85rem;" placeholder="CAPM® Exam Prep | /capm&#10;PM Foundations&#10;Agile Basics | /agile"></textarea>
            </div>
        `;
        editor.appendChild(row);
    }

    function addLbItem(btn, blockIdx) {
        const editor = document.getElementById('lb_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'faq-item-row';
        row.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;';
        row.innerHTML = `
            <div class="form-group" style="flex:1 1 160px;"><label>Logo image</label>
                <input type="file" name="lb_photo[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                <input type="hidden" name="lb_photo_existing[${blockIdx}][]" value="">
            </div>
            <div class="form-group" style="flex:1 1 160px;"><label>Alt text</label><input type="text" name="lb_alt[${blockIdx}][]" placeholder="e.g. PMI Premier ATP Partner"></div>
            <div class="form-group" style="flex:1 1 160px;"><label>Link (optional)</label><input type="text" name="lb_url[${blockIdx}][]" placeholder="https://..."></div>
            <div style="padding-top:22px;"><button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)">&times; Remove</button></div>
        `;
        editor.appendChild(row);
    }

    function addTbItem(btn, blockIdx) {
        const editor = document.getElementById('tb_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'faq-item-row';
        row.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;';
        row.innerHTML = `
            <div class="form-group" style="flex:1 1 200px;"><label>Badge label</label><input type="text" name="tb_label[${blockIdx}][]" placeholder="e.g. Free Estimates"></div>
            <div class="form-group" style="flex:2 1 260px;"><label>Icon SVG (leave blank for default checkmark)</label><textarea name="tb_icon[${blockIdx}][]" rows="3" style="font-size:0.72rem;font-family:monospace;" placeholder="<svg …>…</svg>"></textarea></div>
            <div style="padding-top:22px;"><button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)">&times; Remove</button></div>
        `;
        editor.appendChild(row);
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
                        <div class="form-group" style="flex:2 1 180px;"><label>Icon SVG <span class="hint">(inline SVG — shows in blue box above title)</span></label>
                            <textarea name="cards_icon[${blockIdx}][]" rows="2" style="font-family:monospace;font-size:.78rem;"></textarea>
                        </div>
                        <div class="form-group" style="flex:1 1 120px;"><label>Badge pill text</label>
                            <input type="text" name="cards_badge[${blockIdx}][]" placeholder="e.g. Guaranteed">
                        </div>
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

    /* ---- Pricing Cards helpers ---- */
    function addPcItem(btn, blockIdx) {
        const editor = document.getElementById('pc_items_' + blockIdx);
        const ci = editor.children.length;
        const row = document.createElement('div');
        row.className = 'pc-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1 1 180px;">
                    <div class="form-group"><label>Card name / title</label>
                        <input type="text" name="pc_name[${blockIdx}][]" placeholder="e.g. PMP®">
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 120px;"><label>Badge text</label>
                            <input type="text" name="pc_badge[${blockIdx}][]" placeholder="e.g. MOST POPULAR">
                        </div>
                        <div class="form-group" style="flex:0 0 60px;"><label>Color</label>
                            <input type="color" name="pc_badge_color[${blockIdx}][]" value="#2563eb">
                        </div>
                    </div>
                    <div class="form-group"><label>Inner badge <span class="hint">(gray pill inside card)</span></label>
                        <input type="text" name="pc_inner_badge[${blockIdx}][]" placeholder="e.g. PMI FLAGSHIP CREDENTIAL">
                    </div>
                    <div class="form-group"><label>Sub-label <span class="hint">(optional)</span></label>
                        <input type="text" name="pc_sublabel[${blockIdx}][]" placeholder="e.g. PMI FLAGSHIP CREDENTIAL">
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="pc_featured[${blockIdx}][]" value="${ci}" style="width:auto;margin-right:6px;">Featured / highlighted</label>
                    </div>
                </div>
                <div style="flex:2 1 240px;">
                    <div class="form-group"><label>Description</label>
                        <textarea name="pc_desc[${blockIdx}][]" rows="2"></textarea>
                    </div>
                    <div class="form-group"><label>Feature checklist <span class="hint">(one item per line)</span></label>
                        <textarea name="pc_features[${blockIdx}][]" rows="4" style="font-size:0.85rem;"></textarea>
                    </div>
                    <div class="form-group"><label>Meta line 1 <span class="hint">(e.g. 4 days · Live online)</span></label>
                        <input type="text" name="pc_meta[${blockIdx}][]" placeholder="4 Days · Live online · Flexible scheduling">
                    </div>
                    <div class="form-group"><label>Meta line 2 <span class="hint">(e.g. Mon–Thu · 9AM–5PM)</span></label>
                        <input type="text" name="pc_meta2[${blockIdx}][]" placeholder="Flexible scheduling">
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 100px;"><label>Button text</label>
                            <input type="text" name="pc_btn_text[${blockIdx}][]" value="Get Started">
                        </div>
                        <div class="form-group" style="flex:2 1 160px;"><label>Button URL</label>
                            <input type="text" name="pc_btn_url[${blockIdx}][]" placeholder="/enroll">
                        </div>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="this.closest('.pc-item-row').remove()" style="align-self:flex-start;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }

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
