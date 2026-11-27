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
.sales-states{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:4px 10px;max-height:180px;overflow-y:auto;border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;padding:10px;margin:8px 0;}
.sales-states label{font-size:.82rem;display:flex;align-items:center;gap:5px;font-weight:400;}
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
</style>

<div class="pc-card">
    <details <?= empty($_GET['run_id']) ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-weight:700;">New Search</summary>
        <form id="sales-search-form" style="margin-top:12px;">
            <div class="sales-form-row">
                <div style="flex:1;min-width:260px;">
                    <label for="sales-niche">Business category</label>
                    <input type="text" id="sales-niche" name="niche" list="sales-categories" autocomplete="off"
                        placeholder="e.g. roofing_contractor" required>
                    <datalist id="sales-categories">
                        <?php foreach ($categories as $slug): ?>
                            <option value="<?= h($slug) ?>" label="<?= h(sales_category_label($slug)) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <small style="color:var(--pc-muted,#52607a);">
                        <?= count($categories) ?> categories loaded from DataForSEO's own taxonomy — must match a real
                        slug exactly (not free text). <a href="sales.php?refresh_categories=1">Refresh list</a>
                    </small>
                </div>
            </div>
            <label style="font-size:.8rem;font-weight:600;color:var(--pc-muted,#52607a);">States to focus on</label>
            <div class="sales-states">
                <?php foreach ($states as $ss => $name): ?>
                    <label><input type="checkbox" name="states[]" value="<?= h($ss) ?>"> <?= h($name) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="sales-form-row">
                <div><label for="sales-cities-per-state">Cities per state</label>
                    <input type="number" id="sales-cities-per-state" name="cities_per_state" value="5" min="1" max="50"></div>
                <div><label for="sales-businesses-per-city">Businesses per city</label>
                    <input type="number" id="sales-businesses-per-city" name="businesses_per_city" value="20" min="1" max="1000"></div>
                <div><label for="sales-radius-km">Search radius (km)</label>
                    <input type="number" id="sales-radius-km" name="radius_km" value="20" min="1" max="100000"></div>
                <div style="justify-content:flex-end;">
                    <label><input type="checkbox" id="sales-domain-rank" name="domain_rank" value="1">
                        Also look up domain rank (costs extra)</label>
                </div>
            </div>
            <div id="sales-cost-estimate">Fill in the form to see a cost estimate.</div>
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
    <div id="sales-progress-results" style="font-size:.82rem;"></div>
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
    </form>

    <table class="sales-table">
        <thead><tr>
            <th><?= sales_sort_link('business_name', 'Business', $filters) ?></th>
            <th>Phone</th>
            <th>Domain</th>
            <th>City / State</th>
            <th><?= sales_sort_link('google_rating', 'Rating', $filters) ?></th>
            <th>WordPress</th>
            <th><?= sales_sort_link('sitemap_url_count', 'Sitemap pages', $filters) ?></th>
            <th><?= sales_sort_link('domain_rank', 'Domain rank', $filters) ?></th>
            <th>Status</th>
            <th>Notes</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="11" style="text-align:center;color:var(--pc-muted,#52607a);">No leads yet — run a search above.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr data-id="<?= (int) $r['id'] ?>">
                <td><?= h($r['business_name']) ?></td>
                <td><?= h($r['phone']) ?></td>
                <td><a href="<?= h($r['website_url'] ?: ('https://' . $r['domain'])) ?>" target="_blank" rel="noopener"><?= h($r['domain']) ?></a></td>
                <td><?= h($r['city']) ?>, <?= h($r['state']) ?></td>
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
    var progressResults = document.getElementById('sales-progress-results');
    var terminal = {done: true, failed: true, stale: true};

    function renderProgress(s) {
        var pct = s.total ? Math.round((s.done / s.total) * 100) : 0;
        progressBar.style.width = pct + '%';
        var label = {
            running: 'Searching ' + s.done + ' of ' + s.total + ' cities&hellip; (' + s.found_new + ' new, ' + s.skipped_known + ' already known)',
            done: 'Done — ' + s.found_new + ' new leads found, ' + s.skipped_known + ' already known.',
            failed: 'Finished with errors — ' + s.found_new + ' new leads found before the problem.',
            stale: 'The search stopped unexpectedly (' + s.done + ' of ' + s.total + ' cities completed). Safe to start a new search.',
            pending: 'Starting&hellip;'
        }[s.state] || s.state;
        progressState.innerHTML = label;
        progressResults.innerHTML = (s.results || []).map(function(r) {
            return '<div>' + (r.city || '') + ', ' + (r.state || '') + ' — ' + (r.msg || r.status || '') + '</div>';
        }).join('');
        if (terminal[s.state]) {
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

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var data = new URLSearchParams(formData());
        data.append('csrf_token', csrfToken);
        fetch('sales_launch.php', {method: 'POST', body: data}).then(function(r) { return r.json(); }).then(function(s) {
            if (s.error) { alert(s.error); return; }
            progressCard.style.display = 'block';
            poll(s.run_id);
        });
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
})();
</script>

<?php lpc_admin_close(); ?>
