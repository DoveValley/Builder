<?php
// AI Content Review tab — browse generated blocks by city, lock/unlock individual blocks.
// $tab, $csrfToken, $cities, ACTIVE_SITE_DIR, ACTIVE_SITE_ID available from index.php.

// ── Load AI block registry for type labels ────────────────────────────────────
$aiRegistry = [];
if (file_exists(AI_REGISTRY_FILE)) {
    $raw = json_decode(file_get_contents(AI_REGISTRY_FILE), true);
    if (is_array($raw)) $aiRegistry = $raw;
}

// ── City map ──────────────────────────────────────────────────────────────────
$cityMap = [];
foreach ($cities as $c) {
    if (!empty($c['id'])) $cityMap[$c['id']] = $c;
}

// ── Scan page files, group ai_blocks by city (only when tab is active) ───────
$reviewData = [];  // city_id => [city, ss, total_blocks, pages: [...]]

$pageIndex = [];
if ($tab === 'ai_review' && file_exists(PAGE_INDEX_FILE)) {
    $raw = json_decode(file_get_contents(PAGE_INDEX_FILE), true);
    if (is_array($raw)) $pageIndex = $raw;
}

foreach ($pageIndex as $slug => $fileName) {
    $filePath = PAGES_DIR . basename($fileName);
    if (!file_exists($filePath)) continue;

    $page = json_decode(file_get_contents($filePath), true);
    if (!is_array($page)) continue;

    $cityId = $page['city_id'] ?? '';
    if (!$cityId) continue;

    if (!isset($reviewData[$cityId])) {
        $c = $cityMap[$cityId] ?? [];
        $reviewData[$cityId] = [
            'city'         => $c['city'] ?? $cityId,
            'ss'           => $c['SS']   ?? '',
            'total_blocks' => 0,
            'pages'        => [],
        ];
    }

    // Human-readable template label
    $tplId    = $page['template_id'] ?? '';
    $tplLabel = preg_replace('/^tpl_/', '', $tplId);
    $tplLabel = preg_replace('/_city$/', '', $tplLabel);
    $tplLabel = ucwords(str_replace('_', ' ', $tplLabel));

    // Collect standalone ai_blocks from this page
    $aiBlocks = [];
    foreach ($page['content_blocks'] ?? [] as $idx => $block) {
        if ($block['type'] !== 'ai_block' || empty($block['_ai_generated'])) continue;
        $aiType   = $block['_ai_type'] ?? '';
        $aiBlocks[] = [
            'block_index'     => $idx,
            'ai_type'         => $aiType,
            'ai_type_label'   => $aiRegistry[$aiType]['label'] ?? ucwords(str_replace('_', ' ', $aiType)),
            'heading_text'    => $block['heading_text'] ?? '',
            'text_snippet'    => strip_tags($block['text'] ?? ''),
            'ai_model'        => $block['_ai_model'] ?? '',
            'ai_generated_at' => $block['_ai_generated_at'] ?? '',
            'ai_locked'       => !empty($block['_ai_locked']),
        ];
    }

    $reviewData[$cityId]['pages'][] = [
        'file'      => $fileName,
        'slug'      => $slug,
        'label'     => $tplLabel,
        'ai_blocks' => $aiBlocks,
    ];
    $reviewData[$cityId]['total_blocks'] += count($aiBlocks);
}

// Sort by city name
uasort($reviewData, fn($a, $b) => strcmp($a['city'], $b['city']));

// Selected city
$selectedCityId = $_GET['review_city'] ?? (array_key_first($reviewData) ?? '');
if (!isset($reviewData[$selectedCityId])) {
    $selectedCityId = array_key_first($reviewData) ?? '';
}

function _review_model_short(string $model): string {
    if (str_contains($model, 'haiku'))  return 'Haiku';
    if (str_contains($model, 'sonnet')) return 'Sonnet';
    if (str_contains($model, 'opus'))   return 'Opus';
    return $model ?: '—';
}
function _review_ts(string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('M j, Y', $ts) : '—';
}
?>

<div class="tab-content" style="<?= $tab === 'ai_review' ? '' : 'display:none;' ?>">

<h2 style="margin-bottom:6px;">Content Review</h2>
<p class="hint" style="margin-bottom:20px;">Review AI-generated blocks across city landing pages. Lock a block to protect it from being overwritten on the next generation run.</p>

<?php if (empty($reviewData)): ?>
<div class="card">
    <p class="hint">No AI-generated content found. Go to <a href="?tab=ai">&#127916; AI Generation</a> to run the generator.</p>
</div>
<?php else: ?>

<!-- ── City selector ─────────────────────────────────────────────────────── -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;align-items:center;">
    <span class="hint" style="margin:0;">City:</span>
    <?php foreach ($reviewData as $cId => $cd): ?>
    <a href="?tab=ai_review&review_city=<?= h(urlencode($cId)) ?>"
       style="display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:20px;font-size:.82rem;font-weight:600;text-decoration:none;
              <?= $cId === $selectedCityId
                  ? 'background:var(--color-accent,#fd783b);color:#fff;'
                  : 'background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;' ?>">
        <?= h($cd['city']) ?>
        <?php if ($cd['total_blocks'] > 0): ?>
        <span style="background:rgba(0,0,0,.12);border-radius:10px;padding:0 6px;font-size:.7rem;"><?= $cd['total_blocks'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($selectedCityId && isset($reviewData[$selectedCityId])): ?>
<?php
$cd   = $reviewData[$selectedCityId];
$city = $cityMap[$selectedCityId] ?? [];
$isResearched = !empty($city['industries']) || !empty($city['top_employers']);
?>

<!-- ── City header ───────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
    <h3 style="margin:0;"><?= h($cd['city']) ?>, <?= h($cd['ss']) ?></h3>
    <?php if ($isResearched): ?>
    <span style="background:#d1fae5;color:#065f46;font-size:11px;padding:2px 8px;border-radius:4px;font-weight:600;">Researched</span>
    <?php else: ?>
    <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:4px;font-weight:600;">No research</span>
    <?php endif; ?>
    <span class="hint" style="margin:0;"><?= $cd['total_blocks'] ?> AI block<?= $cd['total_blocks'] !== 1 ? 's' : '' ?> across <?= count($cd['pages']) ?> pages</span>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <button class="ai-run-btn" id="review-regen-btn" style="font-size:.78rem;padding:6px 14px;"
                onclick="reviewRegen(<?= h(json_encode($selectedCityId)) ?>)">&#9654; Regenerate</button>
        <div class="ai-spinner" id="review-spinner"></div>
    </div>
</div>

<div class="ai-result-bar" id="review-result-bar"></div>
<div class="ai-console" id="review-console" style="margin-bottom:20px;"></div>

<!-- ── Pages ─────────────────────────────────────────────────────────────── -->
<?php foreach ($cd['pages'] as $pd): ?>
<div class="card" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:<?= empty($pd['ai_blocks']) ? '0' : '12px' ?>;">
        <strong style="font-size:.9rem;"><?= h($pd['label']) ?></strong>
        <span class="hint" style="margin:0;font-size:.77rem;"><?= h($pd['slug']) ?></span>
        <a href="../page.php?slug=<?= h($pd['slug']) ?>" target="_blank"
           style="margin-left:auto;font-size:.77rem;color:#9ca3af;text-decoration:none;" title="View page">View ↗</a>
    </div>

    <?php if (empty($pd['ai_blocks'])): ?>
    <p class="hint" style="margin:0;">No standalone AI blocks generated yet.</p>
    <?php else: ?>
    <?php foreach ($pd['ai_blocks'] as $ab): ?>
    <div class="review-block" data-file="<?= h($pd['file']) ?>" data-idx="<?= (int)$ab['block_index'] ?>"
         style="border:1px solid #e5e7eb;border-radius:8px;padding:11px 14px;margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:6px;flex-wrap:wrap;">
            <span style="font-size:.72rem;font-weight:700;background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:4px;"><?= h($ab['ai_type_label']) ?></span>
            <span style="font-size:.72rem;color:#9ca3af;"><?= h(_review_model_short($ab['ai_model'])) ?></span>
            <span style="font-size:.72rem;color:#9ca3af;"><?= h(_review_ts($ab['ai_generated_at'])) ?></span>
            <span class="lock-badge" style="font-size:.7rem;font-weight:600;padding:2px 7px;border-radius:4px;
                  <?= $ab['ai_locked']
                      ? 'background:#d1fae5;color:#065f46;'
                      : 'background:#fef3c7;color:#92400e;' ?>">
                <?= $ab['ai_locked'] ? '&#128274; Locked' : 'Unlocked' ?>
            </span>
            <button class="btn btn-secondary btn-small lock-toggle-btn"
                    style="margin-left:auto;padding:3px 10px;font-size:.73rem;"
                    data-locked="<?= $ab['ai_locked'] ? '1' : '0' ?>"
                    onclick="reviewToggleLock(this)">
                <?= $ab['ai_locked'] ? 'Unlock' : 'Lock' ?>
            </button>
        </div>
        <?php if ($ab['heading_text']): ?>
        <div style="font-weight:600;font-size:.85rem;margin-bottom:3px;color:#111;"><?= h($ab['heading_text']) ?></div>
        <?php endif; ?>
        <?php if ($ab['text_snippet']): ?>
        <div style="font-size:.79rem;color:#6b7280;line-height:1.55;"><?= h(mb_substr($ab['text_snippet'], 0, 160)) ?><?= mb_strlen($ab['text_snippet']) > 160 ? '…' : '' ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; // selectedCityId ?>
<?php endif; // empty reviewData ?>

</div>

<script>
(function () {
    var _csrf = <?= json_encode($csrfToken) ?>;

    window.reviewToggleLock = function (btn) {
        var block  = btn.closest('.review-block');
        var file   = block.dataset.file;
        var idx    = block.dataset.idx;
        var badge  = block.querySelector('.lock-badge');
        var locked = btn.dataset.locked === '1';
        var newAction = locked ? 'unlock' : 'lock';

        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', _csrf);
        fd.append('action', newAction);
        fd.append('page_file', file);
        fd.append('block_index', idx);

        fetch('ai_block_save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            if (!res.success) { alert(res.error || 'Could not update lock.'); return; }
            if (res.locked) {
                badge.style.cssText = 'font-size:.7rem;font-weight:600;padding:2px 7px;border-radius:4px;background:#d1fae5;color:#065f46;';
                badge.innerHTML = '&#128274; Locked';
                btn.textContent = 'Unlock';
                btn.dataset.locked = '1';
            } else {
                badge.style.cssText = 'font-size:.7rem;font-weight:600;padding:2px 7px;border-radius:4px;background:#fef3c7;color:#92400e;';
                badge.textContent = 'Unlocked';
                btn.textContent = 'Lock';
                btn.dataset.locked = '0';
            }
        })
        .catch(function () { btn.disabled = false; alert('Request failed.'); });
    };

    window.reviewRegen = function (cityId) {
        var btn       = document.getElementById('review-regen-btn');
        var spinner   = document.getElementById('review-spinner');
        var resultBar = document.getElementById('review-result-bar');
        var outputEl  = document.getElementById('review-console');

        btn.disabled = true;
        spinner.classList.add('on');
        resultBar.className = 'ai-result-bar';
        resultBar.textContent = '';
        outputEl.textContent = '';
        outputEl.classList.add('open');

        var startedAt = Date.now();
        var ticker = setInterval(function () {
            var s = ((Date.now() - startedAt) / 1000).toFixed(0);
            btn.textContent = '▶ Running… ' + s + 's';
        }, 1000);

        var fd = new FormData();
        fd.append('csrf_token', _csrf);
        fd.append('action', 'generate');
        fd.append('city_id', cityId);
        fd.append('scope', 'landing');

        fetch('ai_generate.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            clearInterval(ticker);
            btn.disabled = false;
            btn.textContent = '▶ Regenerate';
            spinner.classList.remove('on');
            outputEl.textContent = res.output || '(no output)';
            outputEl.scrollTop = outputEl.scrollHeight;
            if (res.success) {
                resultBar.textContent = 'Done. Reloading…';
                resultBar.className = 'ai-result-bar ok';
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                resultBar.textContent = res.error || ('Exited with code ' + res.exit_code);
                resultBar.className = 'ai-result-bar fail';
            }
        })
        .catch(function (err) {
            clearInterval(ticker);
            btn.disabled = false;
            btn.textContent = '▶ Regenerate';
            spinner.classList.remove('on');
            resultBar.textContent = 'Request failed: ' + err.message;
            resultBar.className = 'ai-result-bar fail';
        });
    };
})();
</script>
