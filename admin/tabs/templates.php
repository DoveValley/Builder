<?php
// Templates tab — list view + block editor view
// $tab, $templates, $editingTemplate, $editingTemplateId set by index.php
?>
<div class="tab-content" style="<?= $tab === 'templates' ? '' : 'display:none;' ?>">
<?php tab_header('Landing Templates', 'Design the block structure for city landing pages. Each template × each city produces one generated page.', 'tab-templates'); ?>
<?php if ($editingTemplate === null && $editingRegistryEntry !== null): ?>

    <!-- ── Registry entry editor ────────────────────────────────────── -->
    <p style="margin-bottom:16px;"><a href="?tab=templates">&larr; Back to templates &amp; registry</a></p>

    <form action="templates_save.php" method="post">
        <input type="hidden" name="action"      value="registry_save">
        <input type="hidden" name="registry_id" value="<?= h($editingRegistryId) ?>">
        <input type="hidden" name="csrf_token"  value="<?= h($csrfToken) ?>">

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h2 style="margin:0;">Edit Registry Entry: <code><?= h($editingRegistryId) ?></code></h2>
                <button type="submit" class="btn">Save Entry</button>
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:2 1 220px;">
                    <label>Label <span class="hint">(shown in admin)</span></label>
                    <input type="text" name="reg_label" value="<?= h($editingRegistryEntry['label'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="flex:0 0 150px;">
                    <label>Mode</label>
                    <select name="reg_ai_mode">
                        <option value="standalone" <?= ($editingRegistryEntry['ai_mode'] ?? '') === 'standalone' ? 'selected' : '' ?>>Standalone</option>
                        <option value="inject"     <?= ($editingRegistryEntry['ai_mode'] ?? '') === 'inject'     ? 'selected' : '' ?>>Inject</option>
                    </select>
                </div>
                <div class="form-group" style="flex:0 0 160px;">
                    <label>Render as <span class="hint">(standalone)</span></label>
                    <input type="text" name="reg_ai_render_as" value="<?= h($editingRegistryEntry['ai_render_as'] ?? '') ?>" placeholder="e.g. text, feature_columns">
                </div>
            </div>

            <div class="form-group">
                <label>Description <span class="hint">(shown in registry list)</span></label>
                <input type="text" name="reg_description" value="<?= h($editingRegistryEntry['description'] ?? '') ?>">
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:0 0 160px;">
                    <label>Inject target <span class="hint">(inject mode)</span></label>
                    <select name="reg_ai_inject_target">
                        <option value=""         <?= ($editingRegistryEntry['ai_inject_target'] ?? '') === ''         ? 'selected' : '' ?>>None</option>
                        <option value="previous" <?= ($editingRegistryEntry['ai_inject_target'] ?? '') === 'previous' ? 'selected' : '' ?>>Previous block</option>
                        <option value="next"     <?= ($editingRegistryEntry['ai_inject_target'] ?? '') === 'next'     ? 'selected' : '' ?>>Next block</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 150px;">
                    <label>Target field <span class="hint">(inject mode)</span></label>
                    <input type="text" name="reg_ai_inject_field" value="<?= h($editingRegistryEntry['ai_inject_field'] ?? '') ?>" placeholder="e.g. hs_subtext, fq_items">
                </div>
                <div class="form-group" style="flex:0 0 150px;">
                    <label>Inject mode</label>
                    <select name="reg_ai_inject_mode">
                        <option value=""        <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === ''        ? 'selected' : '' ?>>None</option>
                        <option value="replace" <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === 'replace' ? 'selected' : '' ?>>Replace</option>
                        <option value="append"  <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === 'append'  ? 'selected' : '' ?>>Append</option>
                        <option value="prepend" <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === 'prepend' ? 'selected' : '' ?>>Prepend</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Prompt <span class="hint">Use {city}, {SS}, {industries}, {top_employers}, {salary_note}, {market_blurb}, {business}, {keyword} as variables</span></label>
                <textarea name="reg_ai_prompt" rows="16" style="font-family:monospace;font-size:0.82rem;"><?= h($editingRegistryEntry['ai_prompt'] ?? '') ?></textarea>
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:1 1 220px;">
                    <label>Output schema <span class="hint">(JSON — field name → type)</span></label>
                    <textarea name="reg_ai_output_schema" rows="5" style="font-family:monospace;font-size:0.82rem;"><?= h(json_encode($editingRegistryEntry['ai_output_schema'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                </div>
                <div class="form-group" style="flex:1 1 220px;">
                    <label>Default fields <span class="hint">(JSON — merged into generated block)</span></label>
                    <textarea name="reg_default_fields" rows="5" style="font-family:monospace;font-size:0.82rem;"><?= h(json_encode($editingRegistryEntry['default_fields'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn">Save Entry</button>
        </div>
    </form>

    <form action="templates_save.php" method="post" style="margin-top:8px;"
          onsubmit="return confirm('Delete registry entry &quot;<?= h(addslashes($editingRegistryId)) ?>&quot;? This cannot be undone.');">
        <input type="hidden" name="action"      value="registry_delete">
        <input type="hidden" name="registry_id" value="<?= h($editingRegistryId) ?>">
        <input type="hidden" name="csrf_token"  value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">Delete Entry</button>
    </form>

<?php elseif ($editingTemplate === null): ?>

    <!-- ── List view ─────────────────────────────────────────────────── -->
    <div class="card">
        <h2>Add a New Template</h2>
        <p class="hint" style="margin-bottom:18px;">
            Templates are master landing pages. The generation engine clones each template
            for every city in your city list, replacing <code>{city}</code>, <code>{SS}</code>,
            and other city vars throughout.
        </p>
        <form action="templates_save.php" method="post">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="form-group">
                <label>Template title</label>
                <input type="text" name="title" placeholder="e.g. PMP Certification Training {city} {SS}" required>
            </div>
            <div class="form-group">
                <label>Slug pattern <span style="font-weight:400;color:#888;">(optional)</span></label>
                <input type="text" name="slug_pattern" placeholder="e.g. pmp-certification-training-{city_slug}">
                <span class="hint">Use <code>{city_slug}</code> where the city goes. Leave blank to auto-generate from the title.</span>
            </div>
            <button type="submit" class="btn">Add Template</button>
        </form>
    </div>

    <div class="card">
        <h2>Templates</h2>
        <?php if (empty($templates)): ?>
            <p class="hint">No templates yet. Add one above.</p>
        <?php else: ?>
            <div class="repeat-items">
            <?php foreach ($templates as $tpl): ?>
                <div class="repeat-row" style="align-items:center;">
                    <div style="flex:1;">
                        <strong><?= h($tpl['title'] ?: '(untitled)') ?></strong><br>
                        <span class="hint">
                            Slug pattern: <code><?= h($tpl['slug_pattern'] ?? '') ?></code>
                            &mdash;
                            <?= count($tpl['content_blocks'] ?? []) ?> block<?= count($tpl['content_blocks'] ?? []) !== 1 ? 's' : '' ?>
                            &mdash;
                            <?= count($tpl['generation_steps'] ?? []) ?> generation step<?= count($tpl['generation_steps'] ?? []) !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <a class="btn btn-secondary btn-small" href="?tab=templates&template=<?= h($tpl['id']) ?>">Edit</a>

                    <form action="templates_save.php" method="post" style="display:inline;">
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="template_id" value="<?= h($tpl['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <button type="submit" class="btn btn-secondary btn-small">Duplicate</button>
                    </form>

                    <form action="templates_save.php" method="post" style="display:inline;"
                          onsubmit="return confirm('Delete template \'<?= h(addslashes($tpl['title'])) ?>\'? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="template_id" value="<?= h($tpl['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <button type="submit" class="remove-row" title="Delete template">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Prompt Registry ─────────────────────────────────────────────── -->
    <div class="card">
        <h2>Prompt Registry</h2>
        <p class="hint" style="margin-bottom:16px;">
            Named AI block configurations. Each entry defines the prompt, mode, and output schema for one type of AI-generated content.
            Templates reference these by <strong>Type ID</strong> — update the prompt here and all templates pick it up automatically.
        </p>

        <?php if (empty($aiRegistry)): ?>
            <p class="hint">No registry file yet.</p>
        <?php else: ?>
            <div class="repeat-items">
            <?php foreach ($aiRegistry as $rid => $rentry): ?>
                <div class="repeat-row" style="align-items:flex-start;flex-wrap:wrap;gap:8px;">
                    <div style="flex:1;min-width:220px;">
                        <strong><?= h($rentry['label'] ?? $rid) ?></strong>
                        &nbsp;<code style="font-size:.78rem;color:#64748b;"><?= h($rid) ?></code>
                        &nbsp;<?php
                            $modeClr = ($rentry['ai_mode'] ?? '') === 'inject' ? '#7c3aed' : '#0369a1';
                            echo '<span style="display:inline-block;padding:1px 7px;background:'.h($modeClr).';color:#fff;border-radius:10px;font-size:.72rem;font-weight:700;">' . h(strtoupper($rentry['ai_mode'] ?? 'standalone')) . '</span>';
                        ?>
                        <?php if (!empty($rentry['ai_render_as'])): ?>
                        &nbsp;<span style="font-size:.78rem;color:#64748b;">renders as <code><?= h($rentry['ai_render_as']) ?></code></span>
                        <?php elseif (!empty($rentry['ai_inject_field'])): ?>
                        &nbsp;<span style="font-size:.78rem;color:#64748b;">injects into <code><?= h($rentry['ai_inject_field']) ?></code></span>
                        <?php endif; ?>
                        <br><span class="hint"><?= h($rentry['description'] ?? '') ?></span>
                    </div>
                    <a class="btn btn-secondary btn-small" href="?tab=templates&registry=<?= h($rid) ?>">Edit</a>
                    <form action="templates_save.php" method="post" style="display:inline;"
                          onsubmit="return confirm('Delete registry entry &quot;<?= h(addslashes($rid)) ?>&quot;?');">
                        <input type="hidden" name="action"      value="registry_delete">
                        <input type="hidden" name="registry_id" value="<?= h($rid) ?>">
                        <input type="hidden" name="csrf_token"  value="<?= h($csrfToken) ?>">
                        <button type="submit" class="remove-row" title="Delete entry">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr style="margin:20px 0;">
        <h3 style="margin:0 0 12px;">Add Registry Entry</h3>
        <form action="templates_save.php" method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="action"     value="registry_add">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="form-group" style="flex:1 1 180px;margin:0;">
                <label>Type ID</label>
                <input type="text" name="registry_id" placeholder="e.g. city_benefits_section" pattern="[a-z][a-z0-9_]{1,59}" required>
                <span class="hint">Lowercase letters, numbers, underscores only</span>
            </div>
            <div class="form-group" style="flex:1 1 180px;margin:0;">
                <label>Label</label>
                <input type="text" name="reg_label" placeholder="e.g. City Benefits Section" required>
            </div>
            <button type="submit" class="btn" style="align-self:flex-end;margin-bottom:20px;">Add Entry</button>
        </form>
    </div>

<?php else: ?>

    <!-- ── Edit view ─────────────────────────────────────────────────── -->
    <p style="margin-bottom:16px;">
        <a href="?tab=templates">&larr; Back to all templates</a>
    </p>

    <?php
    // Find first generated page for this template to enable a preview link
    $tplPreviewSlug = '';
    if (defined('PAGES_DIR') && is_dir(PAGES_DIR)) {
        foreach (glob(PAGES_DIR . $editingTemplateId . '_*.json') ?: [] as $pf) {
            $pd = json_decode(file_get_contents($pf), true);
            if (!empty($pd['slug'])) { $tplPreviewSlug = $pd['slug']; break; }
        }
    }
    ?>
    <form action="templates_save.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="template_id" value="<?= h($editingTemplateId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn">Save Template</button>
            <?php if ($tplPreviewSlug): ?>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
            <?php endif; ?>
        </div>

        <!-- Template settings -->
        <div class="card">
            <h2>Template Settings</h2>
            <div class="form-group">
                <label>Template title</label>
                <input type="text" name="template_title" value="<?= h($editingTemplate['title'] ?? '') ?>">
                <span class="hint">Use <code>{city}</code> and <code>{SS}</code> shortcodes — e.g. "PMP Certification Training {city} {SS}"</span>
            </div>
            <div class="form-group">
                <label>Slug pattern</label>
                <input type="text" name="slug_pattern" value="<?= h($editingTemplate['slug_pattern'] ?? '') ?>" required>
                <span class="hint">
                    Use <code>{city_slug}</code> where the city goes — e.g. <code>pmp-certification-training-{city_slug}</code>.
                    Generated pages will be available at <code>/{slug-pattern-resolved}</code>.
                </span>
            </div>
            <div class="form-group">
                <label>Generation steps <span style="font-weight:400;color:#888;">(JSON)</span></label>
                <textarea name="generation_steps" rows="4" style="font-family:monospace;font-size:13px;"><?= h(json_encode($editingTemplate['generation_steps'] ?? [['step' => 'city_vars']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                <span class="hint">
                    Array of steps the generation engine runs for each city. The built-in step is
                    <code>city_vars</code> (replaces all city shortcodes). Additional steps (AI, custom pricing, etc.)
                    will be added in Phase 5. Each step: <code>{"step":"step_name","options":{}}</code>.
                </span>
            </div>
        </div>

        <!-- Block editor (reuses existing infrastructure) -->
        <?php render_content_blocks_editor($editingTemplate['content_blocks'] ?? []); ?>

        <!-- SEO editor -->
        <?php render_seo_editor($editingTemplate['seo'] ?? []); ?>

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px;">
            <button type="submit" class="btn">Save Template</button>
            <?php if ($tplPreviewSlug): ?>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Danger zone -->
    <form action="templates_save.php" method="post" style="margin-top:12px;"
          onsubmit="return confirm('Delete this template? This cannot be undone. Generated city pages are not affected.');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="template_id" value="<?= h($editingTemplateId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">Delete This Template</button>
    </form>

<?php endif; ?>
</div>
