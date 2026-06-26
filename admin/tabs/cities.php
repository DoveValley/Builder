<?php
// Cities tab — list, add, edit, CSV import
// $tab, $cities, $editingCity, $editingCityId set by index.php

function _render_city_fields(array $city = [], string $prefix = '', string $cityId = ''): void {
    $v = fn(string $k) => h($city[$k] ?? '');
?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
        <div class="form-group">
            <label>City <span class="cf-req">*</span></label>
            <input type="text" name="<?= $prefix ?>city" value="<?= $v('city') ?>" placeholder="San Antonio" required>
        </div>
        <div class="form-group">
            <label>State</label>
            <input type="text" name="<?= $prefix ?>state" value="<?= $v('state') ?>" placeholder="Texas">
        </div>
        <div class="form-group">
            <label>State abbreviation (SS) <span class="cf-req">*</span></label>
            <input type="text" name="<?= $prefix ?>SS" value="<?= $v('SS') ?>" placeholder="TX" maxlength="3" required>
        </div>
        <div class="form-group">
            <label>City slug</label>
            <input type="text" name="<?= $prefix ?>city_slug" value="<?= $v('city_slug') ?>" placeholder="san-antonio-tx">
            <span class="hint">Used in generated page URLs via <code>{city_slug}</code>. Auto-generated if blank.</span>
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="<?= $prefix ?>phone" value="<?= $v('phone') ?>" placeholder="(210) 555-0100">
        </div>
        <div class="form-group">
            <label>Tel (E.164 for href)</label>
            <input type="text" name="<?= $prefix ?>tel" value="<?= $v('tel') ?>" placeholder="+12105550100">
        </div>
        <div class="form-group">
            <label>ZIP code</label>
            <input type="text" name="<?= $prefix ?>zip" value="<?= $v('zip') ?>" placeholder="78201">
        </div>
        <div class="form-group">
            <label>Website</label>
            <input type="text" name="<?= $prefix ?>website" value="<?= $v('website') ?>" placeholder="https://example.com">
        </div>
    </div>
    <div class="form-group">
        <label>Street address</label>
        <input type="text" name="<?= $prefix ?>address" value="<?= $v('address') ?>" placeholder="100 W Houston St Suite 200">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
        <div class="form-group">
            <label>Latitude</label>
            <input type="text" name="<?= $prefix ?>lat" value="<?= $v('lat') ?>" placeholder="29.4241">
        </div>
        <div class="form-group">
            <label>Longitude</label>
            <input type="text" name="<?= $prefix ?>lng" value="<?= $v('lng') ?>" placeholder="-98.4936">
        </div>
    </div>
    <div class="form-group">
        <label>Tags <span style="font-weight:400;color:#888;">(comma or space separated)</span></label>
        <input type="text" name="<?= $prefix ?>tags" value="<?= h(implode(', ', $city['tags'] ?? [])) ?>" placeholder="texas, client-abc, priority">
        <span class="hint">Tags let you generate pages for a subset of cities — e.g. generate only the "texas" tag.</span>
    </div>

    <div style="display:flex;align-items:center;gap:12px;border-top:1px solid #e5e7eb;padding-top:16px;margin-top:20px;margin-bottom:4px;flex-wrap:wrap;">
        <h3 style="margin:0;font-size:.9rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">AI Research Context</h3>
        <?php if ($cityId): ?>
        <button type="button" class="ai-run-btn" id="city-research-btn"
                style="font-size:.75rem;padding:5px 12px;"
                onclick="cityResearch(<?= h(json_encode($cityId)) ?>)">&#128269; Research with AI</button>
        <div class="ai-spinner" id="city-research-spinner"></div>
        <span id="city-research-status" style="font-size:.78rem;color:#6b7280;"></span>
        <?php endif; ?>
    </div>
    <p class="hint" style="margin-bottom:12px;">Used by <code>generate.py</code> to write city-specific content. One item per line.</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
        <div class="form-group">
            <label>Key industries</label>
            <textarea name="<?= $prefix ?>industries" rows="4" placeholder="technology&#10;healthcare&#10;finance"><?= h(implode("\n", $city['industries'] ?? [])) ?></textarea>
            <span class="hint">One industry per line — e.g. "defense/military", "technology"</span>
        </div>
        <div class="form-group">
            <label>Top employers</label>
            <textarea name="<?= $prefix ?>top_employers" rows="4" placeholder="USAA&#10;H-E-B&#10;Baptist Health System"><?= h(implode("\n", $city['top_employers'] ?? [])) ?></textarea>
            <span class="hint">One employer per line. Used verbatim in AI prompts.</span>
        </div>
    </div>
    <div class="form-group">
        <label>Salary note</label>
        <input type="text" name="<?= $prefix ?>salary_note" value="<?= h($city['salary_note'] ?? '') ?>" placeholder="PMP-certified PMs in [City] average $110,000–$130,000 annually">
        <span class="hint">One sentence. Used in city market intro and FAQ blocks.</span>
    </div>
    <div class="form-group">
        <label>Market blurb</label>
        <textarea name="<?= $prefix ?>market_blurb" rows="3" placeholder="[City] is home to..."><?= h($city['market_blurb'] ?? '') ?></textarea>
        <span class="hint">1–3 sentences on why the PM certification market is strong here. Referenced directly in AI prompts.</span>
    </div>
<?php
}
?>

<div class="tab-content" style="<?= $tab === 'cities' ? '' : 'display:none;' ?>">

<?php if ($editingCity === null): ?>

    <!-- ── List view ─────────────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        <!-- Add city form -->
        <div class="card">
            <h2>Add a City</h2>
            <form action="cities_save.php" method="post">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <?php _render_city_fields(); ?>
                <button type="submit" class="btn">Add City</button>
            </form>
        </div>

        <!-- CSV import -->
        <div class="card">
            <h2>Import from CSV</h2>
            <p class="hint" style="margin-bottom:12px;">
                Upload a CSV with a header row. Required columns: <code>city</code>, <code>SS</code>.
                Optional: <code>state</code>, <code>city_slug</code>, <code>phone</code>, <code>tel</code>,
                <code>zip</code>, <code>address</code>, <code>lat</code>, <code>lng</code>, <code>website</code>, <code>tags</code>.
            </p>
            <p class="hint" style="margin-bottom:16px;">
                Tags column: separate multiple tags with <code>|</code> (pipe) — e.g. <code>texas|priority</code>.
            </p>
            <form action="cities_save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <div class="form-group">
                    <label>CSV file</label>
                    <input type="file" name="csv_file" accept=".csv,.txt" required>
                </div>
                <button type="submit" class="btn">Import Cities</button>
            </form>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;">
                <p class="hint" style="margin-bottom:8px;"><strong>CSV template:</strong></p>
                <pre style="font-size:11px;background:#f8f9fa;padding:10px;border-radius:4px;overflow-x:auto;line-height:1.5;">city,state,SS,city_slug,phone,tel,zip,address,lat,lng,website,tags
Dallas,Texas,TX,dallas-tx,(214) 555-0100,+12145550100,75201,,32.7767,-96.7970,,texas
Austin,Texas,TX,austin-tx,(512) 555-0100,+15125550100,78701,,30.2672,-97.7431,,texas|priority</pre>
            </div>
        </div>

    </div>

    <!-- City list -->
    <div class="card">
        <h2>Cities <span style="font-weight:400;font-size:0.85em;color:#888;">(<?= count($cities) ?>)</span></h2>
        <?php if (empty($cities)): ?>
            <p class="hint">No cities yet. Add one above or import from CSV.</p>
        <?php else: ?>

            <!-- Tag filter -->
            <?php
            $allTags = [];
            foreach ($cities as $c) { foreach ($c['tags'] ?? [] as $t) { $allTags[$t] = true; } }
            ksort($allTags);
            $filterTag = trim($_GET['filter_tag'] ?? '');
            $filteredCities = $filterTag !== ''
                ? array_filter($cities, fn($c) => in_array($filterTag, $c['tags'] ?? [], true))
                : $cities;
            ?>
            <?php if (!empty($allTags)): ?>
            <div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <span class="hint" style="margin:0;">Filter by tag:</span>
                <a href="?tab=cities" class="btn btn-small <?= $filterTag === '' ? 'btn-secondary' : '' ?>" style="padding:3px 10px;font-size:12px;">All</a>
                <?php foreach ($allTags as $tag => $_): ?>
                <a href="?tab=cities&filter_tag=<?= h(urlencode($tag)) ?>"
                   class="btn btn-small <?= $filterTag === $tag ? '' : 'btn-secondary' ?>"
                   style="padding:3px 10px;font-size:12px;"><?= h($tag) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="repeat-items">
            <?php foreach ($filteredCities as $city): ?>
                <div class="repeat-row" style="align-items:center;">
                    <div style="flex:1;">
                        <strong><?= h($city['city']) ?>, <?= h($city['SS']) ?></strong>
                        <?php if (!empty($city['tags'])): ?>
                            <?php foreach ($city['tags'] as $tag): ?>
                            <span style="display:inline-block;background:#e5e7eb;color:#374151;font-size:11px;padding:1px 7px;border-radius:10px;margin-left:4px;"><?= h($tag) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($city['industries']) || !empty($city['top_employers'])): ?>
                            <span style="display:inline-block;background:#d1fae5;color:#065f46;font-size:11px;padding:1px 7px;border-radius:10px;margin-left:4px;">Researched</span>
                        <?php else: ?>
                            <span style="display:inline-block;background:#fef3c7;color:#92400e;font-size:11px;padding:1px 7px;border-radius:10px;margin-left:4px;">No research</span>
                        <?php endif; ?>
                        <br>
                        <span class="hint">
                            <?= h($city['city_slug'] ?? '') ?>
                            <?php if (!empty($city['phone'])): ?> &mdash; <?= h($city['phone']) ?><?php endif; ?>
                            <?php if (!empty($city['zip'])): ?> &mdash; <?= h($city['zip']) ?><?php endif; ?>
                        </span>
                    </div>
                    <a class="btn btn-secondary btn-small" href="?tab=cities&city=<?= h($city['id']) ?>">Edit</a>
                    <form action="cities_save.php" method="post" style="display:inline;"
                          onsubmit="return confirm('Delete <?= h(addslashes($city['city'])) ?>, <?= h(addslashes($city['SS'])) ?>? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="city_id" value="<?= h($city['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <button type="submit" class="remove-row" title="Delete city">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>

            <?php if ($filterTag !== '' && count($filteredCities) !== count($cities)): ?>
            <p class="hint" style="margin-top:12px;">Showing <?= count($filteredCities) ?> of <?= count($cities) ?> cities.</p>
            <?php endif; ?>

        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- ── Edit view ─────────────────────────────────────────────────── -->
    <p style="margin-bottom:16px;">
        <a href="?tab=cities">&larr; Back to all cities</a>
    </p>

    <div class="card">
        <h2>Edit City: <?= h($editingCity['city']) ?>, <?= h($editingCity['SS']) ?></h2>
        <form action="cities_save.php" method="post">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="city_id" value="<?= h($editingCityId) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <?php _render_city_fields($editingCity, '', $editingCityId); ?>
            <button type="submit" class="btn">Save City</button>
        </form>
    </div>

    <form action="cities_save.php" method="post" style="margin-top:12px;"
          onsubmit="return confirm('Delete <?= h(addslashes($editingCity['city'])) ?>, <?= h(addslashes($editingCity['SS'])) ?>? This cannot be undone.');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="city_id" value="<?= h($editingCityId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">Delete This City</button>
    </form>

<?php endif; ?>
</div>

<?php if ($tab === 'cities' && $editingCityId): ?>
<script>
window.cityResearch = function (cityId) {
    var btn     = document.getElementById('city-research-btn');
    var spinner = document.getElementById('city-research-spinner');
    var status  = document.getElementById('city-research-status');
    if (!btn) return;

    btn.disabled = true;
    spinner.classList.add('on');
    status.textContent = 'Researching…';

    var startedAt = Date.now();
    var ticker = setInterval(function () {
        status.textContent = 'Researching… ' + ((Date.now() - startedAt) / 1000).toFixed(0) + 's';
    }, 1000);

    var fd = new FormData();
    fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
    fd.append('action', 'research');
    fd.append('city_id', cityId);

    fetch('ai_generate.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        clearInterval(ticker);
        btn.disabled = false;
        spinner.classList.remove('on');
        if (res.success) {
            status.textContent = 'Done — reloading…';
            setTimeout(function () { location.reload(); }, 1200);
        } else {
            status.textContent = res.error || 'Research failed.';
            status.style.color = '#dc2626';
        }
    })
    .catch(function (err) {
        clearInterval(ticker);
        btn.disabled = false;
        spinner.classList.remove('on');
        status.textContent = 'Request failed: ' + err.message;
        status.style.color = '#dc2626';
    });
};
</script>
<?php endif; ?>
