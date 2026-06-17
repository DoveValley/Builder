<?php
// Popups plugin admin panel.
// Rendered inside the Plugins tab by admin/tabs/plugins.php.
// All index.php scope variables ($data, $csrfToken, etc.) are available.

$infoPopup = $data['popups']['info'] ?? [];
?>

<div class="admin-section">
    <form method="post" action="plugin_save.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="plugin_id"  value="popups">
        <input type="hidden" name="action"      value="save">

        <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Popup</button></div>

        <div class="card">
            <h2>Info Popup</h2>
            <p class="hint" style="margin-bottom:18px;">
                This popup opens when visitors click the <strong>&#8505;</strong> circle in the nav bar or sticky bottom bar.
                Use it for "How Your Calls Are Handled" or any quick info disclosure.
            </p>

            <div class="form-group">
                <label>Popup heading</label>
                <input type="text" name="popup_info_heading"
                       value="<?= h($infoPopup['heading'] ?? 'How Your Calls Are Handled') ?>"
                       placeholder="e.g. How Your Calls Are Handled">
            </div>

            <div class="form-group">
                <label>Popup image (optional — shown at top of popup)</label>
                <?php if (!empty($infoPopup['image'])): ?>
                    <img src="../<?= h($infoPopup['image']) ?>" style="max-height:80px;border-radius:6px;margin-bottom:8px;display:block;" onerror="this.style.display='none'">
                    <label style="font-weight:400;margin-bottom:8px;display:block;">
                        <input type="checkbox" name="popup_info_remove_image" value="1"> Remove image
                    </label>
                <?php endif; ?>
                <input type="file" name="popup_info_image" accept="image/png,image/jpeg,image/gif,image/webp">
                <input type="hidden" name="popup_info_image_existing" value="<?= h($infoPopup['image'] ?? '') ?>">
                <?php photo_picker_btn('popup_info_image_existing'); ?>
            </div>

            <div class="form-group">
                <label>Popup body text</label>
                <textarea name="popup_info_body" rows="10" class="rich-editor"
                          placeholder="Enter the full popup text here. Leave a blank line between paragraphs."><?= h($infoPopup['body'] ?? '') ?></textarea>
                <span class="hint">Leave a blank line between paragraphs. Surround text with **double asterisks** for bold.</span>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="popup_info_enabled" value="1"
                           <?= !empty($infoPopup['enabled']) ? 'checked' : '' ?>>
                    Show &#8505; trigger button in header nav bar and sticky bottom bar
                </label>
            </div>
        </div>

        <button type="submit" class="btn">Save Popup</button>
    </form>
</div>
