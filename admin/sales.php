<?php
/**
 * admin/sales.php — the Sales prospecting tool. One page, three stacked
 * sections: a New Search form, an active-run progress banner, and the saved
 * leads table. See includes/sales_prospecting.php for the module this page
 * is a thin view over, and /root/.claude/plans/adaptive-shimmying-lark.md
 * for the full design.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sales_prospecting.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: pluscities_clients.php?msg=error:Sales+prospecting+is+managed+by+the+platform+only');
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

if (!empty($_GET['refresh_categories'])) {
    sales_categories_refresh();
    header('Location: sales.php');
    exit;
}

$categories = sales_categories_cached();
$states = sales_us_states();
$activeRunId = sales_active_run_id(); // a search started in another tab/session, or before a refresh, is still live — resume showing it

$filters = [
    'niche'        => trim((string) ($_GET['niche'] ?? '')),
    'state'        => trim((string) ($_GET['state'] ?? '')),
    'status'       => trim((string) ($_GET['status'] ?? '')),
    'is_wordpress' => trim((string) ($_GET['is_wordpress'] ?? '')),
    'sort'         => trim((string) ($_GET['sort'] ?? 'found_at')),
    'dir'          => trim((string) ($_GET['dir'] ?? 'desc')),
];
$rows = sales_list_prospects(array_filter($filters, fn($v) => $v !== ''));

$knownNiches = sales_db()->query('SELECT DISTINCT niche FROM sales_prospects ORDER BY niche')->fetchAll(PDO::FETCH_COLUMN);
$knownStates = sales_db()->query('SELECT DISTINCT state FROM sales_prospects ORDER BY state')->fetchAll(PDO::FETCH_COLUMN);

$statusLabels = [
    'new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified',
    'not_interested' => 'Not interested', 'won' => 'Won', 'lost' => 'Lost',
];

function sales_sort_link(string $col, string $label, array $filters): string
{
    $dir = ($filters['sort'] === $col && $filters['dir'] === 'desc') ? 'asc' : 'desc';
    $q = $filters;
    $q['sort'] = $col; $q['dir'] = $dir;
    $arrow = $filters['sort'] === $col ? ($filters['dir'] === 'desc' ? ' &darr;' : ' &uarr;') : '';
    return '<a href="sales.php?' . h(http_build_query(array_filter($q, fn($v) => $v !== ''))) . '">' . h($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php lpc_admin_open('sales', 'Sales'); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.sales-suggestions{position:absolute;left:0;right:0;z-index:20;border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;margin-top:4px;max-height:240px;overflow-y:auto;background:#fff;box-shadow:0 4px 14px rgba(0,0,0,.12);}
.sales-suggestions div{padding:7px 10px;font-size:.82rem;cursor:pointer;border-bottom:1px solid #f1f5f9;}
.sales-suggestions div:last-child{border-bottom:none;}
.sales-suggestions div:hover{background:#f4f7fb;}
.sales-suggestion-history{color:var(--pc-muted,#52607a);font-size:.74rem;}
.sales-selected-cities{display:flex;flex-direction:column;gap:6px;margin-top:10px;}
.sales-city-row{display:flex;align-items:center;gap:12px;border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;padding:8px 10px;font-size:.84rem;}
.sales-city-row.overlap{border-color:#f59e0b;background:#fffbeb;}
.sales-city-row .sales-city-name{flex:1;}
.sales-city-row .sales-city-history,.sales-city-row .sales-city-overlap{color:var(--pc-muted,#52607a);font-size:.76rem;}
.sales-city-row .sales-city-overlap{color:#b45309;}
.sales-city-row label{font-size:.76rem;font-weight:400;color:var(--pc-muted,#52607a);display:flex;align-items:center;gap:4px;}
.sales-city-row input[type=number]{width:70px;}
.sales-city-remove{background:none;border:none;color:#991b1b;cursor:pointer;font-weight:700;font-size:1rem;line-height:1;padding:2px 6px;}
.sales-form-row{display:flex;gap:16px;flex-wrap:wrap;margin:10px 0;}
.sales-form-row > div{display:flex;flex-direction:column;gap:4px;}
.sales-form-row label{font-size:.8rem;font-weight:600;color:var(--pc-muted,#52607a);}
#sales-cost-estimate{font-size:.84rem;color:var(--pc-muted,#52607a);margin-top:8px;}
.pc-bulk-bar{height:10px;border-radius:5px;background:#f1f5f9;overflow:hidden;margin:10px 0;}
.pc-bulk-bar-fill{height:100%;background:var(--pc-lime,#c8f55a);transition:width .3s;}
table.sales-table{width:100%;border-collapse:collapse;font-size:.84rem;}
table.sales-table th,table.sales-table td{padding:7px 9px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top;}
table.sales-table th a{color:inherit;text-decoration:none;}
.sales-filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
.sales-notes-input{width:160px;font-size:.8rem;}
.sales-pill{font-size:.68rem;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:10px;}
.sales-pill-yes{background:#dcfce7;color:#166534;}
.sales-pill-no{background:#fee2e2;color:#991b1b;}
.sales-pill-unknown{background:#f1f5f9;color:#52607a;}
.sales-near-anchor{border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;padding:8px 12px;margin:10px 0;background:#fafbfc;}
.sales-near-anchor .sales-city-row{background:#fff;}
.sales-near-anchor .sales-city-row label.sales-city-pick{display:flex;align-items:center;gap:6px;font-weight:400;}
</style>

<div class="pc-card">
    <details <?= (empty($_GET['run_id']) && !$activeRunId) ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-weight:700;">New Search</summary>
        <form id="sales-search-form" style="margin-top:12px;">
            <div class="sales-form-row">
                <div style="flex:1;min-width:260px;">
                    <label for="sales-niche">Business category or keyword</label>
                    <input type="text" id="sales-niche" name="niche" list="sales-categories" autocomplete="off"
                        placeholder="e.g. roofing_contractor, or a phrase like foundation repair" required>
                    <datalist id="sales-categories">
                        <?php foreach ($categories as $slug): ?>
                            <option value="<?= h($slug) ?>" label="<?= h(sales_category_label($slug)) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <small style="color:var(--pc-muted,#52607a);">
                        If this matches one of <?= count($categories) ?> real DataForSEO categories, it searches by
                        category (<a href="sales.php?refresh_categories=1">refresh list</a>). If not — e.g. "foundation
                        repair," which has no matching category — it searches businesses whose own listing name
                        contains that phrase instead. Not every niche has a category; this covers those.
                    </small>
                </div>
            </div>
            <label style="font-size:.8rem;font-weight:600;color:var(--pc-muted,#52607a);">Cities to search</label>
            <div class="sales-form-row" style="margin:6px 0;">
                <div>
                    <label for="sales-state-select">State</label>
                    <select id="sales-state-select">
                        <option value="">Any state</option>
                        <?php foreach ($states as $ss => $name): ?>
                            <option value="<?= h($ss) ?>"><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="sales-quick-add-n">Add top N by population</label>
                    <div style="display:flex;gap:6px;">
                        <input type="number" id="sales-quick-add-n" value="5" min="1" max="50" style="width:70px;">
                        <button type="button" id="sales-quick-add-btn" class="btn-sm-outline">Add</button>
                    </div>
                </div>
                <div style="flex:1;min-width:220px;position:relative;">
                    <label for="sales-city-search">Search for a city</label>
                    <input type="text" id="sales-city-search" autocomplete="off" placeholder="Start typing a city name&hellip;">
                    <div id="sales-city-suggestions" class="sales-suggestions" style="display:none;"></div>
                </div>
            </div>

            <details class="sales-near-anchor">
                <summary style="cursor:pointer;font-weight:600;font-size:.82rem;color:var(--pc-muted,#52607a);">
                    Find cities near an anchor &mdash; pick a city, see what's around it, check the ones you want, repeat
                </summary>
                <div class="sales-form-row" style="margin:8px 0;">
                    <div style="flex:1;min-width:220px;position:relative;">
                        <label for="sales-anchor-search">Anchor city</label>
                        <input type="text" id="sales-anchor-search" autocomplete="off" placeholder="Start typing a city name&hellip;">
                        <div id="sales-anchor-suggestions" class="sales-suggestions" style="display:none;"></div>
                    </div>
                    <div><label for="sales-anchor-n">Nearest N cities</label>
                        <input type="number" id="sales-anchor-n" value="10" min="1" max="50" style="width:70px;"></div>
                    <div style="justify-content:flex-end;">
                        <button type="button" id="sales-anchor-find-btn" class="btn-sm-outline" disabled>Find nearby</button>
                    </div>
                </div>
                <p id="sales-anchor-current" style="font-size:.8rem;color:var(--pc-muted,#52607a);"></p>
                <div id="sales-anchor-results" style="display:none;">
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:6px;">
                        <a href="#" id="sales-anchor-select-all" style="font-size:.78rem;">Select all</a>
                        <a href="#" id="sales-anchor-select-none" style="font-size:.78rem;">Select none</a>
                    </div>
                    <div id="sales-anchor-list" class="sales-selected-cities"></div>
                    <button type="button" id="sales-anchor-add-btn" class="btn-open" style="margin-top:8px;">Add checked cities</button>
                </div>
            </details>

            <div id="sales-selected-cities" class="sales-selected-cities"></div>
            <p id="sales-no-cities" style="color:var(--pc-muted,#52607a);font-size:.82rem;">No cities added yet.</p>
            <input type="hidden" name="cities" id="sales-cities-json" value="[]">
            <div class="sales-form-row">
                <div><label for="sales-businesses-per-city">Businesses per city</label>
                    <input type="number" id="sales-businesses-per-city" name="businesses_per_city" value="20" min="1" max="1000"></div>
                <div style="justify-content:flex-end;">
                    <label><input type="checkbox" id="sales-domain-rank" name="domain_rank" value="1">
                        Also look up Ahrefs Domain Rating (costs extra)</label>
                </div>
            </div>
            <div id="sales-cost-estimate">Add a city and a business category to see a cost estimate.</div>
            <div style="margin-top:12px;">
                <button type="submit" class="btn-open">Start search</button>
            </div>
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        </form>
    </details>
</div>

<div class="pc-card" id="sales-progress-card" style="display:none;">
    <h3 style="margin-top:0;">Search in progress</h3>
    <p id="sales-progress-state" style="color:var(--pc-muted,#52607a);font-size:.88rem;">Starting&hellip;</p>
    <div class="pc-bulk-bar"><div class="pc-bulk-bar-fill" id="sales-progress-bar-fill" style="width:0%;"></div></div>
    <p id="sales-progress-activity" style="color:var(--pc-ink,#1b2433);font-size:.84rem;font-weight:600;min-height:1.2em;"></p>
    <div class="pc-bulk-bar" id="sales-progress-activity-bar-wrap" style="display:none;">
        <div class="pc-bulk-bar-fill" id="sales-progress-activity-bar-fill" style="width:0%;background:var(--pc-navy-2,#12264A);"></div>
    </div>
    <p id="sales-progress-cost" style="color:var(--pc-muted,#52607a);font-size:.82rem;"></p>
    <div id="sales-progress-results" style="font-size:.82rem;"></div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Spend</h3>
    <p style="font-size:.88rem;margin:0;">
        <?php
        $cum = []; foreach (sales_cumulative_cost() as $row) $cum[$row['provider'] . '_' . $row['unit']] = $row;
        $dfsTotal = $cum['dataforseo_usd']['total'] ?? 0;
        $dfsCalls = $cum['dataforseo_usd']['calls'] ?? 0;
        $ahrefsUnits = $cum['ahrefs_units']['total'] ?? 0;
        $ahrefsCalls = $cum['ahrefs_units']['calls'] ?? 0;
        ?>
        All-time, real (not estimated): <strong>$<?= number_format($dfsTotal, 2) ?></strong> on DataForSEO
        (<?= (int) $dfsCalls ?> calls) &middot; <strong><?= number_format($ahrefsUnits) ?> units</strong> on Ahrefs
        (<?= (int) $ahrefsCalls ?> calls) — confirm Ahrefs' $/unit rate on your plan to convert that to dollars.
    </p>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Saved leads (<?= count($rows) ?>)</h3>
    <form class="sales-filters" method="get">
        <select name="niche" onchange="this.form.submit()">
            <option value="">All categories</option>
            <?php foreach ($knownNiches as $n): ?>
                <option value="<?= h($n) ?>" <?= $filters['niche'] === $n ? 'selected' : '' ?>><?= h(sales_category_label($n)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="state" onchange="this.form.submit()">
            <option value="">All states</option>
            <?php foreach ($knownStates as $s): ?>
                <option value="<?= h($s) ?>" <?= $filters['state'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach ($statusLabels as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="is_wordpress" onchange="this.form.submit()">
            <option value="">WordPress: any</option>
            <option value="yes" <?= $filters['is_wordpress'] === 'yes' ? 'selected' : '' ?>>Yes</option>
            <option value="no" <?= $filters['is_wordpress'] === 'no' ? 'selected' : '' ?>>No</option>
            <option value="unknown" <?= $filters['is_wordpress'] === 'unknown' ? 'selected' : '' ?>>Unknown</option>
        </select>
        <input type="hidden" name="sort" value="<?= h($filters['sort']) ?>">
        <input type="hidden" name="dir" value="<?= h($filters['dir']) ?>">
        <a class="btn-sm-outline" href="sales_export.php?<?= h(http_build_query(array_filter($filters, fn($v) => $v !== ''))) ?>">Export CSV</a>
        <?php
        $missingRank = count(array_filter($rows, fn($r) => $r['domain_rank'] === null));
        $rankCostEstimate = $missingRank * SALES_COST_PER_DOMAIN_RANK_CALL;
        ?>
        <?php if ($missingRank > 0): ?>
            <button type="button" id="sales-bulk-rank-btn" class="btn-sm-outline"
                data-missing="<?= (int) $missingRank ?>" data-cost="<?= h(number_format($rankCostEstimate, 2)) ?>"
                data-niche="<?= h($filters['niche']) ?>" data-state="<?= h($filters['state']) ?>"
                data-status="<?= h($filters['status']) ?>" data-is-wordpress="<?= h($filters['is_wordpress']) ?>">
                Look up domain rank (<?= (int) $missingRank ?> missing)
            </button>
        <?php endif; ?>
        <?php
        // WordPress-only, by request — matches what sales_detect_offices_cli.php actually processes.
        $missingOffices = count(array_filter($rows, fn($r) => $r['office_cities'] === '' && $r['is_wordpress'] === 'yes'));
        ?>
        <?php if ($missingOffices > 0): ?>
            <button type="button" id="sales-backfill-offices-btn" class="btn-sm-outline"
                data-missing="<?= (int) $missingOffices ?>"
                data-niche="<?= h($filters['niche']) ?>" data-state="<?= h($filters['state']) ?>"
                data-status="<?= h($filters['status']) ?>" data-is-wordpress="<?= h($filters['is_wordpress']) ?>">
                Backfill office cities for WordPress leads (<?= (int) $missingOffices ?> missing)
            </button>
        <?php endif; ?>
    </form>

    <table class="sales-table">
        <thead><tr>
            <th><?= sales_sort_link('business_name', 'Business', $filters) ?></th>
            <th>Phone</th>
            <th>Domain</th>
            <th>City / State</th>
            <th><?= sales_sort_link('office_cities', 'Office City', $filters) ?></th>
            <th><?= sales_sort_link('google_rating', 'Rating', $filters) ?></th>
            <th>WordPress</th>
            <th><?= sales_sort_link('sitemap_url_count', 'Sitemap pages', $filters) ?></th>
            <th><?= sales_sort_link('domain_rank', 'Ahrefs DR', $filters) ?></th>
            <th>Status</th>
            <th>Notes</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="12" style="text-align:center;color:var(--pc-muted,#52607a);">No leads yet — run a search above.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr data-id="<?= (int) $r['id'] ?>">
                <td><?= h($r['business_name']) ?></td>
                <td><?= h($r['phone']) ?></td>
                <td><a href="<?= h($r['website_url'] ?: ('https://' . $r['domain'])) ?>" target="_blank" rel="noopener"><?= h($r['domain']) ?></a></td>
                <td><?= h($r['city']) ?>, <?= h($r['state']) ?></td>
                <td><?= $r['office_cities'] !== '' ? h($r['office_cities']) : '&mdash;' ?></td>
                <td><?= $r['google_rating'] !== null ? h((string) $r['google_rating']) . ' (' . (int) $r['google_review_count'] . ')' : '&mdash;' ?></td>
                <td><span class="sales-pill sales-pill-<?= h($r['is_wordpress']) ?>"><?= h($r['is_wordpress']) ?></span></td>
                <td><?= $r['sitemap_url_count'] !== null ? (int) $r['sitemap_url_count'] : '&mdash;' ?></td>
                <td><?= $r['domain_rank'] !== null ? (int) $r['domain_rank'] : '&mdash;' ?></td>
                <td>
                    <select class="sales-status-select" data-id="<?= (int) $r['id'] ?>">
                        <?php foreach ($statusLabels as $k => $label): ?>
                            <option value="<?= h($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="sales-notes-input" data-id="<?= (int) $r['id'] ?>" value="<?= h($r['notes']) ?>"></td>
                <td><button type="button" class="btn-sm-outline sales-refresh-btn" data-id="<?= (int) $r['id'] ?>">Refresh</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
(function() {
    var csrfToken = <?= json_encode($csrfToken) ?>;

    function post(url, data) {
        var body = new URLSearchParams(data);
        body.append('csrf_token', csrfToken);
        return fetch(url, {method: 'POST', body: body}).then(function(r) { return r.json(); });
    }

    /* ---- city picker: search-and-add, per-city radius, client-side overlap check ---- */
    var stateSelect = document.getElementById('sales-state-select');
    var citySearchInput = document.getElementById('sales-city-search');
    var suggestionsEl = document.getElementById('sales-city-suggestions');
    var selectedCitiesEl = document.getElementById('sales-selected-cities');
    var noCitiesEl = document.getElementById('sales-no-cities');
    var citiesJsonInput = document.getElementById('sales-cities-json');
    var quickAddN = document.getElementById('sales-quick-add-n');
    var quickAddBtn = document.getElementById('sales-quick-add-btn');
    var selectedCities = []; // {city, state, ss, lat, lng, population, radius_km, history}

    function historyText(history) {
        if (!history || !history.length) return '';
        return 'already searched: ' + history.map(function(h) { return h.niche + ' (' + h.cnt + ')'; }).join(', ');
    }

    // Plain Haversine — every selected city already carries lat/lng from the search
    // endpoint, so this is a pure client-side calc, no server round-trip needed.
    function haversineKm(lat1, lng1, lat2, lng2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180, dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function computeOverlaps() {
        var overlaps = {};
        for (var i = 0; i < selectedCities.length; i++) {
            for (var j = i + 1; j < selectedCities.length; j++) {
                var a = selectedCities[i], b = selectedCities[j];
                var dist = haversineKm(parseFloat(a.lat), parseFloat(a.lng), parseFloat(b.lat), parseFloat(b.lng));
                var combined = (parseFloat(a.radius_km) || 0) + (parseFloat(b.radius_km) || 0);
                if (dist < combined) {
                    (overlaps[i] = overlaps[i] || []).push(b.city + ', ' + b.ss + ' (~' + Math.round(dist) + 'km apart)');
                    (overlaps[j] = overlaps[j] || []).push(a.city + ', ' + a.ss + ' (~' + Math.round(dist) + 'km apart)');
                }
            }
        }
        return overlaps;
    }

    function renderSelectedCities() {
        noCitiesEl.style.display = selectedCities.length ? 'none' : 'block';
        var overlaps = computeOverlaps();
        selectedCitiesEl.innerHTML = selectedCities.map(function(c, i) {
            var hist = historyText(c.history);
            return '<div class="sales-city-row' + (overlaps[i] ? ' overlap' : '') + '">'
                + '<div class="sales-city-name">' + c.city + ', ' + c.ss + ' — pop ' + Number(c.population).toLocaleString()
                + (hist ? '<div class="sales-city-history">' + hist + '</div>' : '')
                + (overlaps[i] ? '<div class="sales-city-overlap">overlaps with ' + overlaps[i].join('; ') + '</div>' : '')
                + '</div>'
                + '<label>radius km <input type="number" min="1" max="100000" value="' + c.radius_km + '" class="sales-city-radius" data-index="' + i + '"></label>'
                + '<button type="button" class="sales-city-remove" data-index="' + i + '" title="Remove">&times;</button>'
                + '</div>';
        }).join('');
        citiesJsonInput.value = JSON.stringify(selectedCities.map(function(c) {
            return {city: c.city, state: c.state, ss: c.ss, lat: c.lat, lng: c.lng, radius_km: c.radius_km};
        }));
        selectedCitiesEl.querySelectorAll('.sales-city-radius').forEach(function(el) {
            el.addEventListener('change', function() {
                selectedCities[parseInt(el.dataset.index, 10)].radius_km = Math.max(1, Math.min(100000, parseInt(el.value, 10) || 20));
                renderSelectedCities();
                updateEstimate();
            });
        });
        selectedCitiesEl.querySelectorAll('.sales-city-remove').forEach(function(el) {
            el.addEventListener('click', function() {
                selectedCities.splice(parseInt(el.dataset.index, 10), 1);
                renderSelectedCities();
                updateEstimate();
            });
        });
        updateEstimate();
    }

    function addCity(c) {
        if (selectedCities.some(function(x) { return x.city === c.city && x.ss === c.ss; })) return;
        selectedCities.push({
            city: c.city, state: c.state, ss: c.ss, lat: c.lat, lng: c.lng,
            population: c.population, radius_km: 20, history: c.history || []
        });
        renderSelectedCities();
    }

    var citySearchTimer = null;
    citySearchInput.addEventListener('input', function() {
        clearTimeout(citySearchTimer);
        var q = citySearchInput.value.trim();
        if (q.length < 2) { suggestionsEl.style.display = 'none'; return; }
        citySearchTimer = setTimeout(function() {
            var url = 'sales_launch.php?action=search_cities&q=' + encodeURIComponent(q) + '&state=' + encodeURIComponent(stateSelect.value);
            fetch(url).then(function(r) { return r.json(); }).then(function(rows) {
                if (!rows || !rows.length) { suggestionsEl.innerHTML = '<div>No matching cities.</div>'; suggestionsEl.style.display = 'block'; return; }
                suggestionsEl.innerHTML = rows.map(function(r, i) {
                    var hist = historyText(r.history);
                    return '<div data-i="' + i + '">' + r.city + ', ' + r.ss + ' — pop ' + Number(r.population).toLocaleString()
                        + (hist ? '<div class="sales-suggestion-history">' + hist + '</div>' : '') + '</div>';
                }).join('');
                suggestionsEl.style.display = 'block';
                suggestionsEl.querySelectorAll('[data-i]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        addCity(rows[parseInt(el.dataset.i, 10)]);
                        citySearchInput.value = '';
                        suggestionsEl.style.display = 'none';
                    });
                });
            }).catch(function() {});
        }, 300);
    });
    document.addEventListener('click', function(e) {
        if (e.target !== citySearchInput && !suggestionsEl.contains(e.target)) suggestionsEl.style.display = 'none';
    });

    quickAddBtn.addEventListener('click', function() {
        var state = stateSelect.value;
        if (!state) { alert('Pick a state first.'); return; }
        var n = Math.max(1, Math.min(50, parseInt(quickAddN.value, 10) || 5));
        fetch('sales_launch.php?action=search_cities&q=&state=' + encodeURIComponent(state) + '&limit=' + n)
            .then(function(r) { return r.json(); })
            .then(function(rows) { (rows || []).forEach(addCity); })
            .catch(function() {});
    });

    /* ---- "find cities near an anchor" round-trip: pick anchor, check some nearby, repeat ---- */
    var anchorSearchInput = document.getElementById('sales-anchor-search');
    var anchorSuggestionsEl = document.getElementById('sales-anchor-suggestions');
    var anchorCurrentEl = document.getElementById('sales-anchor-current');
    var anchorN = document.getElementById('sales-anchor-n');
    var anchorFindBtn = document.getElementById('sales-anchor-find-btn');
    var anchorResultsEl = document.getElementById('sales-anchor-results');
    var anchorListEl = document.getElementById('sales-anchor-list');
    var anchorAddBtn = document.getElementById('sales-anchor-add-btn');
    var currentAnchor = null;
    var nearbyResults = [];

    function resetAnchorPicker() {
        currentAnchor = null;
        anchorSearchInput.value = '';
        anchorCurrentEl.textContent = '';
        anchorFindBtn.disabled = true;
        anchorResultsEl.style.display = 'none';
        anchorListEl.innerHTML = '';
        nearbyResults = [];
    }

    var anchorSearchTimer = null;
    anchorSearchInput.addEventListener('input', function() {
        clearTimeout(anchorSearchTimer);
        var q = anchorSearchInput.value.trim();
        if (q.length < 2) { anchorSuggestionsEl.style.display = 'none'; return; }
        anchorSearchTimer = setTimeout(function() {
            fetch('sales_launch.php?action=search_cities&q=' + encodeURIComponent(q) + '&state=' + encodeURIComponent(stateSelect.value))
                .then(function(r) { return r.json(); })
                .then(function(rows) {
                    if (!rows || !rows.length) { anchorSuggestionsEl.innerHTML = '<div>No matching cities.</div>'; anchorSuggestionsEl.style.display = 'block'; return; }
                    anchorSuggestionsEl.innerHTML = rows.map(function(r, i) {
                        return '<div data-i="' + i + '">' + r.city + ', ' + r.ss + ' — pop ' + Number(r.population).toLocaleString() + '</div>';
                    }).join('');
                    anchorSuggestionsEl.style.display = 'block';
                    anchorSuggestionsEl.querySelectorAll('[data-i]').forEach(function(el) {
                        el.addEventListener('click', function() {
                            currentAnchor = rows[parseInt(el.dataset.i, 10)];
                            anchorSearchInput.value = '';
                            anchorSuggestionsEl.style.display = 'none';
                            anchorCurrentEl.textContent = 'Anchor: ' + currentAnchor.city + ', ' + currentAnchor.ss;
                            anchorFindBtn.disabled = false;
                            anchorResultsEl.style.display = 'none';
                        });
                    });
                }).catch(function() {});
        }, 300);
    });
    document.addEventListener('click', function(e) {
        if (e.target !== anchorSearchInput && !anchorSuggestionsEl.contains(e.target)) anchorSuggestionsEl.style.display = 'none';
    });

    anchorFindBtn.addEventListener('click', function() {
        if (!currentAnchor) return;
        var n = Math.max(1, Math.min(50, parseInt(anchorN.value, 10) || 10));
        fetch('sales_launch.php?action=cities_near&lat=' + encodeURIComponent(currentAnchor.lat)
            + '&lng=' + encodeURIComponent(currentAnchor.lng) + '&limit=' + n)
            .then(function(r) { return r.json(); })
            .then(function(rows) {
                nearbyResults = rows || [];
                anchorListEl.innerHTML = nearbyResults.map(function(r, i) {
                    var hist = historyText(r.history);
                    return '<div class="sales-city-row">'
                        + '<label class="sales-city-pick"><input type="checkbox" class="sales-anchor-check" data-i="' + i + '"'
                        + (r.distance_km === 0 ? ' checked' : '') + '>'
                        + '<span class="sales-city-name">' + r.city + ', ' + r.ss + ' — ' + r.distance_km + 'km away — pop '
                        + Number(r.population).toLocaleString() + (hist ? '<div class="sales-city-history">' + hist + '</div>' : '') + '</span>'
                        + '</label></div>';
                }).join('');
                anchorResultsEl.style.display = 'block';
            }).catch(function() {});
    });

    document.getElementById('sales-anchor-select-all').addEventListener('click', function(e) {
        e.preventDefault();
        anchorListEl.querySelectorAll('.sales-anchor-check').forEach(function(el) { el.checked = true; });
    });
    document.getElementById('sales-anchor-select-none').addEventListener('click', function(e) {
        e.preventDefault();
        anchorListEl.querySelectorAll('.sales-anchor-check').forEach(function(el) { el.checked = false; });
    });

    anchorAddBtn.addEventListener('click', function() {
        anchorListEl.querySelectorAll('.sales-anchor-check:checked').forEach(function(el) {
            addCity(nearbyResults[parseInt(el.dataset.i, 10)]);
        });
        resetAnchorPicker(); // one round done — ready to pick a new anchor and repeat
    });

    renderSelectedCities(); // initial render: empty-state message

    /* ---- cost estimate + launch ---- */
    var form = document.getElementById('sales-search-form');
    var estimateEl = document.getElementById('sales-cost-estimate');
    var estimateTimer = null;

    function formData() {
        var fd = new FormData(form);
        return fd;
    }

    function updateEstimate() {
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(function() {
            var data = new URLSearchParams(formData());
            data.set('preview', '1');
            data.append('csrf_token', csrfToken);
            fetch('sales_launch.php', {method: 'POST', body: data}).then(function(r) { return r.json(); }).then(function(s) {
                if (s.error) { estimateEl.textContent = s.error; return; }
                estimateEl.textContent = s.cities_total + ' cities, ~' + s.businesses_est + ' businesses — estimated cost $'
                    + s.cost_low.toFixed(2) + ' to $' + s.cost_high.toFixed(2) + '.';
            }).catch(function() {});
        }, 400);
    }
    form.addEventListener('input', updateEstimate);
    form.addEventListener('change', updateEstimate);

    var progressCard = document.getElementById('sales-progress-card');
    var progressState = document.getElementById('sales-progress-state');
    var progressBar = document.getElementById('sales-progress-bar-fill');
    var progressActivity = document.getElementById('sales-progress-activity');
    var progressActivityBarWrap = document.getElementById('sales-progress-activity-bar-wrap');
    var progressActivityBar = document.getElementById('sales-progress-activity-bar-fill');
    var progressCost = document.getElementById('sales-progress-cost');
    var progressResults = document.getElementById('sales-progress-results');
    var terminal = {done: true, failed: true, stale: true};

    function renderProgress(s) {
        var pct = s.total ? Math.round((s.done / s.total) * 100) : 0;
        progressBar.style.width = pct + '%';
        var label = s.job_type === 'office_backfill' ? ({
            running: 'Checking ' + s.done + ' of ' + s.total + ' websites for office locations&hellip; (' + s.found_new + ' found so far)',
            done: 'Done — found an office city for ' + s.found_new + ' of ' + s.total + ' leads.',
            failed: 'Finished with errors — ' + s.found_new + ' of ' + s.done + ' checked so far found an office city.',
            stale: 'The backfill stopped unexpectedly (' + s.done + ' of ' + s.total + ' checked). Safe to start a new one.',
            pending: 'Starting&hellip;'
        }[s.state] || s.state) : ({
            running: 'Searching ' + s.done + ' of ' + s.total + ' cities&hellip; (' + s.found_new + ' new, ' + s.skipped_known + ' already known)',
            done: 'Done — ' + s.found_new + ' new leads found, ' + s.skipped_known + ' already known.',
            failed: 'Finished with errors — ' + s.found_new + ' new leads found before the problem.',
            stale: 'The search stopped unexpectedly (' + s.done + ' of ' + s.total + ' cities completed). Safe to start a new search.',
            pending: 'Starting&hellip;'
        }[s.state] || s.state);
        progressState.innerHTML = label;

        // What's actually happening right now, within the current city/phase — without
        // this a city with a big businesses-per-city cap goes silent for minutes while
        // every business gets a real WordPress + sitemap check, which reads as stuck.
        var activity = '';
        var activityPct = null;
        if (s.phase === 'searching' && s.current_city) {
            var modeNote = s.niche_mode === 'keyword' ? ' (as a keyword, not a category)' : '';
            activity = 'Searching ' + s.current_city + ' for ' + (s.niche || 'businesses') + modeNote + '&hellip;';
        } else if (s.phase === 'checking_businesses' && s.current_city) {
            activity = 'Checking businesses in ' + s.current_city + ': ' + (s.city_businesses_checked || 0)
                + ' of ' + (s.city_businesses_found || 0) + ' (WordPress + sitemap)';
            if (s.city_businesses_found) activityPct = Math.round((s.city_businesses_checked / s.city_businesses_found) * 100);
        } else if (s.phase === 'looking_up_rank') {
            activity = 'Looking up Ahrefs Domain Rating: ' + (s.rank_checked || 0) + ' of ' + (s.rank_total || 0) + ' domains';
            if (s.rank_total) activityPct = Math.round((s.rank_checked / s.rank_total) * 100);
        }
        progressActivity.textContent = activity;
        if (activityPct !== null) {
            progressActivityBarWrap.style.display = 'block';
            progressActivityBar.style.width = activityPct + '%';
        } else {
            progressActivityBarWrap.style.display = 'none';
        }

        if (typeof s.cost_dataforseo_usd === 'number') {
            var costLine = 'Real cost so far: $' + s.cost_dataforseo_usd.toFixed(4) + ' on DataForSEO';
            if (s.cost_ahrefs_units) costLine += ' + ' + s.cost_ahrefs_units + ' Ahrefs units';
            progressCost.textContent = costLine;
        }
        progressResults.innerHTML = (s.results || []).map(function(r) {
            return '<div>' + (r.city || '') + ', ' + (r.state || '') + ' — ' + (r.msg || r.status || '') + '</div>';
        }).join('');
        if (terminal[s.state]) {
            progressActivity.textContent = '';
            progressActivityBarWrap.style.display = 'none';
            setTimeout(function() { window.location.href = 'sales.php'; }, 1500);
            return true;
        }
        return false;
    }

    function poll(runId) {
        fetch('sales_status.php?run_id=' + encodeURIComponent(runId))
            .then(function(r) { return r.json(); })
            .then(function(s) {
                var finished = renderProgress(s);
                if (!finished) setTimeout(function() { poll(runId); }, 2000);
            })
            .catch(function() { setTimeout(function() { poll(runId); }, 3000); });
    }

    // Auto-resume: a search is a detached background job with no connection to the
    // browser tab that launched it, so without this, reloading the page (or opening
    // it in a second tab) while a search is running silently lost the live progress
    // view even though the search itself kept working untouched.
    var activeRunId = <?= json_encode($activeRunId) ?>;
    if (activeRunId) {
        progressCard.style.display = 'block';
        poll(activeRunId);
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!selectedCities.length) { alert('Add at least one city.'); return; }
        // Overlap is advisory, not a hard block — warn and let the user proceed, same
        // confirm-before-spend pattern the cost estimate and bulk-rank button already use.
        var overlaps = computeOverlaps();
        var overlapCities = Object.keys(overlaps).map(function(i) { return selectedCities[i].city + ', ' + selectedCities[i].ss; });
        if (overlapCities.length && !confirm(
            'These cities\' search areas overlap and may re-discover (and re-pay for) the same businesses: '
            + overlapCities.join('; ') + '. Continue anyway?'
        )) return;

        var data = new URLSearchParams(formData());
        data.append('csrf_token', csrfToken);
        fetch('sales_launch.php', {method: 'POST', body: data}).then(function(r) { return r.json(); }).then(function(s) {
            if (s.error) { alert(s.error); return; }
            // sales_launch_job() uses started/msg, NOT error, for an "already running" rejection —
            // this used to fall through silently and quietly start polling whatever job WAS
            // running as if it were the one just launched, with no visible explanation at all.
            if (s.started === false) { alert(s.msg || 'Could not start the search.'); return; }
            progressCard.style.display = 'block';
            poll(s.run_id);
        }).catch(function(err) { alert('Could not reach the server to start the search: ' + err); });
    });

    /* ---- inline status/notes/refresh on the results table ---- */
    document.querySelectorAll('.sales-status-select').forEach(function(el) {
        el.addEventListener('change', function() {
            post('sales_save.php', {id: el.dataset.id, action: 'update', status: el.value});
        });
    });
    document.querySelectorAll('.sales-notes-input').forEach(function(el) {
        el.addEventListener('blur', function() {
            post('sales_save.php', {id: el.dataset.id, action: 'update', notes: el.value});
        });
    });
    document.querySelectorAll('.sales-refresh-btn').forEach(function(el) {
        el.addEventListener('click', function() {
            el.disabled = true; el.textContent = 'Refreshing…';
            post('sales_save.php', {id: el.dataset.id, action: 'refresh'}).then(function(s) {
                if (s.ok) { window.location.reload(); } else { alert(s.error || 'Refresh failed'); el.disabled = false; el.textContent = 'Refresh'; }
            });
        });
    });

    /* ---- bulk domain-rank lookup, scoped to the current filter ---- */
    var bulkRankBtn = document.getElementById('sales-bulk-rank-btn');
    if (bulkRankBtn) {
        bulkRankBtn.addEventListener('click', function() {
            var missing = bulkRankBtn.dataset.missing;
            var cost = bulkRankBtn.dataset.cost;
            if (!confirm('Look up domain rank for ' + missing + ' lead(s) missing one — estimated cost $' + cost + '. Continue?')) return;
            bulkRankBtn.disabled = true; bulkRankBtn.textContent = 'Looking up…';
            post('sales_save.php', {
                action: 'bulk_rank', niche: bulkRankBtn.dataset.niche, state: bulkRankBtn.dataset.state,
                status: bulkRankBtn.dataset.status, is_wordpress: bulkRankBtn.dataset.isWordpress
            }).then(function(s) {
                if (s.ok) { window.location.reload(); }
                else { alert(s.msg || s.error || 'Lookup failed'); bulkRankBtn.disabled = false; bulkRankBtn.textContent = 'Look up domain rank (' + missing + ' missing)'; }
            });
        });
    }

    /* ---- bulk office-city backfill, scoped to the current filter — a real background job, not a quick button ---- */
    var backfillBtn = document.getElementById('sales-backfill-offices-btn');
    if (backfillBtn) {
        backfillBtn.addEventListener('click', function() {
            var missing = backfillBtn.dataset.missing;
            if (!confirm('Check ' + missing + ' WordPress website(s) for their office city. This is free but checks one site at a time, so it may take a while for a large list. Continue?')) return;
            backfillBtn.disabled = true; backfillBtn.textContent = 'Starting…';
            var resetBtn = function() { backfillBtn.disabled = false; backfillBtn.textContent = 'Backfill office cities for WordPress leads (' + missing + ' missing)'; };
            var data = new URLSearchParams({
                action: 'backfill_offices', niche: backfillBtn.dataset.niche, state: backfillBtn.dataset.state,
                status: backfillBtn.dataset.status, is_wordpress: backfillBtn.dataset.isWordpress, csrf_token: csrfToken
            });
            fetch('sales_launch.php', {method: 'POST', body: data}).then(function(r) { return r.json(); }).then(function(s) {
                if (s.error) { alert(s.error); resetBtn(); return; }
                // sales_launch_office_detect_job() uses started/msg, NOT error, for an "already
                // running" rejection — this used to fall through silently as if it had launched.
                if (s.started === false) { alert(s.msg || 'Could not start the backfill.'); resetBtn(); return; }
                progressCard.style.display = 'block';
                poll(s.run_id);
            }).catch(function(err) { alert('Could not reach the server to start the backfill: ' + err); resetBtn(); });
        });
    }
})();
</script>

<?php lpc_admin_close(); ?>
