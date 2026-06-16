    <div class="tab-content" style="<?= $tab === 'seo' ? '' : 'display:none;' ?>">
        <form method="post" action="save.php">
            <input type="hidden" name="section" value="local_business">
            <?php render_local_business_editor($data['local_business'] ?? []); ?>
            <div style="margin-top:24px;">
                <button type="submit" class="btn">Save Local Business Info</button>
            </div>
        </form>
    </div>
