<?php
// City Image plugin admin panel.
// All index.php scope variables ($data, $csrfToken, etc.) are available.

$v      = $data['site_vars'] ?? [];
$city   = $v['city'] ?? '';
$ss     = $v['SS'] ?? '';
$path   = $v['city_image'] ?? '';
$alt    = $v['city_image_alt'] ?? '';
$credit = $v['city_image_credit'] ?? '';
$source = $v['city_image_source'] ?? '';
$imgUrl = $path !== '' ? (str_starts_with($path, 'http') ? $path : '/' . ltrim($path, '/')) : '';
?>

<div class="admin-section">

    <div class="card" style="margin-bottom:16px;">
        <p style="margin:0 0 10px;color:#dc2626;font-weight:700;">
            ** IMPORTANT ** You usually don't need this panel. The generator fetches each city's
            image automatically during content generation (once per city, then cached). Use the
            button below only to override — re-fetch or replace the current image.
        </p>
        <p class="hint">
            Fetches a photo of <strong><?= h($city !== '' ? "$city, $ss" : 'your city') ?></strong> from the
            <strong>Wikipedia API</strong> (the article's lead image — always geo-correct and freely licensed),
            self-hosts it, and exposes it as:
        </p>
        <ul class="hint" style="margin:8px 0 0 18px;line-height:1.7;">
            <li><code>{city_image}</code> — image path (drop into any photo field, e.g. the Map/Info block)</li>
            <li><code>{city_image_alt}</code> — SEO alt text &nbsp;·&nbsp; <code>{city_image_credit}</code> — CC credit</li>
        </ul>
    </div>

    <!-- Current image -->
    <div class="card" style="margin-bottom:16px;">
        <h2>Current city image</h2>
        <?php if ($imgUrl !== ''): ?>
            <img src="<?= h($imgUrl) ?>" alt="<?= h($alt) ?>" style="max-width:420px;width:100%;border-radius:10px;display:block;margin:10px 0;">
            <p class="hint" style="margin:4px 0;"><strong>Alt:</strong> <?= h($alt) ?: '<em>none</em>' ?></p>
            <p class="hint" style="margin:4px 0;"><strong>Credit:</strong> <?= h($credit) ?: '<em>none</em>' ?></p>
            <?php if ($source): ?>
                <p class="hint" style="margin:4px 0;"><a href="<?= h($source) ?>" target="_blank" rel="noopener">Source on Wikimedia Commons &nearr;</a></p>
            <?php endif; ?>
        <?php else: ?>
            <p class="hint">No city image yet. Click <strong>Fetch from Wikipedia</strong> below.</p>
        <?php endif; ?>
    </div>

    <!-- Fetch / clear actions -->
    <div class="card" style="margin-bottom:16px;">
        <h2>Fetch</h2>
        <p class="hint" style="margin-bottom:12px;">Pulls the Wikipedia lead image for <strong><?= h($city !== '' ? "$city, $ss" : 'the site city') ?></strong>. Re-fetching overwrites the current image.</p>
        <div style="display:flex;gap:10px;align-items:center;">
            <form method="post" action="plugin_save.php" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="plugin_id"  value="city_image">
                <input type="hidden" name="action"     value="fetch">
                <button type="submit" class="btn">&#127961;&#65039; Fetch from Wikipedia</button>
            </form>
            <?php if ($imgUrl !== ''): ?>
            <form method="post" action="plugin_save.php" style="margin:0;" onsubmit="return confirm('Clear the city image?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="plugin_id"  value="city_image">
                <input type="hidden" name="action"     value="clear">
                <button type="submit" class="btn btn-secondary">Clear</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Manual SEO alt / credit override -->
    <?php if ($imgUrl !== ''): ?>
    <div class="card">
        <h2>SEO alt &amp; credit</h2>
        <form method="post" action="plugin_save.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="plugin_id"  value="city_image">
            <input type="hidden" name="action"     value="save">
            <div class="form-group">
                <label>Alt text (SEO)</label>
                <input type="text" name="city_image_alt" value="<?= h($alt) ?>" placeholder="e.g. Downtown <?= h($city) ?>, <?= h($ss) ?>">
            </div>
            <div class="form-group">
                <label>Photo credit</label>
                <input type="text" name="city_image_credit" value="<?= h($credit) ?>" placeholder="Photo: Author, CC BY-SA">
            </div>
            <button type="submit" class="btn">Save alt &amp; credit</button>
        </form>
    </div>
    <?php endif; ?>

</div>
