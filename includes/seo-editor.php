<?php
/* ============================================================
   ADMIN: Local Business editor
   ============================================================ */
function render_local_business_editor(array $lb) {
    $types = ['LocalBusiness','PestControlService','HomeAndConstructionBusiness','Plumber','Electrician','GeneralContractor'];
    ?>
    <div class="card">
        <h2>Local Business Info</h2>
        <p class="hint" style="margin-bottom:18px;">Business information used across the site. Reference these values when writing schema manually in the page editors.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
                <label>Business name</label>
                <input type="text" name="lb_name" value="<?= h($lb['lb_name'] ?? '') ?>" placeholder="e.g. Katy Pest Pros">
            </div>
            <div class="form-group">
                <label>Business type</label>
                <select name="lb_type">
                    <?php foreach ($types as $t): ?>
                    <option value="<?= h($t) ?>" <?= ($lb['lb_type'] ?? '') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Website URL</label>
                <input type="text" name="lb_url" value="<?= h($lb['lb_url'] ?? '') ?>" placeholder="https://katypestpros.com">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="lb_phone" value="<?= h($lb['lb_phone'] ?? '') ?>" placeholder="(281) 215-0160">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label>Street address</label>
                <input type="text" name="lb_street" value="<?= h($lb['lb_street'] ?? '') ?>" placeholder="123 Main St">
            </div>
            <div class="form-group">
                <label>City</label>
                <input type="text" name="lb_city" value="<?= h($lb['lb_city'] ?? '') ?>" placeholder="Katy">
            </div>
            <div class="form-group">
                <label>State</label>
                <input type="text" name="lb_state" value="<?= h($lb['lb_state'] ?? '') ?>" placeholder="TX">
            </div>
            <div class="form-group">
                <label>ZIP code</label>
                <input type="text" name="lb_zip" value="<?= h($lb['lb_zip'] ?? '') ?>" placeholder="77494">
            </div>
            <div class="form-group">
                <label>Country</label>
                <input type="text" name="lb_country" value="<?= h($lb['lb_country'] ?? 'US') ?>">
            </div>
            <div class="form-group">
                <label>Latitude</label>
                <input type="text" name="lb_lat" value="<?= h($lb['lb_lat'] ?? '') ?>" placeholder="29.7858">
            </div>
            <div class="form-group">
                <label>Longitude</label>
                <input type="text" name="lb_lng" value="<?= h($lb['lb_lng'] ?? '') ?>" placeholder="-95.8245">
            </div>
            <div class="form-group">
                <label>Price range</label>
                <input type="text" name="lb_price_range" value="<?= h($lb['lb_price_range'] ?? '$$') ?>" placeholder="$$">
            </div>
            <div class="form-group">
                <label>Opening hours <span class="hint">(schema format)</span></label>
                <input type="text" name="lb_hours" value="<?= h($lb['lb_hours'] ?? '') ?>" placeholder="Mo-Fr 08:00-18:00, Sa 09:00-13:00">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label>Logo URL</label>
                <input type="text" name="lb_logo" value="<?= h($lb['lb_logo'] ?? '') ?>" placeholder="https://katypestpros.com/images/logo/logo-katy-pest-pros.png">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label>Business description</label>
                <textarea name="lb_description" rows="3"><?= h($lb['lb_description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="lb_rating">Average rating <span class="hint">(1–5) — use shortcode <code>{rating}</code></span></label>
                <input type="text" id="lb_rating" name="lb_rating" value="<?= h($lb['lb_rating'] ?? '') ?>" placeholder="e.g. 4.8">
            </div>
            <div class="form-group">
                <label for="lb_review_count">Total review count — use shortcode <code>{review_count}</code></label>
                <input type="text" id="lb_review_count" name="lb_review_count" value="<?= h($lb['lb_review_count'] ?? '') ?>" placeholder="e.g. 534">
            </div>
        </div>
    </div>
    <?php
}

/* ============================================================
   ADMIN: SEO editor
   ============================================================ */
function render_seo_editor($seo, string $context = 'page') {
    ?>
    <div class="card">
        <h2>SEO &amp; Metadata</h2>
        <p class="hint" style="margin-bottom:18px;">These fields help search engines and social media understand your page.</p>

        <div class="form-group">
            <label for="seo_title">Page title (browser tab &amp; Google result)</label>
            <input type="text" id="seo_title" name="seo_title" value="<?= h($seo['seo_title'] ?? '') ?>" placeholder="e.g. Pest Control Katy TX | Local Exterminator | Katy Pest Pros">
            <span class="hint">Leave blank to use the site name. Supports shortcodes. Aim for 50–60 characters. Put your primary keyword first.</span>
        </div>
        <div class="form-group">
            <label for="canonical_url">Canonical URL</label>
            <input type="text" id="canonical_url" name="canonical_url" value="<?= h($seo['canonical_url'] ?? '') ?>" placeholder="e.g. https://katypestpros.com/cockroach-exterminator-katy-tx/">
            <span class="hint">Tells search engines the preferred URL for this page. Use the live site URL.</span>
        </div>
        <div class="form-group">
            <label for="meta_description">Meta description</label>
            <textarea id="meta_description" name="meta_description" rows="3"><?= h($seo['meta_description'] ?? '') ?></textarea>
            <span class="hint">1–2 sentences shown in search results. Aim for 120–160 characters.</span>
        </div>
        <div class="form-group">
            <label for="meta_keywords">Meta keywords</label>
            <input type="text" id="meta_keywords" name="meta_keywords" value="<?= h($seo['meta_keywords'] ?? '') ?>" placeholder="e.g. pest control, Katy TX, exterminator">
        </div>
        <div class="form-group">
            <label for="og_title">Social share title (og:title)</label>
            <input type="text" id="og_title" name="og_title" value="<?= h($seo['og_title'] ?? '') ?>" placeholder="Leave blank to use the page title">
        </div>
        <div class="form-group">
            <label for="og_description">Social share description (og:description)</label>
            <textarea id="og_description" name="og_description" rows="2"><?= h($seo['og_description'] ?? '') ?></textarea>
            <span class="hint">Shown when someone shares this page on social media.</span>
        </div>
        <div class="form-group">
            <label for="og_image">Social share image (og:image)</label>
            <?php if (!empty($seo['og_image'])): ?>
                <img src="/<?= h($seo['og_image']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
            <?php endif; ?>
            <input type="hidden" name="og_image_existing" value="<?= h($seo['og_image'] ?? '') ?>">
            <?php photo_picker_btn('og_image_existing'); ?>
            <span class="hint">Recommended 1200×630px. Shown when sharing on Facebook, iMessage, Slack, etc.</span>
        </div>
        <div class="form-group">
            <label for="og_image_alt">Social share image alt text (og:image:alt)</label>
            <input type="text" id="og_image_alt" name="og_image_alt" value="<?= h($seo['og_image_alt'] ?? '') ?>" placeholder="e.g. Granite PM Academy — PMP Certification Training">
            <span class="hint">Describes the image for screen readers and some social platforms. 1 sentence, no hashtags.</span>
        </div>
        <hr style="margin: 24px 0; border-color: #e5e7eb;">
        <h3 style="margin: 0 0 12px; font-size: 1rem;">Social &amp; Twitter Card</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group" style="grid-column:1/-1;">
                <label for="og_site_name">Site name (og:site_name)</label>
                <input type="text" id="og_site_name" name="og_site_name" value="<?= h($seo['og_site_name'] ?? '') ?>" placeholder="e.g. Granite PM Academy">
                <span class="hint">Shown in social share previews as the publisher name. Set once on the homepage; landing pages inherit it.</span>
            </div>
            <div class="form-group">
                <label for="og_locale">Locale (og:locale)</label>
                <input type="text" id="og_locale" name="og_locale" value="<?= h($seo['og_locale'] ?? '') ?>" placeholder="en_US">
                <span class="hint">Leave blank for en_US default.</span>
            </div>
            <div class="form-group">
                <label for="twitter_card">Twitter card type</label>
                <select id="twitter_card" name="twitter_card">
                    <option value="summary_large_image" <?= ($seo['twitter_card'] ?? '') !== 'summary' ? 'selected' : '' ?>>summary_large_image (large image)</option>
                    <option value="summary" <?= ($seo['twitter_card'] ?? '') === 'summary' ? 'selected' : '' ?>>summary (small thumbnail)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="twitter_handle">Twitter / X handle (twitter:site)</label>
                <input type="text" id="twitter_handle" name="twitter_handle" value="<?= h($seo['twitter_handle'] ?? '') ?>" placeholder="@YourHandle">
                <span class="hint">Optional. Include the @ sign.</span>
            </div>
        </div>
        <hr style="margin: 24px 0; border-color: #e5e7eb;">
        <h3 style="margin: 0 0 6px; font-size: 1rem;">Breadcrumbs</h3>
        <p class="hint" style="margin-bottom:16px;">Auto-generates <code>Home › Page Title</code> from the page title. Add an optional middle crumb (e.g. "Pest Control Services") or override the current page label.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="bc_hide" value="1" <?= !empty($seo['bc_hide']) ? 'checked' : '' ?>>
                    Hide breadcrumb on this page
                </label>
                <span class="hint">Overrides the global breadcrumb setting for this page only.</span>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label for="bc_label">Current page crumb label <span class="hint">(leave blank to use page title)</span></label>
                <input type="text" id="bc_label" name="bc_label" value="<?= h($seo['bc_label'] ?? '') ?>" placeholder="e.g. Cockroach Exterminator">
            </div>
            <div class="form-group">
                <label for="bc_mid_label">Middle crumb label <span class="hint">(optional)</span></label>
                <input type="text" id="bc_mid_label" name="bc_mid_label" value="<?= h($seo['bc_mid_label'] ?? '') ?>" placeholder="e.g. Pest Control Services">
            </div>
            <div class="form-group">
                <label for="bc_mid_url">Middle crumb URL</label>
                <input type="text" id="bc_mid_url" name="bc_mid_url" value="<?= h($seo['bc_mid_url'] ?? '') ?>" placeholder="e.g. /pest-control-katy-tx">
            </div>
        </div>
    </div>
    <div class="card" style="border:2px solid #1e3a5f;margin-top:24px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
            <h2 style="margin:0;">Schema Markup (JSON-LD)</h2>
            <span style="background:#1e3a5f;color:#fff;font-size:0.68rem;font-weight:800;padding:3px 9px;border-radius:4px;letter-spacing:0.07em;text-transform:uppercase;">Structured Data</span>
        </div>
        <p class="hint" style="margin-bottom:12px;">Work out the schema with Claude, paste it here, and save. Supports shortcodes like <code>{website}</code>, <code>{business}</code>, <code>{city}</code>. Must be valid JSON.</p>
        <div class="form-group" style="margin:0;">
            <textarea id="schema_json_ta" name="schema" rows="18"
                      style="font-family:'SF Mono','Fira Code',monospace;font-size:0.79rem;width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:10px;line-height:1.55;resize:vertical;"
                      oninput="validateSchemaJson(this)"><?= h($seo['schema'] ?? '') ?></textarea>
            <div id="schema_json_status" style="margin-top:6px;font-size:0.8rem;min-height:1.2em;"></div>
        </div>
    </div>
    <script>
    (function(){
        function validateSchemaJson(ta) {
            var status = document.getElementById('schema_json_status');
            if (!ta.value.trim()) { status.textContent = ''; ta.style.borderColor = '#e2e8f0'; return; }
            try {
                JSON.parse(ta.value);
                status.textContent = '✓ Valid JSON';
                status.style.color = '#16a34a';
                ta.style.borderColor = '#86efac';
            } catch(e) {
                status.textContent = '✗ ' + e.message;
                status.style.color = '#dc2626';
                ta.style.borderColor = '#fca5a5';
            }
        }
        window.validateSchemaJson = validateSchemaJson;
        var ta = document.getElementById('schema_json_ta');
        if (ta && ta.value.trim()) validateSchemaJson(ta);
    })();
    </script>
    <?php
}
