<?php
// City Pages tab — template × city status matrix + generation controls + log
// $tab, $templates, $cities, $csrfToken available from index.php

// ── Status computation ────────────────────────────────────────────────────────
// Returns ['generated'|'stale'|'missing', generated_at|null]

function _cp_page_status(array $tpl, array $city, string $tplVersion): array {
    $filename = PAGES_DIR . $tpl['id'] . '_' . $city['id'] . '.json';
    if (!file_exists($filename)) return ['missing', null];
    $raw = json_decode(file_get_contents($filename), true);
    if (!is_array($raw)) return ['missing', null];
    $pageVersion  = $raw['template_version'] ?? '';
    $generatedAt  = $raw['generated_at']     ?? null;
    $status = ($pageVersion === $tplVersion) ? 'generated' : 'stale';
    return [$status, $generatedAt];
}

function _cp_tpl_version(array $tpl): string {
    return md5(json_encode($tpl['content_blocks'] ?? []) . json_encode($tpl['seo'] ?? []) . ($tpl['slug_pattern'] ?? ''));
}

// Pre-compute all tags for the filter bar
$allCityTags = [];
foreach ($cities as $c) {
    foreach ($c['tags'] ?? [] as $t) { $allCityTags[$t] = true; }
}
ksort($allCityTags);

// Load recent generation log (last 10 runs)
$genLog = [];
if (file_exists(GEN_LOG_FILE)) {
    $raw = json_decode(file_get_contents(GEN_LOG_FILE), true);
    $genLog = is_array($raw) ? array_reverse(array_slice($raw, -10)) : [];
}
?>
<div class="tab-content" style="<?= $tab === 'citypages' ? '' : 'display:none;' ?>">

<?php if (empty($templates) && empty($cities)): ?>
    <div class="card">
        <p class="hint">No templates or cities yet. Add templates in the <a href="?tab=templates">Templates tab</a> and cities in the <a href="?tab=cities">Cities tab</a> first.</p>
    </div>
<?php elseif (empty($templates)): ?>
    <div class="card"><p class="hint">No templates yet. <a href="?tab=templates">Add a template</a> to get started.</p></div>
<?php elseif (empty($cities)): ?>
    <div class="card"><p class="hint">No cities yet. <a href="?tab=cities">Add cities</a> to get started.</p></div>
<?php else: ?>

    <!-- ── Generation result banner (shown after AJAX completes) ──────────── -->
    <div id="gen-result" style="display:none;" class="alert"></div>

    <!-- ── Controls ──────────────────────────────────────────────────────── -->
    <div class="card" style="padding:16px 20px;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn" onclick="cpGenerate({})">&#9654; Generate All</button>
            <button class="btn btn-secondary" onclick="cpGenerate({dry_run:1})">Dry Run</button>

            <?php if (!empty($allCityTags)): ?>
            <span class="hint" style="margin:0 4px;">Tag filter:</span>
            <button class="btn btn-secondary btn-small cp-tag-btn active-tag" data-tag="" onclick="cpSetTag(this,'')">All</button>
            <?php foreach ($allCityTags as $tag => $_): ?>
            <button class="btn btn-secondary btn-small cp-tag-btn" data-tag="<?= h($tag) ?>" onclick='cpSetTag(this,<?= json_encode($tag) ?>)'><?= h($tag) ?></button>
            <?php endforeach; ?>
            <?php endif; ?>

            <span id="gen-spinner" style="display:none;margin-left:8px;">
                <span style="color:#6b7280;font-size:13px;">&#9696; Generating…</span>
            </span>
        </div>
    </div>

    <!-- ── Per-template status cards ─────────────────────────────────────── -->
    <?php foreach ($templates as $tpl):
        $tplVersion  = _cp_tpl_version($tpl);
        $countTotal  = count($cities);
        $countOk     = 0; $countStale = 0; $countMissing = 0;

        // Pre-compute all statuses for this template
        $cityStatuses = [];
        foreach ($cities as $city) {
            [$st, $genAt] = _cp_page_status($tpl, $city, $tplVersion);
            $cityStatuses[$city['id']] = ['status' => $st, 'generated_at' => $genAt, 'tags' => $city['tags'] ?? []];
            if ($st === 'generated') $countOk++;
            elseif ($st === 'stale')   $countStale++;
            else                       $countMissing++;
        }
    ?>
    <div class="card cp-template-card" data-template-id="<?= h($tpl['id']) ?>">
        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px;">
            <div style="flex:1;min-width:200px;">
                <strong style="font-size:1.05em;"><?= h($tpl['title'] ?: $tpl['id']) ?></strong><br>
                <span class="hint">
                    Slug: <code><?= h($tpl['slug_pattern'] ?? '') ?></code>
                    &mdash; <?= $countOk ?> generated
                    <?php if ($countStale > 0): ?>, <span style="color:#b45309;"><?= $countStale ?> stale</span><?php endif; ?>
                    <?php if ($countMissing > 0): ?>, <span style="color:#dc2626;"><?= $countMissing ?> missing</span><?php endif; ?>
                    &mdash; <?= $countTotal ?> cities total
                </span>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <button class="btn btn-small"
                    onclick='cpGenerate({template_ids:<?= json_encode([$tpl['id']]) ?>})'>
                    &#9654; Regen Template
                </button>
                <button class="btn btn-secondary btn-small"
                    onclick='cpGenerate({template_ids:<?= json_encode([$tpl['id']]) ?>,force_locked:1})'
                    title="Regenerate including locked blocks">
                    Force Regen
                </button>
            </div>
        </div>

        <!-- City status grid -->
        <div class="cp-city-grid">
            <?php foreach ($cities as $city):
                $st    = $cityStatuses[$city['id']]['status'];
                $genAt = $cityStatuses[$city['id']]['generated_at'];
                $tags  = $cityStatuses[$city['id']]['tags'];

                $icon  = $st === 'generated' ? '✅' : ($st === 'stale' ? '⚠️' : '❌');
                $label = $st === 'generated' ? 'Generated' : ($st === 'stale' ? 'Stale' : 'Missing');
                $color = $st === 'generated' ? '#166534' : ($st === 'stale' ? '#92400e' : '#991b1b');
                $bg    = $st === 'generated' ? '#f0fdf4'  : ($st === 'stale' ? '#fffbeb' : '#fef2f2');

                $dateLabel = '';
                if ($genAt) {
                    try { $dateLabel = (new DateTime($genAt))->format('M j'); } catch(Exception $e) {}
                }
            ?>
            <?php
                $previewSlug = '';
                if ($st !== 'missing') {
                    $pattern = $tpl['slug_pattern'] ?? '';
                    $vars = [
                        '{city}'      => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $city['city'] ?? '')),
                        '{SS}'        => strtolower($city['SS'] ?? ''),
                        '{state}'     => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $city['state'] ?? '')),
                        '{city_slug}' => $city['city_slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', ($city['city'] ?? '') . '-' . ($city['SS'] ?? ''))),
                        '{zip}'       => $city['zip'] ?? '',
                    ];
                    $previewSlug = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower(str_replace(array_keys($vars), array_values($vars), $pattern))), '-');
                }
            ?>
            <div class="cp-city-cell" data-tags="<?= h(json_encode($tags)) ?>"
                 style="background:<?= $bg ?>;border:1px solid <?= $st === 'generated' ? '#bbf7d0' : ($st === 'stale' ? '#fde68a' : '#fecaca') ?>;border-radius:6px;padding:8px 10px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:4px;">
                    <div style="min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:#1f2937;">
                            <?= $icon ?> <?= h($city['city']) ?>, <?= h($city['SS']) ?>
                        </div>
                        <div style="font-size:11px;color:<?= $color ?>;margin-top:1px;">
                            <?= $label ?><?= $dateLabel ? ' · ' . $dateLabel : '' ?>
                        </div>
                        <?php if ($previewSlug): ?>
                        <a href="../page.php?slug=<?= h($previewSlug) ?>" target="_blank"
                           style="font-size:11px;color:#2563eb;text-decoration:none;display:inline-block;margin-top:3px;"
                           title="/<?= h($previewSlug) ?>">Preview ↗</a>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-secondary btn-small"
                        style="font-size:11px;padding:2px 7px;flex-shrink:0;"
                        onclick='cpGenerate({template_ids:<?= json_encode([$tpl['id']]) ?>,city_ids:<?= json_encode([$city['id']]) ?>})'
                        title="Regenerate this page">↺</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ── Generation log ────────────────────────────────────────────────── -->
    <div class="card">
        <h2>Recent Generation Runs</h2>
        <?php if (empty($genLog)): ?>
            <p class="hint">No runs yet.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid #e5e7eb;text-align:left;">
                    <th style="padding:6px 10px;">Started</th>
                    <th style="padding:6px 10px;">Written</th>
                    <th style="padding:6px 10px;">Backed Up</th>
                    <th style="padding:6px 10px;">Errors</th>
                    <th style="padding:6px 10px;">Duration</th>
                    <th style="padding:6px 10px;">Options</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($genLog as $run):
                $hasErrors = !empty($run['errors']);
                $isDry     = !empty($run['dry_run']);
            ?>
                <tr style="border-bottom:1px solid #f3f4f6;<?= $hasErrors ? 'background:#fef2f2;' : '' ?>">
                    <td style="padding:6px 10px;color:#374151;">
                        <?= h($run['started_at'] ?? '') ?>
                        <?php if ($isDry): ?><span style="font-size:11px;color:#6b7280;margin-left:4px;">(dry)</span><?php endif; ?>
                    </td>
                    <td style="padding:6px 10px;"><?= (int)($run['pages_written'] ?? 0) ?></td>
                    <td style="padding:6px 10px;"><?= (int)($run['pages_backed_up'] ?? 0) ?></td>
                    <td style="padding:6px 10px;color:<?= $hasErrors ? '#dc2626' : '#166534' ?>;">
                        <?= count($run['errors'] ?? []) ?>
                        <?php if ($hasErrors): ?>
                            <span title="<?= h(json_encode($run['errors'], JSON_PRETTY_PRINT)) ?>">&#9432;</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:6px 10px;"><?= number_format(($run['duration_ms'] ?? 0) / 1000, 2) ?>s</td>
                    <td style="padding:6px 10px;color:#6b7280;font-size:11px;">
                        <?php
                        $opts = $run['options'] ?? [];
                        $parts = [];
                        if (!empty($opts['tag_filter']))   $parts[] = 'tag:' . $opts['tag_filter'];
                        if (!empty($opts['template_ids'])) $parts[] = 'tpl:' . implode(',', $opts['template_ids']);
                        if (!empty($opts['city_ids']))     $parts[] = count($opts['city_ids']) . ' cities';
                        if (!empty($opts['force_locked'])) $parts[] = 'force';
                        echo $parts ? h(implode(' · ', $parts)) : 'all';
                        ?>
                        <span style="color:#9ca3af;margin-left:6px;"><?= h(substr($run['run_id'] ?? '', 0, 8)) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php endif; ?>
</div>

<style>
.cp-city-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 8px;
}
.cp-tag-btn.active-tag {
    background: var(--color-accent, #c8960a);
    color: #fff;
    border-color: var(--color-accent, #c8960a);
}
.cp-city-cell[data-hidden="1"] { display: none; }
</style>

<script>
(function() {
    let _activeTag = '';

    window.cpSetTag = function(btn, tag) {
        _activeTag = tag;
        document.querySelectorAll('.cp-tag-btn').forEach(b => b.classList.remove('active-tag'));
        btn.classList.add('active-tag');

        document.querySelectorAll('.cp-city-cell').forEach(cell => {
            if (tag === '') {
                cell.removeAttribute('data-hidden');
            } else {
                const tags = JSON.parse(cell.dataset.tags || '[]');
                cell.dataset.hidden = tags.includes(tag) ? '0' : '1';
                if (tags.includes(tag)) cell.removeAttribute('data-hidden');
                else cell.setAttribute('data-hidden', '1');
            }
        });
    };

    window.cpGenerate = async function(opts) {
        const spinner = document.getElementById('gen-spinner');
        const result  = document.getElementById('gen-result');

        result.style.display = 'none';
        spinner.style.display = 'inline';

        // Apply active tag filter to all generate calls unless overridden
        if (_activeTag && !opts.tag_filter) opts = Object.assign({ tag_filter: _activeTag }, opts);

        const fd = new FormData();
        fd.append('csrf_token', <?= json_encode($csrfToken) ?>);

        Object.entries(opts).forEach(([k, v]) => {
            if (Array.isArray(v)) v.forEach(i => fd.append(k + '[]', i));
            else if (v !== undefined && v !== null) fd.append(k, v);
        });

        try {
            const res  = await fetch('generate.php', { method: 'POST', body: fd });
            const data = await res.json();
            spinner.style.display = 'none';

            // Cost warning — ask user to confirm then re-run
            if (data.cost_warning) {
                const msg = data.message + '\n\nSteps: ' + (data.cost_steps || []).join(', ') + '\nEstimated pages: ' + data.estimated_pages;
                if (!confirm(msg)) return;
                opts.confirmed_cost = 1;
                return cpGenerate(opts);
            }

            if (!data.success) {
                result.className = 'alert alert-error';
                result.textContent = data.message || 'Generation failed.';
                result.style.display = 'block';
                return;
            }

            const dryLabel  = data.dry_run ? ' (dry run)' : '';
            const errLabel  = data.errors && data.errors.length > 0 ? ` · ${data.errors.length} error(s)` : '';
            const timeLabel = data.duration_ms ? ` · ${(data.duration_ms / 1000).toFixed(2)}s` : '';
            result.className = data.errors && data.errors.length > 0 ? 'alert alert-error' : 'alert alert-success';
            result.textContent = `${data.dry_run ? 'Dry run' : 'Generated'}: ${data.pages_written} pages, ${data.pages_backed_up} backed up${errLabel}${timeLabel}${dryLabel}`;
            result.style.display = 'block';

            // Reload to refresh status cells (skip on dry run)
            if (!data.dry_run) {
                setTimeout(() => window.location.reload(), 1200);
            }

        } catch (e) {
            spinner.style.display = 'none';
            result.className = 'alert alert-error';
            result.textContent = 'Request failed: ' + e.message;
            result.style.display = 'block';
        }
    };
})();
</script>
