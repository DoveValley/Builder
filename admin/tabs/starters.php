<?php
// Page Starters tab — list view + edit view
$allBlockTypes = [];
foreach (grouped_block_types() as $gLabel => $gItems) {
    foreach ($gItems as $k => $v) $allBlockTypes[$k] = "$gLabel › $v";
}
$cats = starter_categories();
?>
<div class="tab-content" style="<?= $tab === 'starters' ? '' : 'display:none;' ?>">

<?php if ($editingStarter === null): ?>

    <!-- ── List view ─────────────────────────────────────────────────────── -->
    <div class="card">
        <h2>Add a New Page Starter</h2>
        <p class="hint" style="margin-bottom:18px;">
            Starters are block-sequence skeletons that pre-populate a new page when you create it.
            They are global — shared across all sites.
        </p>
        <form action="starters_save.php" method="post">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="flex:1 1 160px;margin:0;">
                    <label>Starter name</label>
                    <input type="text" name="label" placeholder="e.g. Service page" required>
                </div>
                <div class="form-group" style="flex:1 1 160px;margin:0;">
                    <label>Category</label>
                    <select name="category">
                        <?php foreach ($cats as $k => $v): ?>
                        <option value="<?= h($k) ?>"><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:2 1 220px;margin:0;">
                    <label>Short description <span style="font-weight:400;color:#888;">(optional)</span></label>
                    <input type="text" name="desc" placeholder="e.g. Hero · Features · Pricing · CTA">
                </div>
                <button type="submit" class="btn" style="align-self:flex-end;">Add Starter</button>
            </div>
        </form>
    </div>

    <?php
    $starters   = starters_load();
    $activeCat  = $_GET['cat'] ?? 'training';
    if (!array_key_exists($activeCat, $cats)) $activeCat = array_key_first($cats);
    $byCat = [];
    foreach ($starters as $s) {
        $c = $s['category'] ?? 'universal';
        $byCat[$c][] = $s;
    }
    ?>

    <div class="card">
        <h2>Page Starters</h2>

        <!-- Category tab strip -->
        <div class="inner-tabs" style="margin-bottom:18px;">
            <?php foreach ($cats as $k => $v): ?>
            <a class="inner-tab <?= $k === $activeCat ? 'active' : '' ?>"
               href="?tab=starters&cat=<?= h($k) ?>"><?= h($v) ?>
               <span style="font-weight:400;color:<?= $k === $activeCat ? 'rgba(255,255,255,.7)' : '#9ca3af' ?>;font-size:.75rem;margin-left:4px;">(<?= count($byCat[$k] ?? []) ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php $list = $byCat[$activeCat] ?? []; ?>
        <?php if (empty($list)): ?>
            <p class="hint">No starters in this category yet. Add one above.</p>
        <?php else: ?>
            <div class="repeat-items">
            <?php foreach ($list as $s): ?>
                <div class="repeat-row" style="align-items:center;">
                    <div style="flex:1;">
                        <strong><?= h($s['label']) ?></strong>
                        <?php if (!empty($s['desc'])): ?>
                            <span class="hint" style="margin-left:6px;"><?= h($s['desc']) ?></span>
                        <?php endif; ?>
                        <br>
                        <span class="hint">
                            <?php if (empty($s['blocks'])): ?>
                                <em>No blocks</em>
                            <?php else: ?>
                                <?php foreach ($s['blocks'] as $bKey): ?>
                                    <span style="display:inline-block;background:#e0e7ff;color:#3730a3;border-radius:4px;padding:1px 7px;font-size:.72rem;margin:2px 2px 2px 0;"><?= h($allBlockTypes[$bKey] ?? $bKey) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <a class="btn btn-secondary btn-small" href="?tab=starters&starter=<?= h($s['id']) ?>&cat=<?= h($activeCat) ?>">Edit</a>

                    <form action="starters_save.php" method="post" style="display:inline;">
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="starter_id" value="<?= h($s['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="return_cat" value="<?= h($activeCat) ?>">
                        <button type="submit" class="btn btn-secondary btn-small">Duplicate</button>
                    </form>

                    <form action="starters_save.php" method="post" style="display:inline;"
                          onsubmit="return confirm('Delete starter \'<?= h(addslashes($s['label'])) ?>\'?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="starter_id" value="<?= h($s['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="return_cat" value="<?= h($activeCat) ?>">
                        <button type="submit" class="remove-row" title="Delete">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- ── Edit view ─────────────────────────────────────────────────────── -->
    <?php $returnCat = $_GET['cat'] ?? 'training'; ?>
    <p style="margin-bottom:16px;"><a href="?tab=starters&cat=<?= h($returnCat) ?>">&larr; Back to starters</a></p>

    <form action="starters_save.php" method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="starter_id" value="<?= h($editingStarterId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="return_cat" value="<?= h($returnCat) ?>">

        <div style="display:flex;gap:12px;margin-bottom:16px;">
            <button type="submit" class="btn">Save Starter</button>
            <a href="?tab=starters&cat=<?= h($returnCat) ?>" class="btn btn-secondary">Cancel</a>
        </div>

        <div class="card">
            <h2>Starter Settings</h2>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:2 1 200px;">
                    <label>Name</label>
                    <input type="text" name="label" value="<?= h($editingStarter['label'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="flex:1 1 160px;">
                    <label>Category</label>
                    <select name="category">
                        <?php foreach ($cats as $k => $v): ?>
                        <option value="<?= h($k) ?>"<?= ($editingStarter['category'] ?? '') === $k ? ' selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description <span style="font-weight:400;color:#888;">(shown in the Add Page picker)</span></label>
                <input type="text" name="desc" value="<?= h($editingStarter['desc'] ?? '') ?>" placeholder="e.g. Hero · Features · Pricing · CTA">
            </div>
        </div>

        <div class="card">
            <h2>Block sequence</h2>
            <p class="hint" style="margin-bottom:14px;">Each row is one block, top to bottom. Add, remove, or reorder them.</p>

            <div id="starter-block-list">
                <?php foreach ($editingStarter['blocks'] ?? [] as $bKey): ?>
                <div class="repeat-row starter-block-row" style="align-items:center;gap:8px;">
                    <select name="starter_blocks[]" class="form-control" style="flex:1;">
                        <?php foreach (grouped_block_types() as $gLabel => $gItems): ?>
                        <optgroup label="<?= h($gLabel) ?>">
                            <?php foreach ($gItems as $k => $v): ?>
                            <option value="<?= h($k) ?>"<?= $k === $bKey ? ' selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="icon-btn" onclick="moveStarterRow(this,-1)" title="Move up">&uarr;</button>
                    <button type="button" class="icon-btn" onclick="moveStarterRow(this,1)"  title="Move down">&darr;</button>
                    <button type="button" class="icon-btn remove-row" onclick="this.closest('.starter-block-row').remove()" title="Remove">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-secondary btn-small" style="margin-top:10px;" onclick="addStarterRow()">+ Add block</button>
        </div>

        <div style="display:flex;gap:12px;margin-top:4px;">
            <button type="submit" class="btn">Save Starter</button>
            <a href="?tab=starters&cat=<?= h($returnCat) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

    <form action="starters_save.php" method="post" style="margin-top:12px;"
          onsubmit="return confirm('Delete this starter?');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="starter_id" value="<?= h($editingStarterId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="return_cat" value="<?= h($returnCat) ?>">
        <button type="submit" class="btn btn-danger">Delete This Starter</button>
    </form>

    <script>
    const _starterBlockOptions = `<?php
        $opts = '';
        foreach (grouped_block_types() as $gLabel => $gItems) {
            $opts .= '<optgroup label="' . h($gLabel) . '">';
            foreach ($gItems as $k => $v) $opts .= '<option value="' . h($k) . '">' . h($v) . '</option>';
            $opts .= '</optgroup>';
        }
        echo addslashes($opts);
    ?>`;

    function addStarterRow() {
        const list = document.getElementById('starter-block-list');
        const row  = document.createElement('div');
        row.className = 'repeat-row starter-block-row';
        row.style.cssText = 'align-items:center;gap:8px;';
        row.innerHTML = `<select name="starter_blocks[]" class="form-control" style="flex:1;">${_starterBlockOptions}</select>
            <button type="button" class="icon-btn" onclick="moveStarterRow(this,-1)" title="Move up">&uarr;</button>
            <button type="button" class="icon-btn" onclick="moveStarterRow(this,1)"  title="Move down">&darr;</button>
            <button type="button" class="icon-btn remove-row" onclick="this.closest('.starter-block-row').remove()" title="Remove">&times;</button>`;
        list.appendChild(row);
    }

    function moveStarterRow(btn, dir) {
        const row  = btn.closest('.starter-block-row');
        const list = row.parentElement;
        if (dir === -1 && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        } else if (dir === 1 && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }
    }
    </script>

<?php endif; ?>
</div>
