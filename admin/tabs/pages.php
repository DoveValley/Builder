    <?php
    // ── Promote-view helpers ──────────────────────────────────────────────────
    // Called only when $promoting is true; needs $siteVars from index.php scope.

    function _pages_suggest_slug_pattern(string $slug, array $sv): string {
        $citySlug = strtolower($sv['city_slug'] ?? '');
        $city     = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($sv['city'] ?? '')));
        $ss       = strtolower(trim($sv['SS'] ?? ''));

        // Map from → to, sorted longest-first so specific tokens win.
        $map = [];
        if ($citySlug !== '') $map[$citySlug] = '{city_slug}';
        if ($city !== '')     $map[$city]     = '{city}';
        if ($ss !== '')       $map[$ss]       = '{SS}';
        uksort($map, fn($a, $b) => strlen($b) - strlen($a));

        $pattern = $slug;
        foreach ($map as $from => $to) {
            $pattern = str_replace($from, $to, $pattern);
        }
        return $pattern;
    }

    function _pages_suggest_title(string $title, array $sv): string {
        $city = trim($sv['city'] ?? '');
        $ss   = trim($sv['SS']   ?? '');

        $t = $title;
        if ($city !== '' && $ss !== '') $t = str_replace("$city $ss", '{city} {SS}', $t);
        if ($city !== '')               $t = str_replace($city, '{city}', $t);
        if ($ss !== '')                 $t = str_replace($ss, '{SS}', $t);
        return $t;
    }

    $promoting = ($editingPage !== null && ($editingPage['page_type'] ?? '') === 'landing' && ($_GET['action'] ?? '') === 'promote');
    ?>

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
            $landingPages = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') === 'landing');
            $otherPages   = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') !== 'landing');
            $renderPageList = function($list, bool $showPromote = false) {
                if (empty($list)) { echo '<p class="hint">None yet.</p>'; return; }
                echo '<div class="repeat-items">';
                foreach ($list as $pid => $p) {
                    echo '<div class="repeat-row" style="align-items:center;">';
                    echo '<div style="flex:1;"><strong>' . h($p['title'] !== '' ? $p['title'] : '(untitled)') . '</strong><br>';
                    echo '<span class="hint">/' . h($p['slug']) . ' &mdash; <a href="../page.php?slug=' . h($p['slug']) . '" target="_blank" rel="noopener">preview</a></span></div>';
                    echo '<a class="btn btn-secondary btn-small" href="?tab=pages&page=' . h($pid) . '">Edit</a>';
                    if ($showPromote) {
                        echo '<a class="btn btn-secondary btn-small" href="?tab=pages&page=' . h($pid) . '&action=promote" title="Copy this page into Templates for multi-city generation">&rarr; Template</a>';
                    }
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
                <p class="hint" style="margin-bottom:12px;">Use <strong>&rarr; Template</strong> to promote a landing page into the Templates system for multi-city generation.</p>
                <?php $renderPageList($landingPages, true); ?>
            </div>

            <div class="card">
                <h2>Core Pages</h2>
                <?php $renderPageList($otherPages, false); ?>
            </div>

        <?php elseif ($promoting): ?>

            <?php
            $suggestedSlug  = _pages_suggest_slug_pattern($editingPage['slug'], $siteVars);
            $suggestedTitle = _pages_suggest_title($editingPage['title'], $siteVars);
            ?>

            <p style="margin-bottom:16px;"><a href="?tab=pages">&larr; Back to all pages</a></p>

            <div class="card">
                <h2>Promote to Template: <?= h($editingPage['title']) ?></h2>
                <p style="color:#374151;margin-bottom:18px;">
                    This copies the page's blocks and SEO into a new <strong>Template</strong>.
                    Templates power multi-city generation — one template creates one page per city automatically.
                    The original page in <em>Landing Pages</em> is kept by default; uncheck below to remove it after promoting.
                </p>

                <form action="templates_save.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action"   value="migrate_from_page">
                    <input type="hidden" name="page_id"  value="<?= h($editingPageId) ?>">

                    <div class="form-group">
                        <label for="tpl_title">Template title</label>
                        <input type="text" id="tpl_title" name="tpl_title" value="<?= h($suggestedTitle) ?>" required>
                        <span class="hint">Use <code>{city}</code>, <code>{SS}</code> placeholders — they're replaced when generating each city page.</span>
                    </div>

                    <div class="form-group">
                        <label for="tpl_slug_pattern">Slug pattern</label>
                        <input type="text" id="tpl_slug_pattern" name="slug_pattern" value="<?= h($suggestedSlug) ?>" required>
                        <span class="hint">
                            Auto-detected from <code><?= h($editingPage['slug']) ?></code> using your site vars.
                            Supported tokens: <code>{city_slug}</code>, <code>{city}</code>, <code>{SS}</code>, <code>{state}</code>, <code>{zip}</code>.
                        </span>
                    </div>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="keep_original" value="1" checked>
                            Keep the original page in Landing Pages after promoting
                        </label>
                        <span class="hint">Leave checked to be safe. Uncheck only after confirming the template-generated version is working.</span>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="btn">Promote to Template</button>
                        <a href="?tab=pages" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="card" style="background:#f8fafc;border:1px solid #e5e7eb;">
                <h2 style="font-size:.95rem;color:#6b7280;">What happens next?</h2>
                <ol style="margin:0;padding-left:20px;color:#374151;font-size:.9rem;line-height:1.7;">
                    <li>A new template is created in the <strong>Templates</strong> tab with all blocks and SEO from this page.</li>
                    <li>Set the template's <strong>Generation Steps</strong> (defaults to <code>city_vars</code> — enough for shortcode-based pages).</li>
                    <li>Go to <strong>City Pages</strong> and generate pages for all cities at once.</li>
                    <li>Once generated pages are working, come back here and delete the original landing page (or leave it — it won't conflict).</li>
                </ol>
            </div>

        <?php else: ?>

            <p style="margin-bottom:16px;"><a href="?tab=pages">&larr; Back to all pages</a></p>

            <form action="save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="page_id" value="<?= h($editingPageId) ?>">
                <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Page</button>
                    <a href="../page.php?slug=<?= h($editingPage['slug'] ?? '') ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
                    <?php if (($editingPage['page_type'] ?? '') === 'landing'): ?>
                    <a href="?tab=pages&page=<?= h($editingPageId) ?>&action=promote" class="btn btn-secondary">&rarr; Promote to Template</a>
                    <?php endif; ?>
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
