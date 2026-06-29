<?php
/* ============================================================
   ADMIN: Local Business editor
   ============================================================ */
function render_local_business_editor(array $lb) {
    $types = ['LocalBusiness','PestControlService','HomeAndConstructionBusiness','Plumber','Electrician','GeneralContractor'];
    ?>
    <div class="card">
        <h2>Local Business Info</h2>
        <p class="hint" style="margin-bottom:18px;">Used to generate <strong>LocalBusiness</strong> schema markup on every page. Helps Google show your business in local search results.</p>
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
function render_schema_section(array $seo, string $context): void {
    global $data;
    $lb = $data['local_business'] ?? [];

    $manualTypes = match($context) {
        'global'   => ['EducationalOrganization', 'WebSite'],
        'homepage' => ['WebPage', 'ItemList'],
        'page'     => ['WebPage', 'Course', 'Event', 'AboutPage', 'ContactPage', 'EducationalOccupationalCredential'],
        'template' => ['Course', 'Event'],
        default    => [],
    };
    $autoTypes = match($context) {
        'homepage' => ['FAQPage'],
        'page'     => ['FAQPage', 'BreadcrumbList'],
        'post'     => ['Article', 'BreadcrumbList'],
        'template' => ['FAQPage'],
        default    => [],
    };
    $meta = [
        'EducationalOrganization'           => ['icon' => '🎓', 'desc' => 'Identifies your training provider to Google. More specific than Organization — the correct type for a certification training business. Set once globally.'],
        'WebSite'                           => ['icon' => '🌐', 'desc' => 'Links your website entity in Google\'s knowledge graph. Enables cross-referencing with your Organization. Set once globally.'],
        'WebPage'                           => ['icon' => '📄', 'desc' => 'Identifies this specific page and connects it to your site and organization entity.'],
        'ItemList'                          => ['icon' => '📋', 'desc' => 'Lists your courses or services. Good for signalling structured offerings on the homepage.'],
        'Course'                            => ['icon' => '📚', 'desc' => 'Tells Google this page covers a specific course. Can unlock rich results in course searches. Fill in name, price, and rating when ready.'],
        'Event'                             => ['icon' => '📅', 'desc' => 'Marks an upcoming class session. Can appear in Google\'s event results. Requires real start and end dates.'],
        'AboutPage'                         => ['icon' => 'ℹ️', 'desc' => 'Signals this is an About Us page to Google\'s entity understanding.'],
        'ContactPage'                       => ['icon' => '📞', 'desc' => 'Signals this is a Contact page.'],
        'EducationalOccupationalCredential' => ['icon' => '🏅', 'desc' => 'Describes the certification this course leads to (PMP, CAPM, CPMAI). Tells Google what credential the student earns.'],
        'FAQPage'                           => ['icon' => '❓', 'label' => 'FAQPage (auto)', 'desc' => 'Built automatically from FAQ Two-Column blocks on this page. No editing needed.'],
        'Article'                           => ['icon' => '📝', 'label' => 'Article (auto)', 'desc' => 'Built automatically from this post\'s title, published date, updated date, and author.'],
        'BreadcrumbList'                    => ['icon' => '🔗', 'label' => 'BreadcrumbList (auto)', 'desc' => 'Built automatically from the page slug. Shows the breadcrumb trail in Google search results.'],
    ];

    $skeletons = [];
    foreach ($manualTypes as $type) {
        $skeletons[$type] = get_schema_skeleton($type, $seo, $lb);
    }
    $savedBlocks = $seo['schema_blocks'] ?? [];
    ?>
    <div class="card" style="border:2px solid #1e3a5f;margin-top:24px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
            <h2 style="margin:0;">Schema Markup</h2>
            <span style="background:#1e3a5f;color:#fff;font-size:0.68rem;font-weight:800;padding:3px 9px;border-radius:4px;letter-spacing:0.07em;text-transform:uppercase;">Structured Data</span>
        </div>
        <p class="hint" style="margin-bottom:20px;">Structured data Google reads directly from this page. <strong>Check a type to enable it</strong>, review the JSON, fill in any blank <code>""</code> fields, then save. Auto-generated types require no input.</p>

        <?php if ($autoTypes): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 16px;margin-bottom:20px;">
            <p style="font-size:0.8rem;font-weight:700;color:#166534;margin:0 0 8px;">✅ Auto-generated on this page — no editing needed</p>
            <?php foreach ($autoTypes as $type):
                $m = $meta[$type] ?? []; ?>
            <div style="display:flex;gap:8px;align-items:baseline;margin-bottom:3px;">
                <span style="font-size:0.83rem;font-weight:600;color:#15803d;"><?= $m['icon'] ?? '' ?> <?= h($m['label'] ?? $type) ?></span>
                <span style="font-size:0.78rem;color:#166534;">— <?= h($m['desc'] ?? '') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($manualTypes): ?>
        <p style="font-size:0.75rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 10px;">Manual schema — check a type to enable it</p>
        <?php foreach ($manualTypes as $type):
            $m         = $meta[$type] ?? ['icon' => '📄', 'desc' => ''];
            $blockData = $savedBlocks[$type] ?? [];
            $isEnabled = !empty($blockData['enabled']);
            $savedJson = $blockData['json'] ?? '';
            $tid       = 'schema-json-' . $type;
        ?>
        <div style="border:1px solid <?= $isEnabled ? '#3b82f6' : '#e2e8f0' ?>;border-radius:8px;margin-bottom:10px;overflow:hidden;" id="schema-wrap-<?= $type ?>">
            <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 16px;background:<?= $isEnabled ? '#eff6ff' : '#f8fafc' ?>;cursor:pointer;"
                 onclick="document.getElementById('schema-enable-<?= $type ?>').click()">
                <input type="checkbox" id="schema-enable-<?= $type ?>"
                       name="schema_blocks[<?= $type ?>][enabled]" value="1"
                       <?= $isEnabled ? 'checked' : '' ?>
                       onchange="schemaToggle('<?= $type ?>')"
                       onclick="event.stopPropagation()"
                       style="margin-top:3px;flex-shrink:0;width:16px;height:16px;cursor:pointer;">
                <div>
                    <div style="font-weight:700;font-size:0.9rem;color:#0f172a;"><?= $m['icon'] ?> <?= h($type) ?></div>
                    <div style="font-size:0.78rem;color:#64748b;margin-top:2px;"><?= h($m['desc']) ?></div>
                </div>
            </div>
            <div id="schema-block-<?= $type ?>" style="<?= $isEnabled ? '' : 'display:none;' ?>padding:16px;border-top:1px solid #e2e8f0;background:#fff;">
                <textarea id="<?= $tid ?>" name="schema_blocks[<?= $type ?>][json]"
                          rows="14" style="font-family:'SF Mono','Fira Code',monospace;font-size:0.79rem;width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:10px;line-height:1.55;resize:vertical;"
                          ><?= h($savedJson) ?></textarea>
                <div style="margin-top:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button"
                            onclick="loadSchemaSkeleton('<?= $type ?>','<?= $tid ?>')"
                            style="padding:5px 12px;font-size:0.8rem;border:1px solid #3b82f6;color:#3b82f6;background:#fff;border-radius:4px;cursor:pointer;font-weight:600;">
                        ↺ Load skeleton
                    </button>
                    <span style="font-size:0.73rem;color:#94a3b8;">Replaces the textarea with the default template — current content will be cleared</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php elseif ($context === 'post'): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 16px;">
            <p style="margin:0;font-size:0.85rem;color:#64748b;">Blog posts get Article and BreadcrumbList schema automatically from post data. No manual schema needed for standard posts.</p>
        </div>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var _sk = <?= json_encode($skeletons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.loadSchemaSkeleton = function(type, tid) {
            if (!_sk[type]) return;
            if (!confirm('Replace current content with the default skeleton?\nYour edits will be cleared.')) return;
            document.getElementById(tid).value = _sk[type];
        };
        window.schemaToggle = function(type) {
            var cb    = document.getElementById('schema-enable-' + type);
            var block = document.getElementById('schema-block-' + type);
            var wrap  = document.getElementById('schema-wrap-' + type);
            var hdr   = wrap ? wrap.querySelector('div') : null;
            if (!cb || !block || !wrap) return;
            var on = cb.checked;
            block.style.display  = on ? '' : 'none';
            wrap.style.borderColor = on ? '#3b82f6' : '#e2e8f0';
            if (hdr) hdr.style.background = on ? '#eff6ff' : '#f8fafc';
        };
    })();
    </script>
    <?php
}

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
        <h3 style="margin: 0 0 16px; font-size: 1rem;">Service Schema (per-page)</h3>
        <p class="hint" style="margin-bottom:16px;">Auto-generates a Service schema for this page using the global business info.</p>
        <div class="form-group">
            <label for="service_name">Service name</label>
            <input type="text" id="service_name" name="service_name" value="<?= h($seo['service_name'] ?? '') ?>" placeholder="e.g. Cockroach Exterminator in Katy, TX">
        </div>
        <div class="form-group">
            <label for="service_type">Service type</label>
            <input type="text" id="service_type" name="service_type" value="<?= h($seo['service_type'] ?? '') ?>" placeholder="e.g. Cockroach Extermination">
        </div>
        <div class="form-group">
            <label for="service_area">Area served</label>
            <input type="text" id="service_area" name="service_area" value="<?= h($seo['service_area'] ?? '') ?>" placeholder="e.g. Katy, TX">
        </div>
        <div class="form-group">
            <label for="service_description">Service description</label>
            <textarea id="service_description" name="service_description" rows="2"><?= h($seo['service_description'] ?? '') ?></textarea>
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
    <?php
    render_schema_section($seo, $context);
}
