    <div class="tab-content" style="<?= $tab === 'seo' ? '' : 'display:none;' ?>">
        <?php tab_header('SEO', 'Global SEO settings — business info, breadcrumbs, and redirects. Schema markup is set per page in each page editor.', 'tab-seo'); ?>
        <form method="post" action="save.php">
            <input type="hidden" name="section" value="local_business">
            <?php render_local_business_editor($data['local_business'] ?? []); ?>
            <div style="margin-top:24px;">
                <button type="submit" class="btn">Save Local Business Info</button>
            </div>
        </form>

        <form method="post" action="save.php" style="margin-top:32px;">
            <input type="hidden" name="section" value="breadcrumbs">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <?php $bc = $data['breadcrumbs'] ?? []; ?>
            <div class="card">
                <h2>Breadcrumbs</h2>
                <p class="hint" style="margin-bottom:18px;">Global breadcrumb settings. Individual pages can override visibility in their own SEO section.</p>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="bc_enabled" value="1" <?= !empty($bc['enabled']) ? 'checked' : '' ?>>
                        Show breadcrumbs site-wide
                    </label>
                    <span class="hint">Uncheck to hide breadcrumbs on all pages.</span>
                </div>
                <hr style="margin:20px 0;border-color:#e5e7eb;">
                <h3 style="margin:0 0 12px;font-size:1rem;">Hero page background</h3>
                <p class="hint" style="margin-bottom:14px;">When the first block is a hero, the breadcrumb bar gets a dark background to blend in. Choose how that color is set.</p>
                <div class="form-group">
                    <label>Background mode</label>
                    <select name="bc_hero_bg_mode" id="bc_hero_bg_mode" onchange="document.getElementById('bc_custom_color_row').style.display=this.value==='custom'?'':'none'">
                        <option value="auto" <?= ($bc['hero_bg_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto — matches header color</option>
                        <option value="custom" <?= ($bc['hero_bg_mode'] ?? 'auto') === 'custom' ? 'selected' : '' ?>>Custom color</option>
                    </select>
                </div>
                <div class="form-group" id="bc_custom_color_row" style="<?= ($bc['hero_bg_mode'] ?? 'auto') === 'custom' ? '' : 'display:none;' ?>">
                    <label for="bc_hero_bg_color">Hero breadcrumb background color</label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="color" name="bc_hero_bg_color" id="bc_hero_bg_color" value="<?= h($bc['hero_bg_color'] ?? '#0d1b3e') ?>">
                        <input type="text" name="bc_hero_bg_color_hex" id="bc_hero_bg_color_hex" value="<?= h($bc['hero_bg_color'] ?? '#0d1b3e') ?>" style="width:120px;" placeholder="#0d1b3e">
                    </div>
                </div>
            </div>
            <div style="margin-top:16px;">
                <button type="submit" class="btn">Save Breadcrumb Settings</button>
            </div>
        </form>

        <?php
        $redirects = [];
        if (defined('REDIRECTS_FILE') && file_exists(REDIRECTS_FILE)) {
            $redirects = json_decode(file_get_contents(REDIRECTS_FILE), true) ?: [];
        }
        ?>
        <form method="post" action="redirects_save.php" style="margin-top:32px;" id="redirects-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <div class="card">
                <h2>301 Redirects</h2>
                <p class="hint" style="margin-bottom:16px;">Redirect old URLs to new ones. These are written into <code>.htaccess</code> every time you Generate Static Site.</p>
                <table style="width:100%;border-collapse:collapse;margin-bottom:12px;" id="redir-table">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">
                            <th style="text-align:left;padding:8px 12px;font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;">From path</th>
                            <th style="text-align:left;padding:8px 12px;font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;">To path</th>
                            <th style="padding:8px 12px;width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="redir-body">
                    <?php foreach ($redirects as $r): ?>
                        <tr class="redir-row">
                            <td style="padding:6px 8px;"><input type="text" name="redir_from[]" value="<?= h($r['from'] ?? '') ?>" placeholder="/old-slug" style="width:100%;"></td>
                            <td style="padding:6px 8px;"><input type="text" name="redir_to[]"   value="<?= h($r['to']   ?? '') ?>" placeholder="/new-slug/" style="width:100%;"></td>
                            <td style="padding:6px 8px;text-align:center;"><button type="button" onclick="this.closest('tr').remove()" style="background:none;border:none;color:#dc2626;font-size:1.1rem;cursor:pointer;line-height:1;">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="btn btn-secondary btn-small" onclick="redirAddRow()">+ Add Redirect</button>
            </div>
            <div style="margin-top:16px;">
                <button type="submit" class="btn">Save Redirects</button>
            </div>
        </form>
    </div>

<script>
function redirAddRow() {
    var tr = document.createElement('tr');
    tr.className = 'redir-row';
    tr.innerHTML = '<td style="padding:6px 8px;"><input type="text" name="redir_from[]" placeholder="/old-slug" style="width:100%;"></td>'
                 + '<td style="padding:6px 8px;"><input type="text" name="redir_to[]"   placeholder="/new-slug/" style="width:100%;"></td>'
                 + '<td style="padding:6px 8px;text-align:center;"><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;color:#dc2626;font-size:1.1rem;cursor:pointer;line-height:1;">&times;</button></td>';
    document.getElementById('redir-body').appendChild(tr);
    tr.querySelector('input').focus();
}
</script>
