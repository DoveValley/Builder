<?php
// AI Block Type Registry tab — list, add, edit block types in ai_block_types.json.
// $tab, $csrfToken, $aiBlockTypes, $editingBlockId, $editingBlock, $isAddingBlock set by index.php.

$_abt_models = [
    'claude-haiku-4-5-20251001' => 'Haiku 4.5 — fast, cheap',
    'claude-sonnet-4-6'         => 'Sonnet 4.6 — higher quality',
];
$_abt_inject_modes = ['replace' => 'Replace field value', 'append' => 'Append to array', 'prepend' => 'Prepend to array'];

function _abt_form(array $bt = [], string $action = 'add', string $id = ''): void {
    global $_abt_models, $_abt_inject_modes, $csrfToken;
    $v   = fn(string $k) => h($bt[$k] ?? '');
    $is  = fn(string $k, string $val) => ($bt[$k] ?? '') === $val ? ' selected' : '';
    $mode = $bt['ai_mode'] ?? 'standalone';
?>
<form action="ai_blocks_save.php" method="post" id="abt-form">
    <input type="hidden" name="action"     value="<?= h($action) ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <?php if ($action !== 'add'): ?>
    <input type="hidden" name="block_type_id" value="<?= h($id) ?>">
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
        <?php if ($action === 'add'): ?>
        <div class="form-group">
            <label>Type ID <span class="cf-req">*</span></label>
            <input type="text" name="block_type_id" value="" placeholder="my_block_type" pattern="[a-z][a-z0-9_]{1,49}" required>
            <span class="hint">Lowercase letters, digits, underscores. Cannot be changed after creation.</span>
        </div>
        <?php else: ?>
        <div class="form-group">
            <label>Type ID</label>
            <input type="text" value="<?= h($id) ?>" disabled style="background:#f3f4f6;color:#6b7280;">
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Label <span class="cf-req">*</span></label>
            <input type="text" name="label" value="<?= $v('label') ?>" placeholder="City Market Intro" required>
        </div>
    </div>

    <div class="form-group">
        <label>Description</label>
        <input type="text" name="description" value="<?= $v('description') ?>" placeholder="Short description shown in the admin block editor.">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
        <div class="form-group">
            <label>Mode <span class="cf-req">*</span></label>
            <select name="ai_mode" id="abt-mode" onchange="abtToggleMode(this.value)">
                <option value="standalone"<?= $is('ai_mode', 'standalone') ?>>Standalone — generates a new block</option>
                <option value="inject"<?= $is('ai_mode', 'inject') ?>>Inject — merges into an adjacent block's field</option>
            </select>
        </div>
        <div class="form-group">
            <label>Model</label>
            <select name="ai_model">
                <?php foreach ($_abt_models as $mid => $mlabel): ?>
                <option value="<?= h($mid) ?>"<?= $is('ai_model', $mid) ?>><?= h($mlabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Standalone fields -->
    <div id="abt-standalone-fields" style="<?= $mode === 'inject' ? 'display:none;' : '' ?>">
        <div class="form-group">
            <label>Render as (block type) <span class="cf-req">*</span></label>
            <input type="text" name="ai_render_as" value="<?= $v('ai_render_as') ?>" placeholder="text"
                   list="abt-block-types">
            <datalist id="abt-block-types">
                <option value="text"><option value="feature_columns"><option value="cards">
                <option value="stats"><option value="faq_two_col"><option value="testimonials">
                <option value="steps"><option value="pricing_cards"><option value="logo_bar">
            </datalist>
            <span class="hint">The block type the generated content will be rendered as on the live page.</span>
        </div>
    </div>

    <!-- Inject fields -->
    <div id="abt-inject-fields" style="<?= $mode !== 'inject' ? 'display:none;' : '' ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 20px;">
            <div class="form-group">
                <label>Inject target <span class="cf-req">*</span></label>
                <select name="ai_inject_target">
                    <option value="">— none —</option>
                    <option value="next"<?= $is('ai_inject_target', 'next') ?>>Next block</option>
                    <option value="prev"<?= $is('ai_inject_target', 'prev') ?>>Previous block</option>
                </select>
            </div>
            <div class="form-group">
                <label>Inject field <span class="cf-req">*</span></label>
                <input type="text" name="ai_inject_field" value="<?= $v('ai_inject_field') ?>" placeholder="hs_subtext">
                <span class="hint">Field name on the target block.</span>
            </div>
            <div class="form-group">
                <label>Inject mode</label>
                <select name="ai_inject_mode">
                    <option value="">— none —</option>
                    <?php foreach ($_abt_inject_modes as $imv => $iml): ?>
                    <option value="<?= h($imv) ?>"<?= $is('ai_inject_mode', $imv) ?>><?= h($iml) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Prompt -->
    <div class="form-group">
        <label>Prompt <span class="cf-req">*</span></label>
        <textarea name="ai_prompt" rows="14" placeholder="You are writing content for {business}..." required
                  style="font-family:monospace;font-size:.82rem;"><?= h($bt['ai_prompt'] ?? '') ?></textarea>
        <span class="hint">
            Available context vars: <code>{business}</code> <code>{city}</code> <code>{SS}</code>
            <code>{service}</code> <code>{industries}</code> <code>{top_employers}</code>
            <code>{salary_note}</code> <code>{market_blurb}</code>.
            Instruct Claude to return JSON only.
        </span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
        <div class="form-group">
            <label>Output schema (JSON)</label>
            <textarea name="ai_output_schema_json" rows="5"
                      style="font-family:monospace;font-size:.8rem;"><?= h(json_encode($bt['ai_output_schema'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
            <span class="hint">Keys Claude must return and their types (<code>string</code>, <code>html_string</code>, <code>array</code>).</span>
        </div>
        <div class="form-group">
            <label>Default fields (JSON)</label>
            <textarea name="default_fields_json" rows="5"
                      style="font-family:monospace;font-size:.8rem;"><?= h(json_encode($bt['default_fields'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
            <span class="hint">Field defaults merged onto the block when it is rendered (e.g. <code>skin</code>, <code>heading_level</code>).</span>
        </div>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
        <button type="submit" class="btn"><?= $action === 'add' ? 'Add Block Type' : 'Save Changes' ?></button>
        <a href="?tab=ai_blocks" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<?php
}
?>

<div class="tab-content" style="<?= $tab === 'ai_blocks' ? '' : 'display:none;' ?>">

<h2 style="margin-bottom:6px;">Block Type Registry</h2>
<p class="hint" style="margin-bottom:20px;">
    Configure the AI block types available to the generator. Each entry in <code>ai_block_types.json</code> defines a prompt, output schema, and how generated content is placed on the page.
</p>

<?php if ($editingBlockId !== null): ?>

    <!-- ── Edit view ──────────────────────────────────────────────────────── -->
    <p style="margin-bottom:16px;"><a href="?tab=ai_blocks">&larr; Back to all block types</a></p>

    <div class="card">
        <h3 style="margin-top:0;margin-bottom:16px;">Edit: <?= h($editingBlock['label'] ?? $editingBlockId) ?></h3>
        <?php _abt_form($editingBlock, 'save', $editingBlockId); ?>
    </div>

    <!-- ── Prompt preview panel ──────────────────────────────────────────── -->
    <div class="card" style="margin-top:16px;" id="abt-preview-card">
        <h4 style="margin-top:0;margin-bottom:12px;">Resolved Prompt Preview</h4>
        <p class="hint" style="margin-bottom:14px;">
            Select a city to see the exact prompt Claude would receive, with all <code>{variables}</code> substituted from real data.
        </p>
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">City</label>
                <select id="preview-city" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;">
                    <option value="">— select a city —</option>
                    <?php foreach ($cities as $c): ?>
                    <option value="<?= h($c['id'] ?? '') ?>"><?= h($c['city'] ?? '') ?>, <?= h($c['SS'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">Service <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                <input type="text" id="preview-service" placeholder="PMP certification training"
                       style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;width:220px;">
            </div>
            <button class="ai-run-btn" id="preview-btn" onclick="abtPreview()"
                    style="padding:7px 16px;font-size:.83rem;">Resolve</button>
            <div class="ai-spinner" id="preview-spinner"></div>
        </div>

        <div id="preview-ctx-wrap" style="display:none;margin-bottom:12px;">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;margin-bottom:0;">
                <tbody id="preview-ctx-table"></tbody>
            </table>
        </div>

        <div id="preview-unresolved-wrap" style="display:none;margin-bottom:10px;">
            <span style="font-size:.78rem;color:#92400e;background:#fef3c7;padding:3px 8px;border-radius:4px;">
                Unresolved variables: <span id="preview-unresolved-list"></span>
            </span>
        </div>

        <pre id="preview-output" style="display:none;background:#0f172a;color:#94a3b8;font-size:.77rem;line-height:1.6;padding:14px 16px;border-radius:8px;overflow-x:auto;white-space:pre-wrap;word-break:break-word;max-height:400px;overflow-y:auto;margin:0;"></pre>
        <p id="preview-error" style="display:none;color:#dc2626;font-size:.82rem;margin:0;"></p>
    </div>

    <form action="ai_blocks_save.php" method="post" style="margin-top:16px;"
          onsubmit="return confirm('Delete block type &quot;<?= h(addslashes($editingBlockId)) ?>&quot;? This cannot be undone.');">
        <input type="hidden" name="action"        value="delete">
        <input type="hidden" name="block_type_id" value="<?= h($editingBlockId) ?>">
        <input type="hidden" name="csrf_token"    value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">Delete This Block Type</button>
    </form>

<?php elseif ($isAddingBlock): ?>

    <!-- ── Add view ───────────────────────────────────────────────────────── -->
    <p style="margin-bottom:16px;"><a href="?tab=ai_blocks">&larr; Back to all block types</a></p>

    <div class="card">
        <h3 style="margin-top:0;margin-bottom:16px;">Add Block Type</h3>
        <?php _abt_form([], 'add'); ?>
    </div>

<?php else: ?>

    <!-- ── List view ──────────────────────────────────────────────────────── -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <span class="hint" style="margin:0;"><?= count($aiBlockTypes) ?> block type<?= count($aiBlockTypes) !== 1 ? 's' : '' ?> registered</span>
        <a href="?tab=ai_blocks&new=1" class="btn">+ Add Block Type</a>
    </div>

    <?php if (empty($aiBlockTypes)): ?>
    <div class="card">
        <p class="hint">No block types registered yet. <a href="?tab=ai_blocks&new=1">Add one</a>.</p>
    </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:.84rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">
                    <th style="text-align:left;padding:10px 14px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">ID</th>
                    <th style="text-align:left;padding:10px 14px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Label</th>
                    <th style="text-align:left;padding:10px 14px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Mode</th>
                    <th style="text-align:left;padding:10px 14px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Model</th>
                    <th style="text-align:left;padding:10px 14px;font-size:.74rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Description</th>
                    <th style="padding:10px 14px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($aiBlockTypes as $btId => $bt): ?>
            <?php
            $modeLabel = $bt['ai_mode'] === 'inject' ? 'inject' : 'standalone';
            $modeColor = $bt['ai_mode'] === 'inject' ? '#ede9fe;color:#5b21b6' : '#dbeafe;color:#1e40af';
            $modelShort = str_contains($bt['ai_model'] ?? '', 'haiku') ? 'Haiku' : (str_contains($bt['ai_model'] ?? '', 'sonnet') ? 'Sonnet' : ($bt['ai_model'] ?? '—'));
            ?>
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:10px 14px;font-family:monospace;font-size:.8rem;color:#374151;"><?= h($btId) ?></td>
                <td style="padding:10px 14px;font-weight:600;"><?= h($bt['label'] ?? '') ?></td>
                <td style="padding:10px 14px;">
                    <span style="font-size:.72rem;font-weight:600;padding:2px 7px;border-radius:4px;background:<?= $modeColor ?>;"><?= $modeLabel ?></span>
                </td>
                <td style="padding:10px 14px;font-size:.8rem;color:#6b7280;"><?= h($modelShort) ?></td>
                <td style="padding:10px 14px;font-size:.8rem;color:#6b7280;max-width:280px;"><?= h(mb_substr($bt['description'] ?? '', 0, 80)) ?><?= mb_strlen($bt['description'] ?? '') > 80 ? '…' : '' ?></td>
                <td style="padding:10px 14px;text-align:right;white-space:nowrap;">
                    <a href="?tab=ai_blocks&edit=<?= h(urlencode($btId)) ?>" class="btn btn-secondary btn-small">Edit</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div>

<script>
function abtToggleMode(mode) {
    document.getElementById('abt-standalone-fields').style.display = mode === 'standalone' ? '' : 'none';
    document.getElementById('abt-inject-fields').style.display     = mode === 'inject'     ? '' : 'none';
}

<?php if ($editingBlockId !== null): ?>
(function () {
    var _csrf     = <?= json_encode($csrfToken) ?>;
    var _blockId  = <?= json_encode($editingBlockId) ?>;
    var CTX_LABELS = {
        business: 'Business name', city: 'City', SS: 'State (abbrev)',
        service: 'Service', industries: 'Industries', top_employers: 'Top employers',
        salary_note: 'Salary note', market_blurb: 'Market blurb'
    };

    window.abtPreview = function () {
        var cityId  = document.getElementById('preview-city').value;
        var service = document.getElementById('preview-service').value;
        var btn     = document.getElementById('preview-btn');
        var spinner = document.getElementById('preview-spinner');
        var output  = document.getElementById('preview-output');
        var errEl   = document.getElementById('preview-error');
        var ctxWrap = document.getElementById('preview-ctx-wrap');
        var ctxTbl  = document.getElementById('preview-ctx-table');
        var unWrap  = document.getElementById('preview-unresolved-wrap');
        var unList  = document.getElementById('preview-unresolved-list');

        if (!cityId) { alert('Select a city first.'); return; }

        btn.disabled = true;
        spinner.classList.add('on');
        output.style.display = 'none';
        errEl.style.display  = 'none';
        ctxWrap.style.display = 'none';
        unWrap.style.display  = 'none';

        var fd = new FormData();
        fd.append('csrf_token',    _csrf);
        fd.append('block_type_id', _blockId);
        fd.append('city_id',       cityId);
        fd.append('service',       service);

        fetch('ai_prompt_preview.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            spinner.classList.remove('on');

            if (!res.success) {
                errEl.textContent = res.error || 'Preview failed.';
                errEl.style.display = 'block';
                return;
            }

            // Context table
            ctxTbl.innerHTML = '';
            Object.keys(res.context).forEach(function (k) {
                var val = res.context[k] || '(empty)';
                var row = document.createElement('tr');
                row.innerHTML =
                    '<td style="padding:3px 8px 3px 0;font-family:monospace;font-size:.75rem;color:#6b7280;white-space:nowrap;vertical-align:top;">{' + k + '}</td>' +
                    '<td style="padding:3px 0;font-size:.77rem;color:#374151;vertical-align:top;">' +
                        (CTX_LABELS[k] ? '<span style="color:#9ca3af;margin-right:6px;">' + CTX_LABELS[k] + '</span>' : '') +
                        escHtml(val.length > 120 ? val.slice(0, 120) + '…' : val) +
                    '</td>';
                ctxTbl.appendChild(row);
            });
            ctxWrap.style.display = 'block';

            // Unresolved vars
            if (res.unresolved && res.unresolved.length) {
                unList.textContent = res.unresolved.join(', ');
                unWrap.style.display = 'block';
            }

            output.textContent   = res.resolved;
            output.style.display = 'block';
            output.scrollTop     = 0;
        })
        .catch(function (err) {
            btn.disabled = false;
            spinner.classList.remove('on');
            errEl.textContent   = 'Request failed: ' + err.message;
            errEl.style.display = 'block';
        });
    };

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
})();
<?php endif; ?>
</script>
