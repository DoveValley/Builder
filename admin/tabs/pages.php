    <div class="tab-content" style="<?= $tab === 'pages' ? '' : 'display:none;' ?>">
        <?php if ($editingPage === null): ?>

            <div class="card">
                <h2>Add a New Page</h2>
                <p class="hint" style="margin-bottom:18px;">
                    All pages share the same header, footer, and colors as your home page,
                    but have their own content and SEO settings.
                </p>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="page_add">
                    <div class="form-group">
                        <label for="new_page_title">Page title</label>
                        <input type="text" id="new_page_title" name="title" placeholder="e.g. About Us" required>
                    </div>
                    <div class="form-group">
                        <label for="new_page_slug">URL slug (optional)</label>
                        <input type="text" id="new_page_slug" name="slug" placeholder="e.g. about-us">
                        <span class="hint">Letters, numbers, and hyphens only. Leave blank to generate one automatically from the title.</span>
                    </div>
                    <div class="form-group">
                        <label for="new_page_type">Page type</label>
                        <select id="new_page_type" name="page_type">
                            <option value="landing">Landing Page</option>
                            <option value="other">Core Page</option>
                        </select>
                        <span class="hint">Landing pages are city/service pages built for SEO cloning. Core pages are things like Privacy Policy, Terms, Contact.</span>
                    </div>
                    <button type="submit" class="btn">Add Page</button>
                </form>
            </div>

            <?php
            $landingPages = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') !== 'other');
            $otherPages   = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') === 'other');
            $renderPageList = function($list) {
                if (empty($list)) { echo '<p class="hint">None yet.</p>'; return; }
                echo '<div class="repeat-items">';
                foreach ($list as $pid => $p) {
                    echo '<div class="repeat-row" style="align-items:center;">';
                    echo '<div style="flex:1;"><strong>' . h($p['title'] !== '' ? $p['title'] : '(untitled)') . '</strong><br>';
                    echo '<span class="hint">/' . h($p['slug']) . ' &mdash; <a href="../page.php?slug=' . h($p['slug']) . '" target="_blank" rel="noopener">preview</a></span></div>';
                    echo '<a class="btn btn-secondary btn-small" href="?tab=pages&page=' . h($pid) . '">Edit</a>';
                    echo '<form action="save.php" method="post" style="display:inline;" onsubmit="return confirm(\'Delete this page? This cannot be undone.\');">';
                    echo '<input type="hidden" name="section" value="page_delete">';
                    echo '<input type="hidden" name="page_id" value="' . h($pid) . '">';
                    echo '<button type="submit" class="remove-row" title="Delete page">&times;</button>';
                    echo '</form></div>';
                }
                echo '</div>';
            };
            ?>

            <div class="card">
                <h2>Landing Pages</h2>
                <?php $renderPageList($landingPages); ?>
            </div>

            <div class="card">
                <h2>Core Pages</h2>
                <?php $renderPageList($otherPages); ?>
            </div>

        <?php else: ?>

            <p style="margin-bottom:16px;"><a href="?tab=pages">&larr; Back to all landing pages</a></p>

            <form action="save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="page_id" value="<?= h($editingPageId) ?>">
                <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Page</button>
                    <a href="../page.php?slug=<?= h($editingPage['slug'] ?? '') ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
                </div>

                <div class="card">
                    <h2>Page Settings</h2>
                    <div class="form-group">
                        <label for="page_title">Page title</label>
                        <input type="text" id="page_title" name="page_title" value="<?= h($editingPage['title']) ?>">
                        <span class="hint">Shown in the browser tab and used as the page's SEO title.</span>
                    </div>
                    <div class="form-group">
                        <label for="page_slug">URL slug</label>
                        <input type="text" id="page_slug" name="page_slug" value="<?= h($editingPage['slug']) ?>">
                        <span class="hint">
                            This page is available at
                            <code>/page.php?slug=<?= h($editingPage['slug']) ?></code>
                            (or <code>/<?= h($editingPage['slug']) ?></code> if pretty URLs are enabled &mdash; see README).
                        </span>
                    </div>
                </div>

                <?php render_content_blocks_editor($editingPage['content_blocks']); ?>

                <?php render_seo_editor($editingPage['seo']); ?>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Page</button>
                    <a href="../page.php?slug=<?= h($editingPage['slug']) ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
                </div>
            </form>

            <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Delete this landing page? This cannot be undone.');">
                <input type="hidden" name="section" value="page_delete">
                <input type="hidden" name="page_id" value="<?= h($editingPageId) ?>">
                <button type="submit" class="btn btn-danger">Delete This Page</button>
            </form>

        <?php endif; ?>
    </div>
