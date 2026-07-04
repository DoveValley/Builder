<?php
// City Pages tab — template × city status matrix + generation controls + log
// $tab, $templates, $cities, $csrfToken available from index.php

require_once __DIR__ . '/../../includes/generation/engine.php';

// ── Status computation ────────────────────────────────────────────────────────
// Returns ['generated'|'stale'|'missing', generated_at|null]

function _cp_page_status(array $tpl, array $city, string $tplVersion): array {
    $filename = PAGES_DIR . $tpl['id'] . '_' . $city['id'] . '.json';
    if (!file_exists($filename)) return ['missing', null, null];
    $raw = json_decode(file_get_contents($filename), true);
    if (!is_array($raw)) return ['missing', null, null];
    $pageVersion  = $raw['template_version'] ?? '';
    $generatedAt  = $raw['generated_at']     ?? null;
    $status = ($pageVersion === $tplVersion) ? 'generated' : 'stale';
    $aiAt = null;
    foreach ($raw['content_blocks'] ?? [] as $block) {
        $ts = $block['_ai_generated_at'] ?? null;
        if ($ts && (!$aiAt || $ts > $aiAt)) $aiAt = $ts;
    }
    return [$status, $generatedAt, $aiAt];
}

// Pre-compute all tags for the filter bar
$allCityTags = [];
foreach ($cities as $c) {
    foreach ($c['tags'] ?? [] as $t) { $allCityTags[$t] = true; }
}
ksort($allCityTags);

// Load recent structure generation log (last 20 runs)
$structureLog = [];
if (file_exists(STRUCTURE_LOG_FILE)) {
    $raw = json_decode(file_get_contents(STRUCTURE_LOG_FILE), true);
    $structureLog = is_array($raw) ? array_reverse(array_slice($raw, -20)) : [];
}

// Load recent AI generation log (last 20 runs)
$aiGenLog = [];
if (file_exists(GEN_LOG_FILE)) {
    $raw = json_decode(file_get_contents(GEN_LOG_FILE), true);
    $aiGenLog = is_array($raw) ? array_reverse(array_slice($raw, -20)) : [];
}

// Template name lookup for JS confirmation dialog
$_tplNames = [];
foreach ($templates as $_t) $_tplNames[$_t['id']] = $_t['title'] ?: $_t['id'];
$_cityNames = [];
foreach ($cities as $_c) $_cityNames[$_c['id']] = ($_c['city'] ?? '') . ', ' . ($_c['SS'] ?? '');
?>
<div class="tab-content" style="<?= $tab === 'citypages' ? '' : 'display:none;' ?>">
<?php tab_header('Landing City Pages', 'View the generated status of every template × city combination. Run structure generation and AI generation from this tab.', 'tab-citypages'); ?>

<details class="card" open style="background:#f8fafc;border-left:3px solid #2563eb;">
    <summary style="cursor:pointer;font-weight:700;font-size:1.02rem;color:#1e3a5f;">How city-page generation works</summary>
    <div style="margin-top:14px;font-size:.9rem;line-height:1.6;color:#334155;">
        <p style="margin:0 0 6px;"><strong>Set up — once, for this site (governs every city page)</strong></p>
        <ul style="margin:0 0 14px 18px;padding:0;">
            <li><a href="?tab=templates">Landing Templates</a> — the reusable page skeleton(s): blocks + <code>{city}</code>/<code>{business}</code> shortcodes + AI archetype blocks</li>
            <li><a href="?tab=cities">Landing Cities</a> — the cities to generate for (name, state, slug…)</li>
            <li><a href="?tab=niche_brief">Niche Brief</a> + <a href="?tab=seo">Keywords</a> — AI vocabulary + guardrails + the per-page keyword the AI blocks target</li>
            <li><em>Optional</em> — <a href="playground.php#hero-overlay" target="_blank">hero style</a> in the Test Lab, if you turn on per-city image overlays below</li>
        </ul>
        <p style="margin:0 0 6px;"><strong>Then, for each template × city (Generate below):</strong></p>
        <ol style="margin:0 0 14px 18px;padding:0;">
            <li>Build the page from the template</li>
            <li>Fill shortcodes (<code>{city}</code>, <code>{state}</code>, <code>{business}</code>…) + generate the AI content for that city</li>
            <li><em>Opt-in</em> — per-city images (hero text overlay / unique photos)</li>
            <li><em>Opt-in</em> — vary the block order per city</li>
            <li>Auto-add FAQ + breadcrumb schema</li>
            <li>Write the page at its city slug (from the template's slug pattern)</li>
        </ol>
        <p style="margin:0;"><strong>Finish</strong> — each template shows per-city status (Generated / Stale / Missing); review, then re-Generate to refresh changed pages.</p>
    </div>
</details>

<?php if (empty($templates) && empty($cities)): ?>
    <div class="card">
        <p class="hint">No templates or cities yet. Add templates in the <a href="?tab=templates">Templates tab</a> and cities in the <a href="?tab=cities">Cities tab</a> first.</p>
    </div>
<?php elseif (empty($templates)): ?>
    <div class="card"><p class="hint">No templates yet. <a href="?tab=templates">Add a template</a> to get started.</p></div>
<?php elseif (empty($cities)): ?>
    <div class="card"><p class="hint">No cities yet. <a href="?tab=cities">Add cities</a> to get started.</p></div>
<?php else: ?>

    <!-- ── Generation result / summary (shown after AJAX completes) ──────── -->
    <div id="gen-result" style="display:none;" class="alert"></div>
    <div id="gen-summary" style="display:none; margin-bottom:16px;"></div>

    <!-- ── Controls ──────────────────────────────────────────────────────── -->
    <div class="card" style="padding:16px 20px;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn" onclick="cpGenerate({})">&#9654; Generate All</button>
            <button class="btn btn-secondary" onclick="cpGenerate({dry_run:1})">Dry Run</button>

            <?php if (!empty($allCityTags)): ?>
            <span class="hint" style="margin:0 4px;">Tag filter:</span>
            <button class="btn btn-secondary btn-small cp-tag-btn active-tag" data-tag="" onclick="cpSetTag(this,'')">All</button>
            <?php foreach ($allCityTags as $tag => $_): ?>
            <button class="btn btn-secondary btn-small cp-tag-btn" data-tag="<?= h($tag) ?>" onclick='cpSetTag(this,<?= h(json_encode($tag)) ?>)'><?= h($tag) ?></button>
            <?php endforeach; ?>
            <?php endif; ?>

            <span id="gen-spinner" style="display:none;margin-left:8px;">
                <span style="color:#6b7280;font-size:13px;">&#9696; Generating…</span>
            </span>
        </div>

        <!-- ── Per-city image differentiation ─────────────────────────────── -->
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #e5e7eb;">
            <label class="hint" for="cp-imgdiff" style="margin:0;font-weight:600;">Per-city images:</label>
            <select id="cp-imgdiff" style="padding:5px 8px;">
                <option value="">Off — city pages share the template's images</option>
                <option value="hero">Hero text overlay — bake keyword + city onto the hero</option>
                <option value="full">Full — hero overlay + unique photo per city</option>
            </select>
            <a href="playground.php#hero-overlay" target="_blank" style="font-size:.8rem;color:#2563eb;text-decoration:none;">tune hero style ↗</a>

            <span style="width:1px;height:22px;background:#e2e8f0;margin:0 4px;"></span>

            <label class="hint" style="margin:0;display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" id="cp-varylayout"> Vary block order per city
            </label>
        </div>
        <p class="hint" id="cp-imgdiff-help" style="margin:8px 0 0;line-height:1.55;"></p>
        <p class="hint" style="margin:6px 0 0;line-height:1.55;"><strong>Vary block order:</strong> gives each city page a slightly different section order (hero stays first, the closing block stays last, a couple of middle sections swap) so the pages aren't structurally identical. Deterministic per city; needs 4+ blocks in the template.</p>
    </div>
    <script>
    (function(){
        var HELP = {
            '':     'Every city page uses the same images from the template — fastest, but 40 pages sharing identical hero/section photos reads as thin, templated content.',
            'hero': 'Bakes the page keyword + "City, ST" onto the hero image, so each city page has a genuinely different, on-topic hero. Adds one generated hero image per city. Needs ImageMagick.',
            'full': 'Hero text overlay PLUS a byte-perturbed, city-renamed copy of every content photo — so no two city pages share an image file (beats exact + perceptual duplicate detection). Adds the most images to uploads/. Deterministic per city; originals are kept, nothing is deleted.'
        };
        var sel = document.getElementById('cp-imgdiff'), help = document.getElementById('cp-imgdiff-help');
        function upd(){ help.innerHTML = HELP[sel.value] || ''; }
        sel.addEventListener('change', upd); upd();
    })();
    </script>

    <!-- ── Per-template status cards ─────────────────────────────────────── -->
    <?php foreach ($templates as $tpl):
        $tplVersion  = _gen_template_version($tpl);
        $countTotal  = count($cities);
        $countOk     = 0; $countStale = 0; $countMissing = 0; $countNoAi = 0;

        // Pre-compute all statuses for this template
        $cityStatuses = [];
        foreach ($cities as $city) {
            [$st, $genAt, $aiAt] = _cp_page_status($tpl, $city, $tplVersion);
            $cityStatuses[$city['id']] = ['status' => $st, 'generated_at' => $genAt, 'ai_at' => $aiAt, 'tags' => $city['tags'] ?? []];
            if ($st === 'generated') $countOk++;
            elseif ($st === 'stale')   $countStale++;
            else                       $countMissing++;
            if (!$aiAt) $countNoAi++;
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
                    <?php if ($countNoAi > 0): ?>, <span style="color:#dc2626;"><?= $countNoAi ?> no AI</span><?php endif; ?>
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
                $aiAt  = $cityStatuses[$city['id']]['ai_at'];
                $tags  = $cityStatuses[$city['id']]['tags'];

                $icon  = $st === 'generated' ? '✅' : ($st === 'stale' ? '⚠️' : '❌');
                $label = $st === 'generated' ? 'Generated' : ($st === 'stale' ? 'Stale' : 'Missing');
                $color = $st === 'generated' ? '#166534' : ($st === 'stale' ? '#92400e' : '#991b1b');
                $bg    = $st === 'generated' ? '#f0fdf4'  : ($st === 'stale' ? '#fffbeb' : '#fef2f2');

                $dateLabel = '';
                if ($genAt) {
                    try { $dateLabel = (new DateTime($genAt))->format('M j'); } catch(Exception $e) {}
                }
                $aiLabel = '';
                if ($aiAt) {
                    try { $aiLabel = (new DateTime($aiAt))->format('M j'); } catch(Exception $e) {}
                }
            ?>
            <?php
                $previewSlug = ($st !== 'missing') ? _gen_resolve_slug($tpl['slug_pattern'] ?? '', $city) : '';
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
                        <div style="font-size:11px;margin-top:1px;<?= $aiLabel ? 'color:#7c3aed;' : 'color:#dc2626;' ?>">
                            <?= $aiLabel ? 'AI Generated · ' . $aiLabel : 'No AI' ?>
                        </div>
                        <?php if ($previewSlug): ?>
                        <div style="display:flex;gap:8px;margin-top:3px;">
                            <a href="../page.php?slug=<?= h($previewSlug) ?>" target="_blank"
                               style="font-size:11px;color:#2563eb;text-decoration:none;">Preview ↗</a>
                            <a href="../page.php?slug=<?= h($previewSlug) ?>&show_blocks=1" target="_blank"
                               style="font-size:11px;color:#7c3aed;text-decoration:none;">+ Blocks ↗</a>
                        </div>
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

    <!-- ── Structure generation log ──────────────────────────────────────── -->
    <div class="card">
        <h2>Generation History
            <?php if (!empty($structureLog)): ?>
            <span style="font-size:.78rem;font-weight:400;color:#9ca3af;">(<?= count($structureLog) ?> runs)</span>
            <?php endif; ?>
            <?php
            // Show last AI run date inline next to heading
            if (!empty($aiGenLog)) {
                $lastAiRun = $aiGenLog[0];
                $lastAiTs  = strtotime($lastAiRun['started_at'] ?? '');
                if ($lastAiTs) {
                    echo '<span style="font-size:.78rem;font-weight:400;color:#7c3aed;margin-left:16px;">Last AI run: ' . date('M j, Y H:i', $lastAiTs) . '</span>';
                }
            }
            ?>
        </h2>
        <?php if (empty($structureLog)): ?>
            <p class="hint">No runs yet — press Generate All above to get started.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.84rem;">
            <thead>
                <tr style="border-bottom:2px solid #e5e7eb;text-align:left;background:#f8fafc;">
                    <th style="padding:8px 10px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Date / Run</th>
                    <th style="padding:8px 10px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Scope</th>
                    <th style="padding:8px 10px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Pages</th>
                    <th style="padding:8px 10px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Backed Up</th>
                    <th style="padding:8px 10px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Errors</th>
                    <th style="padding:8px 10px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($structureLog as $run):
                $errList  = is_array($run['errors'] ?? []) ? $run['errors'] : [];
                $errCnt   = count($errList);
                $hasErr   = $errCnt > 0;
                $isDry    = !empty($run['dry_run']);
                $opts     = $run['options'] ?? [];
                $written  = (int)($run['pages_written']   ?? 0);
                $backedUp = (int)($run['pages_backed_up'] ?? 0);
                $durMs    = (int)($run['duration_ms']     ?? 0);

                // Scope label
                $scopeParts = [];
                if (!empty($opts['tag_filter']))   $scopeParts[] = 'tag:' . $opts['tag_filter'];
                if (!empty($opts['template_ids'])) $scopeParts[] = count($opts['template_ids']) . ' template(s)';
                if (!empty($opts['city_ids']))     $scopeParts[] = count($opts['city_ids']) . ' city';
                if (!empty($opts['force_locked'])) $scopeParts[] = 'force-locked';
                $scopeLabel = $scopeParts ? implode(' · ', $scopeParts) : 'all';

                // Format date
                $dateLabel = '';
                if (!empty($run['started_at'])) {
                    $ts = strtotime($run['started_at']);
                    if ($ts) $dateLabel = date('M j, Y H:i', $ts);
                }

                // Status dot
                $dot = $hasErr
                    ? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-right:5px;vertical-align:middle;"></span>'
                    : '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:5px;vertical-align:middle;"></span>';
            ?>
                <tr style="border-bottom:1px solid #f3f4f6;<?= $hasErr ? 'background:#fff7f7;' : '' ?>">
                    <td style="padding:8px 10px;white-space:nowrap;">
                        <?= $dot ?><span style="color:#374151;font-size:.82rem;"><?= h($dateLabel) ?></span><br>
                        <span style="color:#9ca3af;font-size:.72rem;padding-left:13px;"><?= h(substr($run['run_id'] ?? '', 0, 8)) ?><?= $isDry ? ' · <em>dry run</em>' : '' ?></span>
                    </td>
                    <td style="padding:8px 10px;color:#6b7280;font-size:.8rem;"><?= h($scopeLabel) ?></td>
                    <td style="padding:8px 10px;">
                        <?php if ($written > 0): ?>
                        <span style="font-weight:700;color:#166534;"><?= $written ?></span>
                        <?php else: ?>
                        <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;color:#6b7280;"><?= $backedUp ?: '<span style="color:#d1d5db;">—</span>' ?></td>
                    <td style="padding:8px 10px;color:<?= $hasErr ? '#dc2626' : '#9ca3af' ?>;font-weight:<?= $hasErr ? '700' : '400' ?>;"
                        <?php if ($hasErr && $errList): ?>title="<?= h(json_encode($errList, JSON_PRETTY_PRINT)) ?>"<?php endif; ?>>
                        <?= $errCnt ?: '<span style="color:#d1d5db;">—</span>' ?>
                        <?php if ($hasErr): ?> &#9432;<?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;color:#6b7280;font-size:.8rem;"><?= $durMs ? number_format($durMs / 1000, 2) . 's' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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
    var _activeTag   = '';
    var _tplNames    = <?= json_encode($_tplNames) ?>;
    var _cityNames   = <?= json_encode($_cityNames) ?>;
    var _totalTpls   = <?= count($templates) ?>;
    var _totalCities = <?= count($cities) ?>;

    // ── Tag filter ────────────────────────────────────────────────────────────
    window.cpSetTag = function(btn, tag) {
        _activeTag = tag;
        document.querySelectorAll('.cp-tag-btn').forEach(function(b) { b.classList.remove('active-tag'); });
        btn.classList.add('active-tag');
        document.querySelectorAll('.cp-city-cell').forEach(function(cell) {
            if (tag === '') {
                cell.removeAttribute('data-hidden');
            } else {
                var tags = JSON.parse(cell.dataset.tags || '[]');
                if (tags.includes(tag)) cell.removeAttribute('data-hidden');
                else cell.setAttribute('data-hidden', '1');
            }
        });
    };

    // ── Build confirmation text ───────────────────────────────────────────────
    function cpConfirmText(opts) {
        var lines = [];

        // Target scope
        if (opts.template_ids && opts.template_ids.length) {
            var names = opts.template_ids.map(function(id) { return _tplNames[id] || id; });
            lines.push('Template:  ' + names.join(', '));
        } else {
            lines.push('Templates: all ' + _totalTpls);
        }

        if (opts.city_ids && opts.city_ids.length === 1) {
            lines.push('City:      ' + (_cityNames[opts.city_ids[0]] || opts.city_ids[0]));
        } else if (opts.city_ids && opts.city_ids.length > 1) {
            lines.push('Cities:    ' + opts.city_ids.length + ' selected');
        } else if (_activeTag) {
            lines.push('Cities:    tag "' + _activeTag + '"');
        } else {
            lines.push('Cities:    all ' + _totalCities);
        }

        if (opts.force_locked) lines.push('           + overwrite locked blocks');
        if (opts.dry_run)      lines.push('           DRY RUN — no files written');

        return 'Generate pages?\n\n' + lines.join('\n');
    }

    // ── Render summary card ───────────────────────────────────────────────────
    function cpRenderSummary(data) {
        var el = document.getElementById('gen-summary');
        if (!el) return;

        var written  = parseInt(data.pages_written   || 0);
        var backed   = parseInt(data.pages_backed_up || 0);
        var skipped  = parseInt(data.pages_skipped   || 0);
        var errCnt   = (data.errors || []).length;
        var dur      = data.duration_ms ? (data.duration_ms / 1000).toFixed(2) + 's' : '';
        var isDry    = !!data.dry_run;

        if (!data.success) {
            el.innerHTML =
                '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;">' +
                '<div style="font-weight:700;color:#991b1b;margin-bottom:4px;">&#10060; Generation failed</div>' +
                '<div style="font-size:.83rem;color:#7f1d1d;">' + (data.message || 'Unknown error') + '</div>' +
                '</div>';
            el.style.display = '';
            return;
        }

        var ok = errCnt === 0;
        var head = isDry
            ? '&#128221; Dry run complete'
            : (ok ? '&#10003;&nbsp; Generation complete' : '&#9888;&nbsp; Completed with ' + errCnt + ' error(s)');
        var headColor = ok ? '#166534' : '#92400e';
        var bg        = ok ? '#f0fdf4' : '#fffbeb';
        var border    = ok ? '#86efac' : '#fde68a';

        var stats = '';
        if (written)  stats += '<div style="display:flex;flex-direction:column;"><span style="font-size:1.1rem;font-weight:700;color:' + (ok?'#166534':'#374151') + ';">' + written + '</span><span style="font-size:.72rem;color:#6b7280;">pages ' + (isDry ? 'checked' : 'written') + '</span></div>';
        if (backed)   stats += '<div style="display:flex;flex-direction:column;"><span style="font-size:1.1rem;font-weight:700;">' + backed + '</span><span style="font-size:.72rem;color:#6b7280;">backed up</span></div>';
        if (skipped)  stats += '<div style="display:flex;flex-direction:column;"><span style="font-size:1.1rem;font-weight:700;color:#92400e;">' + skipped + '</span><span style="font-size:.72rem;color:#6b7280;">skipped</span></div>';
        if (errCnt)   stats += '<div style="display:flex;flex-direction:column;"><span style="font-size:1.1rem;font-weight:700;color:#dc2626;">' + errCnt + '</span><span style="font-size:.72rem;color:#6b7280;">errors</span></div>';
        if (dur)      stats += '<div style="display:flex;flex-direction:column;"><span style="font-size:1.1rem;font-weight:700;">' + dur + '</span><span style="font-size:.72rem;color:#6b7280;">duration</span></div>';

        var reloadNote = isDry ? '' : '<div style="margin-top:10px;font-size:.75rem;color:#6b7280;">Refreshing page status in 4 seconds…</div>';
        el.innerHTML =
            '<div style="background:' + bg + ';border:1px solid ' + border + ';border-radius:8px;padding:16px 20px;">' +
            '<div style="font-size:.95rem;font-weight:700;color:' + headColor + ';margin-bottom:10px;">' + head + '</div>' +
            '<div style="display:flex;flex-wrap:wrap;gap:12px 28px;">' + (stats || '<span style="color:#6b7280;font-size:.83rem;">Nothing to generate.</span>') + '</div>' +
            (isDry ? '<div style="margin-top:8px;font-size:.75rem;color:#6b7280;">No files were written — this was a dry run.</div>' : '') +
            reloadNote +
            '</div>';
        el.style.display = '';
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Main generate function ────────────────────────────────────────────────
    window.cpGenerate = function(opts) {
        // Apply active tag filter unless caller overrides
        if (_activeTag && !opts.tag_filter) opts = Object.assign({ tag_filter: _activeTag }, opts);
        // Apply the per-city image mode (Off/Hero/Full) unless the caller set one
        var _idf = document.getElementById('cp-imgdiff');
        if (_idf && _idf.value && opts.image_diff === undefined) opts.image_diff = _idf.value;
        var _vl = document.getElementById('cp-varylayout');
        if (_vl && _vl.checked && opts.vary_layout === undefined) opts.vary_layout = 1;

        // Confirmation
        if (!confirm(cpConfirmText(opts))) return;

        var spinner = document.getElementById('gen-spinner');
        var result  = document.getElementById('gen-result');
        var summary = document.getElementById('gen-summary');

        result.style.display  = 'none';
        summary.style.display = 'none';
        summary.innerHTML     = '';
        spinner.style.display = 'inline';

        var fd = new FormData();
        fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
        Object.entries(opts).forEach(function([k, v]) {
            if (Array.isArray(v)) v.forEach(function(i) { fd.append(k + '[]', i); });
            else if (v !== undefined && v !== null) fd.append(k, v);
        });

        fetch('generate.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            spinner.style.display = 'none';

            // Cost warning — confirm then re-run
            if (data.cost_warning) {
                var msg = data.message + '\n\nSteps: ' + (data.cost_steps || []).join(', ') + '\nEstimated pages: ' + data.estimated_pages;
                if (!confirm(msg)) return;
                opts.confirmed_cost = 1;
                return cpGenerate(opts);
            }

            cpRenderSummary(data);

            if (!data.success) {
                result.className   = 'alert alert-error';
                result.textContent = data.message || 'Generation failed.';
                result.style.display = 'block';
                return;
            }

            // Reload to refresh status cells (skip on dry run)
            if (!data.dry_run) {
                setTimeout(function() { window.location.reload(); }, 4000);
            }
        })
        .catch(function(e) {
            spinner.style.display = 'none';
            result.className      = 'alert alert-error';
            result.textContent    = 'Request failed: ' + e.message;
            result.style.display  = 'block';
        });
    };
})();
</script>
