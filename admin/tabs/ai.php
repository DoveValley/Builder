<?php
// AI Generation tab — log history, city coverage, cost summary.
// $tab, $csrfToken, ACTIVE_SITE_DIR, ACTIVE_SITE_ID available from index.php.

$logFile = ACTIVE_SITE_DIR . '/data/generation_log.json';
$logEntries = [];
if (file_exists($logFile)) {
    $raw = json_decode(file_get_contents($logFile), true);
    if (is_array($raw)) {
        $logEntries = array_reverse($raw); // newest first
    }
}

// ── City coverage: scan pages/ for ai_blocks with _ai_generated ──────────────
$pagesDir   = ACTIVE_SITE_DIR . '/data/pages';
$cityCoverage = [];  // city_id => ['city' => name, 'ss' => ..., 'generated' => N, 'last_at' => ts]

// Load cities.json for the city list
$citiesFile = ACTIVE_SITE_DIR . '/data/cities.json';
$cities = [];
if (file_exists($citiesFile)) {
    $cities = json_decode(file_get_contents($citiesFile), true) ?: [];
    foreach ($cities as $c) {
        $id = $c['id'] ?? '';
        if (!$id) continue;
        $cityCoverage[$id] = [
            'city'       => $c['city'] ?? $id,
            'ss'         => $c['SS']   ?? '',
            'researched' => !empty($c['industries']) || !empty($c['top_employers']),
            'generated'  => 0,
            'last_at'    => '',
            'pages'      => 0,
        ];
    }
}

// Walk page files to count generated blocks per city
if (is_dir($pagesDir)) {
    foreach (glob($pagesDir . '/*.json') as $pageFile) {
        $page = json_decode(file_get_contents($pageFile), true);
        if (!is_array($page)) continue;
        $cityId = $page['city_id'] ?? '';
        if (!$cityId || !isset($cityCoverage[$cityId])) continue;
        $blocks = $page['content_blocks'] ?? [];
        $genCount = 0;
        $lastTs   = '';
        foreach ($blocks as $b) {
            if (!empty($b['_ai_generated'])) {
                $genCount++;
                $ts = $b['_ai_generated_at'] ?? '';
                if ($ts > $lastTs) $lastTs = $ts;
            }
        }
        if ($genCount) {
            $cityCoverage[$cityId]['generated'] += $genCount;
            $cityCoverage[$cityId]['pages']++;
            if ($lastTs > $cityCoverage[$cityId]['last_at']) {
                $cityCoverage[$cityId]['last_at'] = $lastTs;
            }
        }
    }
}

// ── Totals ────────────────────────────────────────────────────────────────────
$totalBlocks = array_sum(array_column($logEntries, 'blocks_generated'));
$totalCost   = array_sum(array_column($logEntries, 'estimated_cost_usd'));
$totalCalls  = array_sum(array_column($logEntries, 'api_calls'));
$lastRun     = !empty($logEntries) ? ($logEntries[0]['started_at'] ?? '') : '';
$citiesResearched = count(array_filter($cityCoverage, fn($c) => $c['researched']));
$citiesGenerated  = count(array_filter($cityCoverage, fn($c) => $c['generated'] > 0));

function fmt_ts_ai(string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('M j, Y H:i', $ts) : '—';
}
function fmt_dur(int $ms): string {
    if ($ms < 1000) return $ms . 'ms';
    return round($ms / 1000, 1) . 's';
}
?>
<div class="tab-content" style="<?= $tab === 'ai' ? '' : 'display:none;' ?>">
<?php tab_header('AI Generation', 'Run the AI generator to create city-specific content for landing pages. This tab shows run history, city coverage, and estimated API cost.', 'tab-generate'); ?>

<style>
.ai-stats-row    { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:28px; }
.ai-stat-card    { flex:1; min-width:140px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 20px; }
.ai-stat-val     { font-size:1.6rem; font-weight:700; color:#111; line-height:1; }
.ai-stat-label   { font-size:.78rem; color:#6b7280; margin-top:4px; }
.ai-table        { width:100%; border-collapse:collapse; font-size:.84rem; }
.ai-table th     { text-align:left; padding:8px 10px; border-bottom:2px solid #e5e7eb; font-size:.76rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; background:#f8fafc; }
.ai-table td     { padding:9px 10px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.ai-table tr:last-child td { border-bottom:none; }
.ai-table tbody tr:hover td { background:#fafafa; }
.ai-badge        { display:inline-block; padding:2px 7px; border-radius:4px; font-size:.72rem; font-weight:600; }
.badge-ok        { background:#d1fae5; color:#065f46; }
.badge-warn      { background:#fef3c7; color:#92400e; }
.badge-none      { background:#f3f4f6; color:#9ca3af; }
.cmd-box         { background:#1e293b; color:#e2e8f0; font-family:monospace; font-size:.82rem; padding:14px 16px; border-radius:8px; margin-bottom:24px; overflow-x:auto; white-space:pre-wrap; word-break:break-all; line-height:1.6; }
.cmd-box .cmd-comment { color:#64748b; }
.ai-console      { background:#0f172a; color:#94a3b8; font-family:monospace; font-size:.78rem; line-height:1.6; padding:14px 16px; border-radius:8px; max-height:360px; overflow-y:auto; white-space:pre-wrap; word-break:break-all; display:none; margin-top:14px; }
.ai-console.open { display:block; }
.ai-console .ok  { color:#4ade80; }
.ai-console .err { color:#f87171; }
.ai-console .sep { color:#334155; }
.ai-trigger-row  { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.ai-trigger-row .form-group { margin:0; }
.ai-trigger-row label { font-size:.8rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.ai-trigger-row select, .ai-trigger-row input[type=text] { padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.83rem; }
.ai-spinner      { display:none; width:18px; height:18px; border:2px solid #e5e7eb; border-top-color:var(--color-accent,#fd783b); border-radius:50%; animation:ai-spin .7s linear infinite; flex-shrink:0; }
.ai-spinner.on   { display:inline-block; }
@keyframes ai-spin { to { transform:rotate(360deg); } }
.ai-run-btn      { background:var(--color-accent,#fd783b); color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:.85rem; font-weight:600; cursor:pointer; white-space:nowrap; }
.ai-run-btn:disabled { opacity:.55; cursor:not-allowed; }
.ai-result-bar   { display:none; font-size:.82rem; margin-top:10px; padding:8px 12px; border-radius:6px; }
.ai-result-bar.ok   { background:#d1fae5; color:#065f46; display:block; }
.ai-result-bar.fail { background:#fee2e2; color:#991b1b; display:block; }
.ai-summary      { display:none; border-radius:8px; padding:16px 20px; margin-top:14px; }
.ai-summary.ok   { background:#f0fdf4; border:1px solid #86efac; display:block; }
.ai-summary.fail { background:#fef2f2; border:1px solid #fecaca; display:block; }
.ai-summary-head { font-size:.95rem; font-weight:700; margin-bottom:10px; }
.ai-summary-head.ok   { color:#166534; }
.ai-summary-head.fail { color:#991b1b; }
.ai-summary-stats { display:flex; flex-wrap:wrap; gap:12px 28px; font-size:.83rem; color:#374151; margin-bottom:0; }
.ai-summary-stat  { display:flex; flex-direction:column; }
.ai-summary-stat .val { font-size:1.1rem; font-weight:700; line-height:1.2; }
.ai-summary-stat .lbl { font-size:.72rem; color:#6b7280; }
.ai-summary-meta  { margin-top:8px; padding-top:8px; border-top:1px solid #bbf7d0; font-size:.76rem; color:#6b7280; display:flex; gap:16px; flex-wrap:wrap; }
.ai-summary-meta.fail { border-top-color:#fecaca; }
</style>

<!-- ── Summary cards ── -->
<div class="ai-stats-row">
    <div class="ai-stat-card">
        <div class="ai-stat-val"><?= $citiesGenerated ?> / <?= count($cityCoverage) ?></div>
        <div class="ai-stat-label">Cities with generated content</div>
    </div>
    <div class="ai-stat-card">
        <div class="ai-stat-val"><?= $citiesResearched ?></div>
        <div class="ai-stat-label">Cities researched</div>
    </div>
    <div class="ai-stat-card">
        <div class="ai-stat-val"><?= number_format($totalBlocks) ?></div>
        <div class="ai-stat-label">Blocks generated (all runs)</div>
    </div>
    <div class="ai-stat-card">
        <div class="ai-stat-val">$<?= number_format($totalCost, 4) ?></div>
        <div class="ai-stat-label">Est. total API cost</div>
    </div>
    <div class="ai-stat-card">
        <div class="ai-stat-val"><?= count($logEntries) ?></div>
        <div class="ai-stat-label">Total runs logged</div>
    </div>
</div>

<!-- ── AI Trigger ── -->
<?php $apiKeyOk = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== ''; ?>
<div class="card" style="margin-bottom:24px;" id="ai-trigger-card">
    <h3 style="margin-top:0; margin-bottom:16px;">Run Generator</h3>

    <!-- API key input — always visible -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;">
        <label style="font-size:.8rem;font-weight:600;color:#374151;white-space:nowrap;">Anthropic API Key</label>
        <input type="password" id="ai-key-input" autocomplete="off" spellcheck="false"
               value="<?= $apiKeyOk ? '••••••••••••••••••••••••' : '' ?>"
               placeholder="sk-ant-api03-…"
               style="flex:1;min-width:220px;padding:7px 10px;border:1px solid <?= $apiKeyOk ? '#86efac' : '#fca5a5' ?>;border-radius:6px;font-size:.83rem;font-family:monospace;background:<?= $apiKeyOk ? '#f0fdf4' : '#fff' ?>;">
        <button type="button" onclick="aiKeySave()" class="btn btn-secondary" style="font-size:.78rem;padding:6px 14px;white-space:nowrap;">Save Key</button>
        <span id="ai-key-status" style="font-size:.78rem;color:#6b7280;"></span>
        <?php if ($apiKeyOk): ?>
        <span style="font-size:.75rem;color:#16a34a;font-weight:600;">&#10003; Configured</span>
        <?php else: ?>
        <span style="font-size:.75rem;color:#dc2626;font-weight:600;">Not configured</span>
        <?php endif; ?>
    </div>

    <?php if (!$apiKeyOk): ?>
    <p class="hint" style="margin:0;">Enter your Anthropic API key above to enable the generator.</p>
    <?php else: ?>
    <form id="ai-trigger-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <div class="ai-trigger-row" style="margin-bottom:14px;">
            <div class="form-group">
                <label for="ai-action">Action</label>
                <select name="action" id="ai-action">
                    <option value="generate">Generate content</option>
                    <option value="research">Research only</option>
                    <option value="sync">Sync templates</option>
                </select>
            </div>
            <div class="form-group" id="ai-city-wrap">
                <label for="ai-city">City</label>
                <select name="city_id" id="ai-city">
                    <option value="">All cities</option>
                    <?php foreach ($cities as $c): ?>
                    <option value="<?= h($c['id'] ?? '') ?>"><?= h($c['city'] ?? ($c['id'] ?? '')) ?>, <?= h($c['SS'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="ai-scope-wrap">
                <label for="ai-scope">Scope</label>
                <select name="scope" id="ai-scope">
                    <option value="landing">Landing pages</option>
                    <option value="all">All pages</option>
                </select>
            </div>
            <div class="form-group">
                <label for="ai-model">Model</label>
                <select name="model_override" id="ai-model">
                    <option value="">Per-block setting</option>
                    <?php foreach (model_options() as $mid => $mlabel): ?>
                    <option value="<?= h($mid) ?>"><?= h($mlabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="ai-trigger-row" style="margin-bottom:16px; align-items:center; gap:20px;">
            <label id="ai-research-wrap" style="display:flex;align-items:center;gap:6px;font-weight:500;font-size:.83rem;cursor:pointer;margin:0;">
                <input type="checkbox" name="research" value="1" id="ai-research">
                Research cities first
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-weight:500;font-size:.83rem;cursor:pointer;margin:0;">
                <input type="checkbox" name="refresh" value="1">
                Refresh locked blocks
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-weight:500;font-size:.83rem;cursor:pointer;margin:0;">
                <input type="checkbox" name="dry_run" value="1">
                Dry run (no API calls)
            </label>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <button type="submit" class="ai-run-btn" id="ai-run-btn">&#9654; Run</button>
            <div class="ai-spinner" id="ai-spinner"></div>
            <span id="ai-status-text" style="font-size:.82rem;color:#6b7280;"></span>
        </div>
    </form>
    <div id="ai-progress-wrap" style="display:none;margin-top:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <span style="font-size:.8rem;font-weight:600;color:#374151;" id="ai-progress-label">Block 0 of 0</span>
            <span style="font-size:.8rem;color:#6b7280;" id="ai-progress-remain"></span>
        </div>
        <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
            <div id="ai-progress-bar" style="height:100%;width:0%;background:var(--color-accent,#fd783b);border-radius:4px;transition:width .3s ease;"></div>
        </div>
        <div id="ai-workers" style="display:none;margin-top:12px;grid-template-columns:1fr 1fr;gap:6px 16px;"></div>
    </div>
    <div class="ai-console" id="ai-console"></div>
    <div class="ai-summary" id="ai-summary"></div>
    <div class="ai-result-bar" id="ai-result-bar"></div>
    <?php endif; ?>
</div>

<!-- ── City coverage ── -->
<?php if (!empty($cityCoverage)): ?>
<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0; margin-bottom:16px;">City Coverage</h3>
    <table class="ai-table">
        <thead>
            <tr>
                <th>City</th>
                <th>Researched</th>
                <th>Content</th>
                <th>Blocks</th>
                <th>Pages</th>
                <th>Last Generated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cityCoverage as $id => $c): ?>
            <tr>
                <td><strong><?= h($c['city']) ?></strong>, <?= h($c['ss']) ?></td>
                <td>
                    <?php if ($c['researched']): ?>
                        <span class="ai-badge badge-ok">Yes</span>
                    <?php else: ?>
                        <span class="ai-badge badge-none">No</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($c['generated'] > 0): ?>
                        <span class="ai-badge badge-ok">Generated</span>
                    <?php else: ?>
                        <span class="ai-badge badge-warn">Pending</span>
                    <?php endif; ?>
                </td>
                <td><?= $c['generated'] ?: '—' ?></td>
                <td><?= $c['pages'] ?: '—' ?></td>
                <td style="color:#6b7280; font-size:.8rem;"><?= h(fmt_ts_ai($c['last_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Generation log ── -->
<div class="card">
    <h3 style="margin-top:0; margin-bottom:16px;">Generation Log
        <?php if (!empty($logEntries)): ?>
        <span style="font-size:.78rem; font-weight:400; color:#9ca3af;">(<?= count($logEntries) ?> runs, newest first)</span>
        <?php endif; ?>
    </h3>
    <?php if (empty($logEntries)): ?>
    <p class="hint">No runs logged yet. Run generate.py to start generating content.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="ai-table">
        <thead>
            <tr>
                <th>Date / Run</th>
                <th>Scope</th>
                <th>Blocks</th>
                <th>Pages</th>
                <th>Errors</th>
                <th>Tokens In / Out</th>
                <th>Cost</th>
                <th>Duration</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($logEntries, 0, 30) as $e): ?>
            <?php
            $opts    = $e['options'] ?? [];
            $errCnt  = (int)($e['errors'] ?? 0);
            $blkCnt  = (int)($e['blocks_generated'] ?? 0);
            $haserr  = $errCnt > 0;
            $isdry   = !empty($e['dry_run']);

            // Scope label
            $scope = !empty($opts['all']) ? 'all pages' : ($opts['page'] ?? '');
            if (!empty($opts['file'])) $scope .= ($scope ? ' / ' : '') . $opts['file'];
            if (!empty($opts['research'])) $scope .= ' + research';
            if (!$scope) $scope = 'landing';

            // Run status dot
            $dot = $haserr
                ? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-right:5px;vertical-align:middle;" title="Errors"></span>'
                : '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:5px;vertical-align:middle;" title="OK"></span>';
            ?>
            <tr style="<?= $haserr ? 'background:#fff7f7;' : '' ?>">
                <td style="white-space:nowrap; font-size:.78rem;">
                    <?= $dot ?><span style="color:#374151;"><?= h(fmt_ts_ai($e['started_at'] ?? '')) ?></span><br>
                    <span style="color:#9ca3af;font-size:.7rem;padding-left:13px;"><?= h(substr($e['run_id'] ?? '', 0, 8)) ?><?= $isdry ? ' · <em>dry run</em>' : '' ?></span>
                </td>
                <td style="font-size:.8rem; color:#6b7280;"><?= h($scope) ?></td>
                <td>
                    <?php if ($blkCnt > 0): ?>
                    <span style="font-weight:700; color:#166534;"><?= $blkCnt ?></span>
                    <?php else: ?>
                    <span style="color:#d1d5db;">—</span>
                    <?php endif; ?>
                </td>
                <td style="color:#6b7280;">
                    <?php $pw = (int)($e['pages_written'] ?? 0); ?>
                    <?= $pw ?: '<span style="color:#d1d5db;">—</span>' ?>
                    <?php $ps = (int)($e['pages_skipped'] ?? 0); if ($ps): ?>
                    <span style="font-size:.72rem; color:#9ca3af;">+<?= $ps ?> skipped</span>
                    <?php endif; ?>
                </td>
                <td style="color:<?= $haserr ? '#dc2626' : '#9ca3af' ?>; font-weight:<?= $haserr ? '700' : '400' ?>;">
                    <?= $errCnt ?: '<span style="color:#d1d5db;">—</span>' ?>
                </td>
                <td style="font-size:.78rem; color:#6b7280; white-space:nowrap;">
                    <?php
                    $in  = (int)($e['input_tokens']  ?? 0);
                    $out = (int)($e['output_tokens'] ?? 0);
                    echo $in ? number_format($in) . ' / ' . number_format($out) : '<span style="color:#d1d5db;">—</span>';
                    ?>
                </td>
                <td style="font-size:.8rem; white-space:nowrap; font-weight:600;">
                    <?php
                    $cost = (float)($e['estimated_cost_usd'] ?? 0);
                    echo $cost ? '$' . number_format($cost, 4) : '<span style="color:#d1d5db;">—</span>';
                    ?>
                </td>
                <td style="font-size:.78rem; color:#6b7280;"><?= isset($e['duration_ms']) ? fmt_dur((int)$e['duration_ms']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($logEntries) > 30): ?>
    <p class="hint" style="margin-top:10px;">Showing 30 most recent runs of <?= count($logEntries) ?> total.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>

</div>

<?php if ($apiKeyOk): ?>
<script>
(function () {
    var form         = document.getElementById('ai-trigger-form');
    var btn          = document.getElementById('ai-run-btn');
    var spinner      = document.getElementById('ai-spinner');
    var outputEl     = document.getElementById('ai-console');
    var summaryEl    = document.getElementById('ai-summary');
    var resultBar    = document.getElementById('ai-result-bar');
    var statusText   = document.getElementById('ai-status-text');
    var actionSel    = document.getElementById('ai-action');
    var cityWrap     = document.getElementById('ai-city-wrap');
    var progressWrap = document.getElementById('ai-progress-wrap');
    var progressBar  = document.getElementById('ai-progress-bar');
    var progressLbl  = document.getElementById('ai-progress-label');
    var progressRem  = document.getElementById('ai-progress-remain');
    var workersWrap  = document.getElementById('ai-workers');
    var workerBars   = {}; // slot -> { fill, label } DOM refs

    function prettyPage(fname) {
        // tpl_amana_dryer_repair_city_lufkin_tx.json -> "amana dryer repair"
        return (fname || '')
            .replace(/^tpl_/, '').replace(/\.json$/, '')
            .replace(/_city_.*$/, '').replace(/_/g, ' ');
    }
    function buildWorkerRows(n) {
        workerBars = {};
        workersWrap.innerHTML = '';
        for (var i = 0; i < n; i++) {
            var row = document.createElement('div');
            row.innerHTML =
                '<div style="display:flex;justify-content:space-between;font-size:.72rem;color:#6b7280;margin-bottom:2px;">' +
                  '<span style="font-weight:600;color:#374151;">W' + (i + 1) + '</span>' +
                  '<span class="ai-w-lbl" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;">idle</span>' +
                '</div>' +
                '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">' +
                  '<div class="ai-w-fill" style="height:100%;width:0%;background:var(--color-accent,#fd783b);border-radius:3px;transition:width .3s ease;"></div>' +
                '</div>';
            workersWrap.appendChild(row);
            workerBars[i] = { fill: row.querySelector('.ai-w-fill'), label: row.querySelector('.ai-w-lbl') };
        }
        workersWrap.style.display = n > 0 ? 'grid' : 'none';
    }
    var scopeWrap    = document.getElementById('ai-scope-wrap');
    var researchWrap = document.getElementById('ai-research-wrap');

    function updateVisibility() {
        var action = actionSel.value;
        cityWrap.style.display     = action === 'sync' ? 'none' : '';
        scopeWrap.style.display    = action === 'generate' ? '' : 'none';
        researchWrap.style.display = action === 'generate' ? '' : 'none';
    }
    actionSel.addEventListener('change', updateVisibility);
    updateVisibility();

    if (!form) return;

    // Escape HTML for safe injection into innerHTML
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtN(n) { return Number(n).toLocaleString(); }

    function renderSummary(msg) {
        if (!summaryEl) return;
        if (!msg.success) {
            summaryEl.className = 'ai-summary fail';
            summaryEl.innerHTML =
                '<div class="ai-summary-head fail">&#10060; Generation failed</div>' +
                '<div style="font-size:.83rem;color:#7f1d1d;">' + esc(msg.error || ('Process exited with code ' + msg.exit_code)) + '</div>';
            return;
        }
        var log     = msg.last_log || {};
        var blocks  = parseInt(log.blocks_generated  || 0);
        var pages   = parseInt(log.pages_written     || 0);
        var skipped = parseInt(log.pages_skipped     || 0);
        var errors  = parseInt(log.errors            || 0);
        var calls   = parseInt(log.api_calls         || 0);
        var tokIn   = parseInt(log.input_tokens      || 0);
        var tokOut  = parseInt(log.output_tokens     || 0);
        var cost    = parseFloat(log.estimated_cost_usd || 0);
        var dur     = log.duration_ms ? (log.duration_ms / 1000).toFixed(1) + 's' : '';

        var stats = '';
        if (blocks)  stats += '<div class="ai-summary-stat"><span class="val" style="color:#166534;">' + fmtN(blocks) + '</span><span class="lbl">blocks generated</span></div>';
        if (pages)   stats += '<div class="ai-summary-stat"><span class="val">' + pages + '</span><span class="lbl">pages written</span></div>';
        if (skipped) stats += '<div class="ai-summary-stat"><span class="val" style="color:#92400e;">' + skipped + '</span><span class="lbl">pages skipped</span></div>';
        if (errors)  stats += '<div class="ai-summary-stat"><span class="val" style="color:#dc2626;">' + errors + '</span><span class="lbl">errors</span></div>';
        if (dur)     stats += '<div class="ai-summary-stat"><span class="val">' + dur + '</span><span class="lbl">duration</span></div>';

        var meta = '';
        if (calls)  meta += '<span>' + calls + ' API calls</span>';
        if (tokIn)  meta += '<span>' + fmtN(tokIn) + ' in&nbsp;/&nbsp;' + fmtN(tokOut) + ' out tokens</span>';
        if (cost)   meta += '<span style="color:#374151;font-weight:600;">$' + cost.toFixed(4) + ' est. cost</span>';

        summaryEl.className = 'ai-summary ok';
        summaryEl.innerHTML =
            '<div class="ai-summary-head ok">&#10003;&nbsp; Generation complete' + (log.dry_run ? ' <span style="font-weight:400;font-size:.8rem;">(dry run)</span>' : '') + '</div>' +
            '<div class="ai-summary-stats">' + (stats || '<span style="color:#6b7280;">No blocks generated.</span>') + '</div>' +
            (meta ? '<div class="ai-summary-meta">' + meta + '</div>' : '');
    }

    // Parse one NDJSON line and handle it
    var ticker;
    function handleLine(raw) {
        var msg;
        try { msg = JSON.parse(raw); } catch(e) { return; }

        if (msg.type === 'line') {
            outputEl.textContent += msg.text + '\n';
            outputEl.scrollTop = outputEl.scrollHeight;
        } else if (msg.type === 'progress') {
            var done  = msg.done;
            var total = msg.total;
            if (total > 0) {
                progressWrap.style.display = '';
                var pct = Math.round((done / total) * 100);
                progressBar.style.width = pct + '%';
                progressLbl.textContent = 'Block ' + done + ' of ' + total;
                progressRem.textContent = (total - done) + ' remaining';
            }
        } else if (msg.type === 'workers_init') {
            progressWrap.style.display = '';
            buildWorkerRows(msg.count);
        } else if (msg.type === 'worker') {
            var w = workerBars[msg.slot];
            if (w) {
                var wpct = msg.total > 0 ? Math.round((msg.done / msg.total) * 100) : 0;
                w.fill.style.width = wpct + '%';
                w.label.textContent = msg.done + '/' + msg.total + ' · ' + prettyPage(msg.page);
                w.label.title = msg.page;
            }
        } else if (msg.type === 'done') {
            clearInterval(ticker);
            btn.disabled = false;
            spinner.classList.remove('on');
            statusText.textContent = '';
            progressWrap.style.display = 'none';
            workersWrap.style.display = 'none';
            workersWrap.innerHTML = '';

            renderSummary(msg);

            if (msg.success) {
                resultBar.className   = 'ai-result-bar ok';
                resultBar.textContent = 'Done — page will reload in a moment…';
                setTimeout(function () { location.reload(); }, 3000);
            } else {
                resultBar.className   = 'ai-result-bar fail';
                resultBar.textContent = msg.error || ('Exited with code ' + msg.exit_code);
            }
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Build confirmation summary
        var action   = actionSel.value;
        var cityEl   = document.getElementById('ai-city');
        var scopeEl  = document.getElementById('ai-scope');
        var research = document.getElementById('ai-research');
        var refresh  = document.querySelector('[name="refresh"]');
        var dryRun   = document.querySelector('[name="dry_run"]');

        var actionLabels = { generate: 'Generate content', research: 'Research only', sync: 'Sync templates' };
        var lines = [];
        lines.push('Action:  ' + (actionLabels[action] || action));
        if (action !== 'sync') {
            lines.push('City:    ' + (cityEl && cityEl.value ? cityEl.options[cityEl.selectedIndex].text : 'All cities'));
        }
        if (action === 'generate') {
            lines.push('Scope:   ' + (scopeEl ? scopeEl.options[scopeEl.selectedIndex].text : ''));
            if (research && research.checked) lines.push('         + Research cities first');
            if (refresh  && refresh.checked)  lines.push('         + Refresh locked blocks');
        }
        if (dryRun && dryRun.checked) lines.push('         DRY RUN — no API calls');

        if (!confirm('Run generator?\n\n' + lines.join('\n'))) return;

        // Reset UI
        btn.disabled = true;
        spinner.classList.add('on');
        resultBar.className   = 'ai-result-bar';
        resultBar.textContent = '';
        summaryEl.className   = 'ai-summary';
        summaryEl.innerHTML   = '';
        outputEl.textContent  = '';
        outputEl.classList.add('open');
        statusText.textContent = 'Starting…';
        progressWrap.style.display = 'none';
        progressBar.style.width    = '0%';
        progressLbl.textContent    = 'Block 0 of 0';
        progressRem.textContent    = '';
        workersWrap.style.display  = 'none';
        workersWrap.innerHTML      = '';
        workerBars                 = {};

        var startedAt = Date.now();
        clearInterval(ticker);
        ticker = setInterval(function () {
            statusText.textContent = 'Running… ' + ((Date.now() - startedAt) / 1000).toFixed(0) + 's';
        }, 1000);

        // Stream the NDJSON response
        fetch('ai_generate.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
        })
        .then(function (r) {
            var reader  = r.body.getReader();
            var decoder = new TextDecoder();
            var buffer  = '';

            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        if (buffer.trim()) handleLine(buffer.trim());
                        return;
                    }
                    buffer += decoder.decode(chunk.value, { stream: true });
                    var parts = buffer.split('\n');
                    buffer = parts.pop(); // hold incomplete last line
                    parts.forEach(function (ln) { if (ln.trim()) handleLine(ln.trim()); });
                    return pump();
                });
            }
            return pump();
        })
        .catch(function (err) {
            clearInterval(ticker);
            btn.disabled = false;
            spinner.classList.remove('on');
            statusText.textContent = '';
            resultBar.className   = 'ai-result-bar fail';
            resultBar.textContent = 'Request failed: ' + err.message;
        });
    });
})();
</script>
<?php endif; ?>

<script>
window.aiKeySave = function () {
    var input  = document.getElementById('ai-key-input');
    var status = document.getElementById('ai-key-status');
    var key    = input.value.trim();

    // Don't re-save the masked placeholder
    if (key === '••••••••••••••••••••••••') { status.textContent = 'No change.'; return; }

    status.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
    fd.append('api_key', key);

    fetch('ai_key_save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.success) {
            status.textContent = 'Saved — reloading…';
            setTimeout(function () { location.reload(); }, 800);
        } else {
            status.textContent = res.error || 'Save failed.';
            status.style.color = '#dc2626';
        }
    })
    .catch(function (err) {
        status.textContent = 'Request failed: ' + err.message;
        status.style.color = '#dc2626';
    });
};
</script>
