    <div class="tab-content" style="<?= $tab === 'blog' ? '' : 'display:none;' ?>">
        <?php if ($editingPost === null): ?>

            <div class="card">
                <h2>Blog Settings</h2>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="blog_settings">
                    <div class="form-group">
                        <label for="blog_heading">Blog page heading</label>
                        <input type="text" id="blog_heading" name="blog_heading" value="<?= h($blogSettings['blog_heading']) ?>">
                        <span class="hint">Shown at the top of /blog. Shortcodes like {city} are supported.</span>
                    </div>
                    <div class="form-group">
                        <label for="blog_intro">Blog intro text</label>
                        <textarea id="blog_intro" name="blog_intro" rows="2"><?= h($blogSettings['blog_intro']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="posts_per_page">Posts per page</label>
                        <input type="number" id="posts_per_page" name="posts_per_page" min="1" max="50" value="<?= h($blogSettings['posts_per_page']) ?>">
                    </div>
                    <button type="submit" class="btn">Save Blog Settings</button>
                </form>
            </div>

            <div class="card">
                <h2>Add a New Post</h2>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="post_add">
                    <div class="form-group">
                        <label for="new_post_title">Post title</label>
                        <input type="text" id="new_post_title" name="title" placeholder="e.g. 5 Signs You Have a Termite Problem" required>
                    </div>
                    <div class="form-group">
                        <label for="new_post_slug">URL slug (optional)</label>
                        <input type="text" id="new_post_slug" name="slug" placeholder="e.g. signs-of-termites">
                        <span class="hint">Leave blank to generate one automatically from the title.</span>
                    </div>
                    <button type="submit" class="btn">Add Post</button>
                </form>
            </div>

            <div class="card">
                <h2>Posts</h2>
                <?php
                if (empty($posts)) {
                    echo '<p class="hint">None yet.</p>';
                } else {
                    $sortedPosts = $posts;
                    uasort($sortedPosts, fn($a, $b) => strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));
                    echo '<div class="repeat-items">';
                    foreach ($sortedPosts as $pid => $p) {
                        echo '<div class="repeat-row" style="align-items:center;">';
                        echo '<div style="flex:1;"><strong>' . h($p['title'] !== '' ? $p['title'] : '(untitled)') . '</strong> ';
                        echo '<span class="hint">(' . h($p['status']) . ')</span><br>';
                        echo '<span class="hint">/blog/' . h($p['slug']) . ' &mdash; ' . h($p['published_at']) . ' &mdash; <a href="../blog.php?slug=' . h($p['slug']) . '" target="_blank" rel="noopener">preview</a></span></div>';
                        echo '<a class="btn btn-secondary btn-small" href="?tab=blog&post=' . h($pid) . '">Edit</a>';
                        echo '<form action="save.php" method="post" style="display:inline;" onsubmit="return confirm(\'Delete this post? This cannot be undone.\');">';
                        echo '<input type="hidden" name="section" value="post_delete">';
                        echo '<input type="hidden" name="post_id" value="' . h($pid) . '">';
                        echo '<button type="submit" class="remove-row" title="Delete post">&times;</button>';
                        echo '</form></div>';
                    }
                    echo '</div>';
                }
                ?>
            </div>

        <?php else: ?>

            <p style="margin-bottom:16px;"><a href="?tab=blog">&larr; Back to all posts</a></p>

            <form action="save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="post_id" value="<?= h($editingPostId) ?>">
                <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Post</button>
                    <a href="../blog.php?slug=<?= h($editingPost['slug'] ?? '') ?>" target="_blank" class="btn btn-secondary">Preview Post &rarr;</a>
                </div>

                <div class="card">
                    <h2>Post Settings</h2>
                    <div class="form-group">
                        <label for="post_title">Post title</label>
                        <input type="text" id="post_title" name="post_title" value="<?= h($editingPost['title']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_slug">URL slug</label>
                        <input type="text" id="post_slug" name="post_slug" value="<?= h($editingPost['slug']) ?>">
                        <span class="hint">This post is available at <code>/blog/<?= h($editingPost['slug']) ?></code>.</span>
                    </div>
                    <div class="form-group">
                        <label for="post_status">Status</label>
                        <select id="post_status" name="post_status">
                            <option value="draft" <?= $editingPost['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $editingPost['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="post_published_at">Published date</label>
                        <input type="date" id="post_published_at" name="post_published_at" value="<?= h($editingPost['published_at']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_author">Author</label>
                        <input type="text" id="post_author" name="post_author" value="<?= h($editingPost['author']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_tag">Tag</label>
                        <input type="text" id="post_tag" name="post_tag" value="<?= h($editingPost['tag']) ?>" placeholder="e.g. Termites">
                        <span class="hint">Readers can click this tag to filter the blog list to posts with the same tag.</span>
                    </div>
                    <div class="form-group">
                        <label for="post_excerpt">Excerpt</label>
                        <textarea id="post_excerpt" name="post_excerpt" rows="2"><?= h($editingPost['excerpt']) ?></textarea>
                        <span class="hint">Shown on the blog list card. Falls back to meta description if blank.</span>
                    </div>
                    <div class="form-group">
                        <label for="post_featured_image">Featured image</label>
                        <?php if (!empty($editingPost['featured_image'])): ?>
                            <p><img src="../<?= h($editingPost['featured_image']) ?>" style="max-width:200px;border-radius:8px;" alt=""></p>
                        <?php endif; ?>
                        <input type="file" id="post_featured_image" name="post_featured_image" accept="image/*">
                        <input type="hidden" name="post_featured_image_existing" value="<?= h($editingPost['featured_image']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_featured_image_alt">Featured image alt text</label>
                        <input type="text" id="post_featured_image_alt" name="post_featured_image_alt" value="<?= h($editingPost['featured_image_alt']) ?>">
                    </div>
                </div>

                <?php render_content_blocks_editor($editingPost['content_blocks']); ?>

                <?php render_seo_editor($editingPost['seo']); ?>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Post</button>
                    <a href="../blog.php?slug=<?= h($editingPost['slug']) ?>" target="_blank" class="btn btn-secondary">Preview Post &rarr;</a>
                </div>
            </form>

            <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Delete this post? This cannot be undone.');">
                <input type="hidden" name="section" value="post_delete">
                <input type="hidden" name="post_id" value="<?= h($editingPostId) ?>">
                <button type="submit" class="btn btn-danger">Delete This Post</button>
            </form>

        <?php endif; ?>
    </div>
