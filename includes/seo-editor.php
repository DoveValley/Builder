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
    // ── AI schema generator (admin-only): effective prompts + core-type presets ──
    require_once __DIR__ . '/../admin/schema_prompts.php';
    $schemaGenPrompts   = schema_prompts_effective();
    $schemaGenDefaults  = schema_prompt_defaults();
    $schemaGenCoreTypes = schema_core_types();
    $schemaGenScope = ($context === 'homepage') ? 'homepage'
                    : (($context === 'template') ? 'template'
                    : (($context === 'post') ? 'post' : 'page'));
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

        <h3 style="margin: 0 0 12px; font-size: 1rem;">🎯 Keyword focus</h3>
        <div class="form-group">
            <label for="primary_keyword">Primary keyword</label>
            <input type="text" id="primary_keyword" name="primary_keyword" value="<?= h($seo['primary_keyword'] ?? '') ?>" placeholder="e.g. Pest Control">
            <span class="hint">The one thing this page is about — <strong>leave the city out</strong>. Drives the title, H1, and schema. Add <code>{city}</code>/<code>{SS}</code> where you want the location, e.g. <code>{primary_keyword} in {city}, {SS}</code>.</span>
        </div>
        <div class="form-group">
            <label for="secondary_keywords">Secondary keywords</label>
            <div style="display:flex; gap:8px; align-items:flex-start;">
                <input type="text" id="secondary_keywords" name="secondary_keywords" value="<?= h($seo['secondary_keywords'] ?? '') ?>" placeholder="e.g. termite treatment, bed bug removal, rodent control" style="flex:1;">
                <button type="button" class="btn btn-secondary btn-small" onclick="suggestSecondaryKeywords(this)">✨ AI suggest</button>
            </div>
            <span class="hint">Comma-separated related topics this page should cover. Guides the AI copy and gives you subheading / FAQ ideas — <strong>not for stuffing</strong>.</span>
        </div>
        <script>
        window.suggestSecondaryKeywords = window.suggestSecondaryKeywords || function (btn) {
            var card = btn.closest('.card') || document;
            var pEl = card.querySelector('#primary_keyword');
            var sEl = card.querySelector('#secondary_keywords');
            var primary = (pEl && pEl.value || '').trim();
            if (!primary) { alert('Enter a primary keyword first.'); if (pEl) pEl.focus(); return; }
            var form = btn.closest('form');
            var csrf = (form && form.querySelector('input[name="csrf_token"]') || {}).value
                       || (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
            var orig = btn.textContent;
            btn.disabled = true; btn.textContent = '…';
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('primary_keyword', primary);
            fetch('keyword_suggest.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.disabled = false; btn.textContent = orig;
                    if (d.error) { alert(d.error); return; }
                    var suggested = (d.keywords || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                    if (!suggested.length) { alert('No suggestions returned — try again.'); return; }
                    var existing = (sEl.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                    var seen = {}; existing.forEach(function (s) { seen[s.toLowerCase()] = 1; });
                    suggested.forEach(function (s) { if (!seen[s.toLowerCase()]) { existing.push(s); seen[s.toLowerCase()] = 1; } });
                    sEl.value = existing.join(', ');
                })
                .catch(function () { btn.disabled = false; btn.textContent = orig; alert('Request failed.'); });
        };
        </script>
        <hr style="margin: 20px 0; border-color: #e5e7eb;">

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
            <span class="hint">Legacy <code>&lt;meta keywords&gt;</code> tag — largely ignored by search engines. Your strategic keywords are the <strong>Keyword focus</strong> fields at the top.</span>
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

            <?php
            // How-it-works blurb — states what's GLOBAL vs UNIQUE for this button's area.
            $schemaGenHow = [
                'homepage' => 'Generates the site\'s <strong>foundational</strong> schema — the business (<code>LocalBusiness</code>), <code>WebSite</code> and <code>WebPage</code> nodes. Their <code>@id</code>s are the anchors that <strong>every</strong> Core, Landing and Blog page references, so this runs on the homepage only.',
                'page'     => 'Generates schema for this core page from the <strong>Page type</strong> you pick below. Each type\'s prompt is shared across <strong>all core pages of that type</strong> on this site. The business is referenced by <code>@id</code> from the homepage — not redefined here.',
                'template' => 'Generates the <code>Service</code> + <code>areaServed</code> schema for this city template. This one prompt is shared across <strong>every city</strong> generated from this template — city values stay as shortcodes and resolve per city. <code>FAQPage</code> is added automatically, so it is excluded here.',
                'post'     => 'Generates <code>BlogPosting</code> schema for this post. The prompt is shared across <strong>all blog posts</strong> on this site; per-post values (title, date, image) are read from this post.',
            ][$schemaGenScope];
            ?>
            <details class="schema-ai-gen" style="margin-top:12px;border:1px solid #c7d2fe;border-radius:8px;background:#f5f7ff;">
                <summary style="cursor:pointer;padding:11px 14px;font-weight:800;color:#3730a3;">✨ AI generate this schema <span style="font-weight:500;color:#6366f1;font-size:0.82rem;">— draft it with Claude, then review &amp; save</span></summary>
                <div style="padding:2px 14px 14px;">
                    <div style="font-size:0.78rem;color:#3f3f70;line-height:1.65;background:#fff;border:1px solid #e0e7ff;border-radius:6px;padding:10px 12px;margin-bottom:12px;">
                        <?= $schemaGenHow ?><br>
                        <span style="color:#6b7280;">Editing the prompt affects <strong>this generation</strong>. Use <em>Save prompt as default</em> to persist it for this whole area on this site (stored globally in <code>data/schema_prompts.json</code>); <em>Reset</em> restores the built-in default.</span>
                    </div>
                    <?php if ($context === 'page'): ?>
                    <div class="form-group" style="margin-bottom:10px;">
                        <label style="font-size:0.8rem;font-weight:700;">Page type</label>
                        <select id="schema_core_type" onchange="schemaGenSwapPrompt()" style="font-size:0.85rem;padding:6px 8px;border:1px solid #c7d2fe;border-radius:5px;">
                            <?php foreach ($schemaGenCoreTypes as $val => $label): ?>
                            <option value="<?= h($val) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Auto-detected from the page title — change it if the guess is wrong.</span>
                    </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:0.8rem;font-weight:700;">Prompt <span class="hint">(editable — defines the schema shape for this area)</span></label>
                        <textarea id="schema_gen_prompt" rows="7" style="font-family:'SF Mono','Fira Code',monospace;font-size:0.76rem;width:100%;border:1px solid #c7d2fe;border-radius:6px;padding:9px;line-height:1.5;resize:vertical;"></textarea>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <button type="button" onclick="schemaGenRun(this)" class="btn btn-primary btn-small">✨ Generate schema</button>
                        <button type="button" onclick="schemaGenSavePrompt(this)" class="btn btn-secondary btn-small">Save prompt as default</button>
                        <button type="button" onclick="schemaGenResetPrompt()" class="btn btn-secondary btn-small">↺ Reset to built-in</button>
                        <span id="schema_gen_status" style="font-size:0.8rem;flex:1;min-width:120px;"></span>
                    </div>
                </div>
            </details>
            <script>
            (function(){
                var PROMPTS  = <?= json_encode($schemaGenPrompts,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
                var DEFAULTS = <?= json_encode($schemaGenDefaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
                var SCOPE    = <?= json_encode($schemaGenScope) ?>;
                var pEl = document.getElementById('schema_gen_prompt');
                var stEl = document.getElementById('schema_gen_status');
                if (!pEl) return;

                function csrf(){
                    var f = pEl.closest('form');
                    return (f && f.querySelector('input[name="csrf_token"]') || {}).value
                        || (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
                }
                function val(name){ var e = document.querySelector('[name="'+name+'"]'); return e ? (e.value||'').trim() : ''; }
                function say(msg, color){ if(stEl){ stEl.textContent = msg; stEl.style.color = color||'#4b5563'; } }

                // Which prompt key is active right now (core scope depends on the picker).
                function currentKey(){
                    if (SCOPE === 'page') { var s = document.getElementById('schema_core_type'); return s ? s.value : 'core_general'; }
                    return SCOPE; // 'homepage' | 'template' | 'post'
                }
                window.schemaGenSwapPrompt = function(){ pEl.value = PROMPTS[currentKey()] || ''; };

                // Core-page type auto-detection from the page title/slug.
                function autodetectCore(){
                    var t = (val('page_title') + ' ' + val('page_slug')).toLowerCase();
                    var key = 'core_service';
                    if (/contact/.test(t)) key = 'core_contact';
                    else if (/about|our story|who we are/.test(t)) key = 'core_about';
                    else if (/privacy|terms|policy|legal|disclaimer|accessibility|cookie/.test(t)) key = 'core_general';
                    else if (/^all |all-|locations|directory|our services|our courses|service area|browse/.test(t)) key = 'core_collection';
                    else if (/service|training|certification|treatment|control|exterminat|repair|install|inspection|removal|cleaning/.test(t)) key = 'core_service';
                    else key = 'core_general';
                    var s = document.getElementById('schema_core_type');
                    if (s) s.value = key;
                }

                // Context hints sent to the generator (concrete, non-identity values only).
                function buildCtx(){
                    var c = {};
                    if (SCOPE === 'template'){ c.title = val('template_title'); c.slug = val('slug_pattern'); c.keyword = val('primary_keyword'); c.service = val('primary_keyword') || val('template_title'); }
                    else if (SCOPE === 'post'){ c.title = val('post_title'); c.slug = val('post_slug'); c.excerpt = val('post_excerpt'); c.image = val('post_featured_image_existing'); c.date = val('post_published_at'); }
                    else if (SCOPE === 'page'){ c.title = val('page_title'); c.slug = val('page_slug'); }
                    return c;
                }

                window.schemaGenRun = function(btn){
                    var orig = btn.textContent; btn.disabled = true; btn.textContent = '…generating';
                    say('Calling Claude…', '#6366f1');
                    var fd = new FormData();
                    fd.append('csrf_token', csrf());
                    fd.append('scope', SCOPE);
                    if (SCOPE === 'page'){ var s = document.getElementById('schema_core_type'); fd.append('core_type', s ? s.value : ''); }
                    fd.append('prompt', pEl.value);
                    fd.append('ctx', JSON.stringify(buildCtx()));
                    fetch('schema_suggest.php', { method:'POST', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            btn.disabled = false; btn.textContent = orig;
                            if (d.error){ say('✗ ' + d.error, '#dc2626'); return; }
                            var ta = document.getElementById('schema_json_ta');
                            ta.value = d.schema;
                            ta.dispatchEvent(new Event('input')); // triggers the JSON validator + green border
                            say('✓ Draft inserted — review it above, then Save the page.', '#16a34a');
                        })
                        .catch(function(){ btn.disabled = false; btn.textContent = orig; say('✗ Request failed.', '#dc2626'); });
                };

                window.schemaGenSavePrompt = function(btn){
                    var key = currentKey();
                    var fd = new FormData();
                    fd.append('csrf_token', csrf()); fd.append('key', key); fd.append('prompt', pEl.value);
                    fetch('schema_prompt_save.php', { method:'POST', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d.error){ say('✗ ' + d.error, '#dc2626'); return; }
                            PROMPTS[key] = pEl.value;
                            say(d.overridden ? '✓ Saved as this site’s default for this area.' : '✓ Matches built-in — using the default.', '#16a34a');
                        })
                        .catch(function(){ say('✗ Save failed.', '#dc2626'); });
                };

                window.schemaGenResetPrompt = function(){
                    var key = currentKey();
                    pEl.value = DEFAULTS[key] || '';
                    var fd = new FormData();
                    fd.append('csrf_token', csrf()); fd.append('key', key); fd.append('prompt', '');
                    fetch('schema_prompt_save.php', { method:'POST', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(){ PROMPTS[key] = DEFAULTS[key]; say('↺ Reset to the built-in default.', '#4b5563'); })
                        .catch(function(){ say('✗ Reset failed (prompt reset locally).', '#dc2626'); });
                };

                // Initial fill (+ auto-detect core type first so the right prompt loads).
                if (SCOPE === 'page') autodetectCore();
                schemaGenSwapPrompt();
            })();
            </script>
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
