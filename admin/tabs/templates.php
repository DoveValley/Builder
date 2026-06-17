<?php
// Templates tab — list view + block editor view
// $tab, $templates, $editingTemplate, $editingTemplateId set by index.php
?>
<div class="tab-content" style="<?= $tab === 'templates' ? '' : 'display:none;' ?>">

<?php if ($editingTemplate === null): ?>

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

<?php else: ?>

    <!-- ── Edit view ─────────────────────────────────────────────────── -->
    <p style="margin-bottom:16px;">
        <a href="?tab=templates">&larr; Back to all templates</a>
    </p>

    <form action="templates_save.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="template_id" value="<?= h($editingTemplateId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn">Save Template</button>
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
