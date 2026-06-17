<?php
// Landing Links: Multi-City admin panel.
// All index.php scope variables ($data, $csrfToken, $templates, etc.) are available.

$cfg = $data['landing_links'] ?? [];
$tplFilter = $cfg['template_filter'] ?? '';
?>

<div class="admin-section">

    <div class="card" style="margin-bottom:16px;">
        <p class="hint">Use <code>[locations]</code> in any <strong>Custom HTML</strong> block to render the city landing page directory. Only cities with at least one generated page are shown.</p>
    </div>

    <form method="post" action="plugin_save.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="plugin_id"  value="landing_links">
        <input type="hidden" name="action"      value="save">

        <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Settings</button></div>

        <div class="card" style="margin-bottom:16px;">
            <h2>Display</h2>

            <div class="form-group">
                <label>Format</label>
                <select name="format">
                    <option value="by_state" <?= ($cfg['format'] ?? 'by_state') === 'by_state' ? 'selected' : '' ?>>By state — states as headings, cities grouped below</option>
                    <option value="columns"  <?= ($cfg['format'] ?? '') === 'columns'  ? 'selected' : '' ?>>Columns — CSS multi-column layout</option>
                    <option value="list"     <?= ($cfg['format'] ?? '') === 'list'     ? 'selected' : '' ?>>List — flat nested list</option>
                </select>
            </div>

            <div class="form-group">
                <label>Columns <span class="hint" style="font-weight:normal;">(columns format only)</span></label>
                <select name="cols">
                    <?php foreach ([2, 3, 4] as $n): ?>
                        <option value="<?= $n ?>" <?= (int)($cfg['cols'] ?? 3) === $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
            <h2>Link Text</h2>
            <div class="form-group">
                <label>Link text pattern</label>
                <input type="text" name="link_text"
                       value="<?= h($cfg['link_text'] ?? '{template_title} in {city}, {SS}') ?>"
                       placeholder="{template_title} in {city}, {SS}">
                <span class="hint">
                    Tokens: <code>{template_title}</code> <code>{city}</code> <code>{SS}</code>
                    <code>{state}</code> <code>{city_slug}</code> <code>{city_state}</code>
                </span>
            </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
            <h2>Template Filter</h2>
            <p class="hint" style="margin-bottom:12px;">Show pages from all templates, or limit to one.</p>
            <div class="form-group">
                <label>Show pages from</label>
                <select name="template_filter">
                    <option value="">All templates</option>
                    <?php foreach ($templates as $tpl): ?>
                        <option value="<?= h($tpl['id']) ?>" <?= $tplFilter === $tpl['id'] ? 'selected' : '' ?>>
                            <?= h($tpl['title'] ?? $tpl['id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($templates)): ?>
                    <span class="hint">No templates yet — add templates and generate pages first.</span>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="btn">Save Settings</button>
    </form>
</div>
