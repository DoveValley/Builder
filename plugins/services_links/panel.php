<?php
// Services Links plugin admin panel.
// All index.php scope variables ($data, $csrfToken, etc.) are available.

$cfg = $data['services_links'] ?? [];
$services = $cfg['services'] ?? [];
?>

<div class="admin-section">

    <div class="card" style="margin-bottom:16px;">
        <p class="hint">Use <code>[services_links]</code> in any <strong>Custom HTML</strong> block to render this list as a styled grid of city-resolved service links.</p>
    </div>

    <form method="post" action="plugin_save.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="plugin_id"  value="services_links">

        <div style="margin-bottom:16px;"><button type="submit" name="action" value="save" class="btn">Save Services Links</button></div>

        <!-- Service List (editable rows) -->
        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:12px;flex-wrap:wrap;">
                <h2 style="margin:0;">Service Links</h2>
                <button type="submit" name="action" value="sync" class="btn"
                    onclick="return confirm('Sync from Landing Templates?\n\nThis REPLACES the list with exactly your current landing templates (one row per template, using its real slug). Any manual rows or edits below will be discarded.');">
                    &#128260; Sync from Landing Templates
                </button>
            </div>
            <p class="hint" style="margin-bottom:12px;">Each row is a service <strong>name</strong> + its <strong>link</strong>. <strong>Sync</strong> <em>replaces</em> the whole list with your current Landing Templates — one row per template, using each template's real slug (removes stale/orphan rows, adds new ones). Edit freely after syncing; re-syncing resets to the templates again. Links may use <code>{city_slug}</code> and other tokens, resolved per city at render.</p>
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="text-align:left;">
                    <th style="padding:4px 8px;font-size:.78rem;color:#64748b;">Name</th>
                    <th style="padding:4px 8px;font-size:.78rem;color:#64748b;">Link</th>
                    <th style="width:44px;"></th>
                </tr></thead>
                <tbody id="svc-rows">
                <?php foreach ($services as $s):
                    $nm = is_array($s) ? ($s['name'] ?? '') : $s;
                    $ur = is_array($s) ? ($s['url']  ?? '') : '';
                ?>
                    <tr>
                        <td style="padding:3px 8px;"><input type="text" name="svc_name[]" value="<?= h($nm) ?>" style="width:100%;"></td>
                        <td style="padding:3px 8px;"><input type="text" name="svc_url[]" value="<?= h($ur) ?>" placeholder="/service-slug-{city_slug}" style="width:100%;font-family:monospace;font-size:.82rem;"></td>
                        <td style="padding:3px 8px;"><button type="button" class="btn btn-danger" style="padding:2px 9px;" onclick="this.closest('tr').remove()">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn" style="margin-top:10px;" onclick="svcAddRow()">+ Add row</button>
        </div>

        <!-- Layout Settings -->
        <div class="card" style="margin-bottom:16px;">
            <h2>Layout</h2>

            <div class="form-group">
                <label>Style</label>
                <select name="style">
                    <option value="dark"  <?= ($cfg['style'] ?? 'dark') === 'dark'  ? 'selected' : '' ?>>Dark (background image with overlay)</option>
                    <option value="light" <?= ($cfg['style'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light (white background)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Columns</label>
                <select name="cols">
                    <?php foreach ([2,3,4,5,6] as $n): ?>
                        <option value="<?= $n ?>" <?= (int)($cfg['cols'] ?? 5) === $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Section anchor ID</label>
                <input type="text" name="anchor" value="<?= h($cfg['anchor'] ?? '') ?>" placeholder="e.g. pest_services">
                <span class="hint">Used for in-page links like <code>#pest_services</code>. Leave blank for none.</span>
            </div>
        </div>

        <!-- Text Content -->
        <div class="card" style="margin-bottom:16px;">
            <h2>Text Content</h2>
            <p class="hint" style="margin-bottom:12px;">Supports shortcode tokens: <code>{city}</code>, <code>{SS}</code>, <code>{city_state}</code>, <code>{city_slug}</code>, etc.</p>

            <div class="form-group">
                <label>Heading</label>
                <input type="text" name="heading" value="<?= h($cfg['heading'] ?? '') ?>" placeholder="e.g. Our Pest Control Services in {city_state}">
            </div>

            <div class="form-group">
                <label>Sub-label <span class="hint" style="font-weight:normal;">(light style only)</span></label>
                <input type="text" name="sublabel" value="<?= h($cfg['sublabel'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Sub-heading / body text</label>
                <textarea name="subtext" rows="3" placeholder="Short description shown under the heading."><?= h($cfg['subtext'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Dark Style Settings -->
        <div class="card" style="margin-bottom:16px;">
            <h2>Dark Style Settings</h2>

            <div class="form-group">
                <label>Background photo</label>
                <?php if (!empty($cfg['bg_photo'])): ?>
                    <img src="../<?= h($cfg['bg_photo']) ?>" style="max-height:80px;border-radius:6px;margin-bottom:8px;display:block;" onerror="this.style.display='none'">
                    <label style="font-weight:400;margin-bottom:8px;display:block;">
                        <input type="checkbox" name="bg_photo_remove" value="1"> Remove photo
                    </label>
                <?php endif; ?>
                <input type="file" name="bg_photo_upload" accept="image/png,image/jpeg,image/gif,image/webp">
                <input type="hidden" name="bg_photo_existing" value="<?= h($cfg['bg_photo'] ?? '') ?>">
                <?php photo_picker_btn('bg_photo_existing'); ?>
            </div>

            <div class="form-group">
                <label>Overlay opacity <span class="hint" style="font-weight:normal;">(0 = none, 0.6 = standard, 0.8 = heavy)</span></label>
                <input type="number" name="overlay" value="<?= h($cfg['overlay'] ?? '0.20') ?>" min="0" max="1" step="0.05" style="width:120px;">
            </div>
        </div>

        <!-- Light Style Settings -->
        <div class="card" style="margin-bottom:16px;">
            <h2>Light Style Settings</h2>

            <div class="form-group">
                <label>Background color</label>
                <input type="text" name="bg_color" value="<?= h($cfg['bg_color'] ?? '#ffffff') ?>" placeholder="#ffffff" style="width:160px;">
            </div>

            <div class="form-group">
                <label>Accent color</label>
                <select name="accent">
                    <option value="accent" <?= ($cfg['accent'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (theme color)</option>
                    <option value="header" <?= ($cfg['accent'] ?? '') === 'header' ? 'selected' : '' ?>>Header color</option>
                    <option value="custom" <?= ($cfg['accent'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                </select>
                <input type="text" name="accent_custom" value="<?= h($cfg['accent_custom'] ?? '#fd783b') ?>" placeholder="#fd783b" style="width:120px;margin-left:8px;">
            </div>
        </div>

        <!-- URL Settings -->
        <div class="card" style="margin-bottom:16px;">
            <h2>URL Pattern <span class="hint" style="font-weight:normal;">(fallback)</span></h2>
            <div class="form-group">
                <label>URL pattern</label>
                <input type="text" name="url_pattern" value="<?= h($cfg['url_pattern'] ?? '/{service_slug}-{city_slug}') ?>" placeholder="/{service_slug}-{city_slug}">
                <span class="hint">Only used for rows with a <strong>blank Link</strong> field (and legacy name-only entries): <code>{service_slug}</code> = slugified service name &nbsp;·&nbsp; <code>{city_slug}</code> = city slug. Rows with a Link use it as-is.</span>
            </div>
        </div>

        <button type="submit" name="action" value="save" class="btn">Save Services Links</button>
    </form>

    <script>
    function svcAddRow(){
        var tb = document.getElementById('svc-rows');
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td style="padding:3px 8px;"><input type="text" name="svc_name[]" style="width:100%;"></td>' +
            '<td style="padding:3px 8px;"><input type="text" name="svc_url[]" placeholder="/service-slug-{city_slug}" style="width:100%;font-family:monospace;font-size:.82rem;"></td>' +
            '<td style="padding:3px 8px;"><button type="button" class="btn btn-danger" style="padding:2px 9px;" onclick="this.closest(\'tr\').remove()">&times;</button></td>';
        tb.appendChild(tr);
    }
    </script>
</div>
