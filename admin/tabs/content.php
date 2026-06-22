    <div class="tab-content" style="<?= $tab === 'content' ? '' : 'display:none;' ?>">

        <?php if ($tab === 'content'):
            $hpStarters = array_values(array_filter(starters_load(), fn($s) => ($s['category'] ?? '') === 'homepage'));
        ?>
        <!-- ── Homepage starter loader ─────────────────────────────────── -->
        <div id="hp-starter-wrap" style="margin-bottom:12px;">
            <button type="button" class="btn btn-secondary"
                    onclick="document.getElementById('hp-starter-panel').style.display = document.getElementById('hp-starter-panel').style.display === 'none' ? '' : 'none'">
                &#9881; Load Homepage Starter
            </button>
        </div>

        <div id="hp-starter-panel" style="display:none;margin-bottom:20px;">
            <div class="card" style="border:2px solid #4f46e5;padding:20px;">
                <h3 style="margin:0 0 6px;font-size:1rem;">Load a Homepage Starter</h3>
                <p style="margin:0 0 14px;font-size:.85rem;color:#dc2626;font-weight:600;">
                    &#9888; This replaces ALL current homepage blocks. Save your work first if needed.
                </p>
                <form action="save.php" method="post"
                      onsubmit="return confirm('Replace all homepage blocks with the selected starter?\n\nThis cannot be undone — make sure you have saved any work you want to keep.')">
                    <input type="hidden" name="section" value="homepage_load_starter">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="starter_id" id="hp_selected_starter" value="">

                    <div class="starter-picker" style="margin-bottom:14px;">
                        <?php foreach ($hpStarters as $s): ?>
                        <div class="starter-card" data-starter-id="<?= h($s['id']) ?>"
                             onclick="hpSelectStarter(this)">
                            <div class="starter-card-label"><?= h($s['label']) ?></div>
                            <div class="starter-card-desc"><?= h($s['desc']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="submit" class="btn" id="hp-load-btn" disabled>Load Selected Starter</button>
                        <button type="button" class="btn btn-secondary"
                                onclick="document.getElementById('hp-starter-panel').style.display='none'">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function hpSelectStarter(card) {
            document.querySelectorAll('#hp-starter-panel .starter-card').forEach(c => c.classList.remove('is-selected'));
            card.classList.add('is-selected');
            document.getElementById('hp_selected_starter').value = card.dataset.starterId;
            document.getElementById('hp-load-btn').disabled = false;
        }
        </script>
        <?php endif; ?>

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
