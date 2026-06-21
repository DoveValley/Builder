    <div class="tab-content" style="<?= $tab === 'content' ? '' : 'display:none;' ?>">
        <form action="save.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="content">
            <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn">Save Content</button>
                <a href="../index.php?show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
                <a href="../index.php" target="_blank" class="btn btn-secondary">Preview Home Page &rarr;</a>
            </div>

            <?php if ($tab === 'content'): ?>
            <?php render_content_blocks_editor($blocks); ?>

            <?php render_seo_editor($seo); ?>
            <?php endif; ?>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn">Save Content</button>
                <a href="../index.php?show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
                <a href="../index.php" target="_blank" class="btn btn-secondary">Preview Home Page &rarr;</a>
            </div>
        </form>
    </div>
