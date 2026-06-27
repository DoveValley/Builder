    <?php
    ?>
    <div class="tab-content" style="<?= $tab === 'pages' ? '' : 'display:none;' ?>">
        <?php tab_header('Core / Template Pages', 'Create and manage standalone landing pages — about, contact, services, and other pages outside the homepage and blog.', 'tab-pages'); ?>
        <?php if ($editingPage === null): ?>

            <div class="card">
                <h2>Add a New Page</h2>
                <p class="hint" style="margin-bottom:18px;">
                    All pages share the same header, footer, and colors as your home page,
                    but have their own content and SEO settings.
                </p>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="page_add">
                    <input type="hidden" name="starter" id="selected_starter" value="">

                    <?php
                    $spStarters = starters_load();
                    $spCats     = starter_categories();
                    $spByCat    = [];
                    foreach ($spStarters as $s) { $spByCat[$s['category'] ?? 'universal'][] = $s; }
                    $spFirstCat = array_key_first($spCats);
                    ?>
                    <div class="form-group">
                        <label>Start from a template</label>

                        <!-- Category tab strip -->
                        <div class="inner-tabs" style="margin-bottom:10px;" id="sp-tabs">
                            <?php foreach ($spCats as $k => $v): ?>
                            <button type="button" class="inner-tab <?= $k === $spFirstCat ? 'active' : '' ?>"
                                    onclick="spShowCat('<?= h($k) ?>')"><?= h($v) ?></button>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach ($spCats as $k => $v): ?>
                        <div class="starter-picker sp-cat-panel" id="sp-cat-<?= h($k) ?>" style="<?= $k !== $spFirstCat ? 'display:none;' : '' ?>">
                            <?php foreach ($spByCat[$k] ?? [] as $s): ?>
                            <div class="starter-card" data-starter-id="<?= h($s['id']) ?>"
                                 onclick="selectStarter(this)" title="<?= h($s['desc']) ?>">
                                <div class="starter-card-label"><?= h($s['label']) ?></div>
                                <div class="starter-card-desc"><?= h($s['desc']) ?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($spByCat[$k])): ?>
                            <span class="hint">No starters in this category yet.</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

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

                <script>
                function selectStarter(card) {
                    document.querySelectorAll('.starter-card').forEach(c => c.classList.remove('is-selected'));
                    card.classList.add('is-selected');
                    document.getElementById('selected_starter').value = card.dataset.starterId;
                }
                function spShowCat(cat) {
                    document.querySelectorAll('.sp-cat-panel').forEach(p => p.style.display = 'none');
                    document.querySelectorAll('#sp-tabs .inner-tab').forEach(t => t.classList.remove('active'));
                    const panel = document.getElementById('sp-cat-' + cat);
                    if (panel) panel.style.display = '';
                    const btn = document.querySelector('#sp-tabs .inner-tab[onclick*="\'' + cat + '\'"]');
                    if (btn) btn.classList.add('active');
                    // auto-select first card in this panel
                    const first = panel && panel.querySelector('.starter-card');
                    if (first) selectStarter(first);
                }
                // Auto-select first card in first category on load
                (function() {
                    const first = document.querySelector('.sp-cat-panel:not([style*="none"]) .starter-card');
                    if (first) selectStarter(first);
                })();
                </script>
            </div>

            <?php
            $landingPages = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') === 'landing');
            $otherPages   = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') !== 'landing');
            $renderPageList = function($list) use ($csrfToken) {
                if (empty($list)) { echo '<p class="hint">None yet.</p>'; return; }
                echo '<div class="repeat-items">';
                foreach ($list as $pid => $p) {
                    echo '<div class="repeat-row" style="align-items:center;">';
                    echo '<div style="flex:1;"><strong>' . h($p['title'] !== '' ? $p['title'] : '(untitled)') . '</strong><br>';
                    echo '<span class="hint">/' . h($p['slug']) . ' &mdash; <a href="../page.php?slug=' . h($p['slug']) . '" target="_blank" rel="noopener">preview</a></span></div>';
                    echo '<a class="btn btn-secondary btn-small" href="?tab=pages&page=' . h($pid) . '">Edit</a>';
                    echo '<form action="save.php" method="post" style="display:inline;">';
                    echo '<input type="hidden" name="section" value="page_clone">';
                    echo '<input type="hidden" name="page_id" value="' . h($pid) . '">';
                    echo '<input type="hidden" name="csrf_token" value="' . h($csrfToken) . '">';
                    echo '<button type="submit" class="btn btn-secondary btn-small" title="Clone this page">Clone</button>';
                    echo '</form>';
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
                <h2>Core Pages</h2>
                <?php $renderPageList($otherPages, false); ?>
            </div>

            <div class="card">
                <h2>Landing Pages</h2>
                <?php $renderPageList($landingPages); ?>
            </div>

        <?php else: ?>

            <p style="margin-bottom:16px;"><a href="?tab=pages">&larr; Back to all pages</a></p>

            <form action="save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="page_id" value="<?= h($editingPageId) ?>">
                <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Page</button>
                    <a href="../page.php?slug=<?= h($editingPage['slug'] ?? '') ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
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
                    <a href="../page.php?slug=<?= h($editingPage['slug']) ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
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
