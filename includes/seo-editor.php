<?php
/* ============================================================
   ADMIN: Local Business editor
   ============================================================ */
function render_local_business_editor(array $lb) {
    ?>
    <div class="card">
        <h2>Local Business Info</h2>
        <p class="hint" style="margin-bottom:18px;">Used for the canonical URL fallback, admin display name, and the <code>{rating}</code> / <code>{review_count}</code> shortcodes.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
                <label>Business name</label>
                <input type="text" name="lb_name" value="<?= h($lb['lb_name'] ?? '') ?>" placeholder="e.g. Katy Pest Pros">
                <span class="hint">Shown as the site name in the admin when no other name is set.</span>
            </div>
            <div class="form-group">
                <label>Website URL</label>
                <input type="text" name="lb_url" value="<?= h($lb['lb_url'] ?? '') ?>" placeholder="https://katypestpros.com">
                <span class="hint">Used as the canonical URL base when a page has no explicit canonical set.</span>
            </div>
            <div class="form-group">
                <label for="lb_rating">Average rating <span class="hint">(1–5) — shortcode <code>{rating}</code></span></label>
                <input type="text" id="lb_rating" name="lb_rating" value="<?= h($lb['lb_rating'] ?? '') ?>" placeholder="e.g. 4.8">
            </div>
            <div class="form-group">
                <label for="lb_review_count">Review count — shortcode <code>{review_count}</code></label>
                <input type="text" id="lb_review_count" name="lb_review_count" value="<?= h($lb['lb_review_count'] ?? '') ?>" placeholder="e.g. 534">
            </div>
        </div>
    </div>
    <?php
}

/* ============================================================
   ADMIN: SEO editor
   ============================================================ */
function render_seo_editor($seo, string $context = 'page', string $websiteUrl = '', string $slugPattern = '') {
    if ($websiteUrl === '') {
        global $data;
        $websiteUrl = rtrim($data['site_vars']['website'] ?? '', '/');
    }
    // Build the prefill URL for the template URL tester input — resolve {website} now
    // (known from active site), leave {city_slug} for the user to replace.
    $templatePrefill = '';
    if ($context === 'template') {
        if (!empty($seo['canonical_url'])) {
            $templatePrefill = str_replace('{website}', $websiteUrl, $seo['canonical_url']);
        } elseif ($slugPattern !== '') {
            $templatePrefill = $websiteUrl . '/' . $slugPattern . '/';
        }
    }
    ?>
    <div class="card">
        <h2>SEO &amp; Metadata</h2>
        <p class="hint" style="margin-bottom:18px;">These fields help search engines and social media understand your page.</p>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="robots_noindex" value="1" <?= !empty($seo['robots_noindex']) ? 'checked' : '' ?>>
                Noindex this page (hide from search engines)
            </label>
            <span class="hint">Adds <code>&lt;meta name="robots" content="noindex"&gt;</code>. Use for thank-you pages, staging duplicates, or any page you don't want Google to index.</span>
        </div>
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
            <label for="og_type">Content type (og:type)</label>
            <?php $defaultOgType = ($context === 'post') ? 'article' : 'website'; ?>
            <select id="og_type" name="og_type">
                <option value="website" <?= (($seo['og_type'] ?? $defaultOgType) === 'website') ? 'selected' : '' ?>>website (default — homepages, service pages, landing pages)</option>
                <option value="article" <?= (($seo['og_type'] ?? $defaultOgType) === 'article') ? 'selected' : '' ?>>article (blog posts, news, guides)</option>
            </select>
            <span class="hint">Controls how Facebook and LinkedIn categorise the page. Use <code>article</code> for blog posts.</span>
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
            <?php if ($context === 'homepage'): ?>
            <h2 style="margin:0;">Homepage Schema (JSON-LD)</h2>
            <span style="background:#1e3a5f;color:#fff;font-size:0.68rem;font-weight:800;padding:3px 9px;border-radius:4px;letter-spacing:0.07em;text-transform:uppercase;">Structured Data</span>
            <?php elseif ($context === 'template'): ?>
            <h2 style="margin:0;">City Template Schema (JSON-LD)</h2>
            <span style="background:#1e3a5f;color:#fff;font-size:0.68rem;font-weight:800;padding:3px 9px;border-radius:4px;letter-spacing:0.07em;text-transform:uppercase;">Structured Data</span>
            <?php else: ?>
            <h2 style="margin:0;">Page Schema (JSON-LD)</h2>
            <span style="background:#1e3a5f;color:#fff;font-size:0.68rem;font-weight:800;padding:3px 9px;border-radius:4px;letter-spacing:0.07em;text-transform:uppercase;">Structured Data</span>
            <?php endif; ?>
        </div>
        <?php if ($context === 'template'): ?>
        <p class="hint" style="margin-bottom:12px;">Write the @graph schema with Claude using shortcodes for all city-specific values: <code>{website}</code>, <code>{city}</code>, <code>{SS}</code>, <code>{city_slug}</code>, <code>{business}</code>, etc. Do not include FAQPage — it is injected automatically from each city's FAQ block.<br><strong>Flow:</strong> JSON (with shortcodes) saved to templates.json → city generator resolves shortcodes + injects FAQPage → fully-resolved JSON written to each city page file → Generate Static Site embeds it in <code>&lt;script type="application/ld+json"&gt;</code> in the page <code>&lt;head&gt;</code> → HTML deployed to server.</p>
        <?php elseif ($context === 'homepage'): ?>
        <p class="hint" style="margin-bottom:12px;">The site's foundational schema — define <strong>Organization</strong> (or EducationalOrganization), <strong>WebSite</strong>, and <strong>WebPage</strong> here. All other pages reference these <code>@id</code> values. Shortcodes: <code>{website}</code>, <code>{business}</code>, <code>{phone}</code>, <code>{tel}</code>, <code>{address}</code>. City shortcodes (<code>{city}</code>, <code>{SS}</code>) do <strong>not</strong> resolve on the homepage.<br><strong>Flow:</strong> JSON saved to site.json → Generate Static Site embeds it in <code>&lt;script type="application/ld+json"&gt;</code> in the page <code>&lt;head&gt;</code> → HTML deployed to server.</p>
        <?php else: ?>
        <p class="hint" style="margin-bottom:12px;">Page-specific schema — Course, Service, FAQPage, WebPage, etc. Reference the homepage <code>@id</code> values rather than repeating the organization definition. Shortcodes: <code>{website}</code>, <code>{business}</code>, <code>{phone}</code>, <code>{tel}</code>, <code>{address}</code>. City shortcodes (<code>{city}</code>, <code>{SS}</code>) do <strong>not</strong> resolve here — use literal values.<br><strong>Flow:</strong> JSON saved to site.json → Generate Static Site embeds it in <code>&lt;script type="application/ld+json"&gt;</code> in the page <code>&lt;head&gt;</code> → HTML deployed to server.</p>
        <?php endif; ?>
        <div class="form-group" style="margin:0;">
            <textarea id="schema_json_ta" name="schema" rows="18"
                      style="font-family:'SF Mono','Fira Code',monospace;font-size:0.79rem;width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:10px;line-height:1.55;resize:vertical;"
                      oninput="validateSchemaJson(this)"><?= h($seo['schema'] ?? '') ?></textarea>
            <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
                <div id="schema_json_status" style="font-size:0.8rem;min-height:1.2em;flex:1;"></div>
                <button type="button" onclick="schemaFormat()" class="btn btn-secondary btn-small">Format JSON</button>
                <?php if ($context !== 'template'): ?>
                <button type="button" onclick="schemaOpenValidator('validator')" class="btn btn-secondary btn-small">validator.schema.org ↗</button>
                <button type="button" onclick="schemaOpenValidator('richresults')" class="btn btn-secondary btn-small">Rich Results Test ↗</button>
                <?php endif; ?>
            </div>
            <?php if ($context === 'template'): ?>
            <div style="margin-top:10px;padding:12px 14px;background:#f0f9ff;border-radius:6px;border:1px solid #bae6fd;">
                <div style="font-size:0.78rem;color:#075985;margin-bottom:8px;">This is a template — there is no single page to test. Enter the URL of a deployed city page to verify its schema:</div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input id="schema_url_editor_input" type="text" value="<?= h($templatePrefill) ?>" placeholder="https://example.com/page-slug-city-st/" style="flex:1;min-width:240px;font-size:0.82rem;padding:5px 8px;border:1px solid #bae6fd;border-radius:4px;">
                    <button type="button" onclick="schemaOpenEdited('validator')" class="btn btn-secondary btn-small">validator.schema.org ↗</button>
                    <button type="button" onclick="schemaOpenEdited('richresults')" class="btn btn-secondary btn-small">Rich Results Test ↗</button>
                </div>
            </div>
            <?php else: ?>
            <div id="schema_url_editor" style="display:none;margin-top:10px;padding:10px 14px;background:#fffbeb;border-radius:6px;border:1px solid #fcd34d;">
                <div style="font-size:0.78rem;color:#92400e;margin-bottom:6px;">Complete the URL for the page you want to test, then click Open:</div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input id="schema_url_editor_input" type="text" style="flex:1;min-width:200px;font-size:0.82rem;padding:5px 8px;border:1px solid #fcd34d;border-radius:4px;">
                    <button type="button" onclick="schemaOpenEdited()" class="btn btn-secondary btn-small">Open ↗</button>
                    <button type="button" class="btn btn-secondary btn-small" onclick="document.getElementById('schema_url_editor').style.display='none'" style="color:#92400e;">Cancel</button>
                </div>
            </div>
            <?php endif; ?>
            <div style="margin-top:10px;padding:10px 14px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;font-size:0.78rem;color:#555e6d;line-height:1.6;">
                <strong>Format JSON</strong> — re-indents the schema cleanly. Use after pasting to make it readable and confirm it is valid.<br>
                <?php if ($context === 'template'): ?>
                <strong>validator.schema.org ↗</strong> — shows every schema type on the live city page with error and warning counts. Tests the generated HTML, not this template.<br>
                <strong>Rich Results Test ↗</strong> — shows which schema types are eligible to appear as visual features in Google Search for that city page.
                <?php else: ?>
                <strong>validator.schema.org ↗</strong> — opens Google's schema validator pre-loaded with this page's canonical URL. Shows every schema type found (WebPage, Course, FAQPage, etc.) with error and warning counts. This is the correct tool to verify your JSON-LD is on the page and structurally valid.<br>
                <strong>Rich Results Test ↗</strong> — opens Google's rich results tester pre-loaded with the canonical URL. Shows only schema types eligible to appear as visual features in Google Search (star ratings, course cards, breadcrumbs). Both buttons require the page to be deployed and the Canonical URL field above to be filled in.
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var websiteBase = <?= json_encode(rtrim($websiteUrl, '/')) ?>;
        function validateSchemaJson(ta) {
            var status = document.getElementById('schema_json_status');
            if (!ta.value.trim()) { status.textContent = ''; ta.style.borderColor = '#e2e8f0'; return; }
            try {
                JSON.parse(ta.value);
                status.textContent = '✓ Valid JSON-LD';
                status.style.color = '#16a34a';
                ta.style.borderColor = '#86efac';
            } catch(e) {
                status.textContent = '✗ ' + e.message;
                status.style.color = '#dc2626';
                ta.style.borderColor = '#fca5a5';
            }
        }
        function schemaFormat() {
            var ta = document.getElementById('schema_json_ta');
            try {
                var parsed = JSON.parse(ta.value);
                ta.value = JSON.stringify(parsed, null, 2);
                validateSchemaJson(ta);
            } catch(e) {
                validateSchemaJson(ta);
            }
        }
        var _pendingTool = null;
        function schemaOpenValidator(tool) {
            var canonical = (document.getElementById('canonical_url') || {}).value || '';
            canonical = canonical.trim();
            if (websiteBase) canonical = canonical.replace(/\{website\}/g, websiteBase);
            // Show inline editor if: shortcodes remain OR canonical is empty (need user to supply a URL)
            var needsEdit = /\{[^}]+\}/.test(canonical) || canonical === '';
            if (needsEdit) {
                _pendingTool = tool;
                var inp = document.getElementById('schema_url_editor_input');
                inp.value = canonical || (websiteBase ? websiteBase + '/' : '');
                document.getElementById('schema_url_editor').style.display = 'block';
                inp.focus();
                var m = inp.value.match(/\{[^}]+\}/);
                if (m) {
                    var i = inp.value.indexOf(m[0]);
                    inp.setSelectionRange(i, i + m[0].length);
                } else {
                    inp.select();
                }
                return;
            }
            schemaDoOpen(tool, canonical);
        }
        function schemaOpenEdited(tool) {
            var inp = document.getElementById('schema_url_editor_input');
            var canonical = (inp ? inp.value : '').trim();
            if (!canonical) {
                if (inp) { inp.style.borderColor = '#ef4444'; inp.focus(); }
                return;
            }
            if (inp) inp.style.borderColor = '';
            // Resolve {website} token now that we have the actual URL
            if (websiteBase) canonical = canonical.replace(/\{website\}/g, websiteBase);
            var editor = document.getElementById('schema_url_editor');
            if (editor) editor.style.display = 'none';
            schemaDoOpen(tool || _pendingTool || 'validator', canonical);
        }
        function schemaDoOpen(tool, canonical) {
            var url;
            if (tool === 'validator') {
                url = canonical
                    ? 'https://validator.schema.org/#url=' + encodeURIComponent(canonical)
                    : 'https://validator.schema.org/';
            } else {
                url = canonical
                    ? 'https://search.google.com/test/rich-results?url=' + encodeURIComponent(canonical)
                    : 'https://search.google.com/test/rich-results';
            }
            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        window.validateSchemaJson  = validateSchemaJson;
        window.schemaFormat        = schemaFormat;
        window.schemaOpenValidator = schemaOpenValidator;
        window.schemaOpenEdited    = schemaOpenEdited;
        var ta = document.getElementById('schema_json_ta');
        if (ta && ta.value.trim()) validateSchemaJson(ta);
        // Pre-select the first {shortcode} in the template URL input so user can type over it immediately
        var tplInp = document.getElementById('schema_url_editor_input');
        if (tplInp && tplInp.value) {
            var m = tplInp.value.match(/\{[^}]+\}/);
            if (m) { var si = tplInp.value.indexOf(m[0]); tplInp.setSelectionRange(si, si + m[0].length); }
        }
    })();
    </script>
    <?php
}
