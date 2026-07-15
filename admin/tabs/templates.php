<?php
// Templates tab — list view + block editor view
// $tab, $templates, $editingTemplate, $editingTemplateId set by index.php
?>
<div class="tab-content" style="<?= $tab === 'templates' ? '' : 'display:none;' ?>">
<?php tab_header('Landing Templates', 'Design the block structure for city landing pages. Each template × each city produces one generated page.', 'tab-templates'); ?>
<?php if ($editingTemplate === null && $editingRegistryEntry !== null): ?>

    <!-- ── Registry entry editor ────────────────────────────────────── -->
    <p style="margin-bottom:16px;"><a href="?tab=templates">&larr; Back to templates &amp; registry</a></p>

    <form action="templates_save.php" method="post">
        <input type="hidden" name="action"      value="registry_save">
        <input type="hidden" name="registry_id" value="<?= h($editingRegistryId) ?>">
        <input type="hidden" name="csrf_token"  value="<?= h($csrfToken) ?>">

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h2 style="margin:0;">Edit Registry Entry: <code><?= h($editingRegistryId) ?></code></h2>
                <button type="submit" class="btn">Save Entry</button>
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:2 1 220px;">
                    <label>Label <span class="hint">(shown in admin)</span></label>
                    <input type="text" name="reg_label" value="<?= h($editingRegistryEntry['label'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="flex:0 0 150px;">
                    <label>Mode</label>
                    <select name="reg_ai_mode">
                        <option value="standalone" <?= ($editingRegistryEntry['ai_mode'] ?? '') === 'standalone' ? 'selected' : '' ?>>Standalone</option>
                        <option value="inject"     <?= ($editingRegistryEntry['ai_mode'] ?? '') === 'inject'     ? 'selected' : '' ?>>Inject</option>
                    </select>
                </div>
                <div class="form-group" style="flex:0 0 160px;">
                    <label>Render as <span class="hint">(standalone)</span></label>
                    <input type="text" name="reg_ai_render_as" value="<?= h($editingRegistryEntry['ai_render_as'] ?? '') ?>" placeholder="e.g. text, feature_columns">
                </div>
            </div>

            <div class="form-group">
                <label>Description <span class="hint">(shown in registry list)</span></label>
                <input type="text" name="reg_description" value="<?= h($editingRegistryEntry['description'] ?? '') ?>">
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:0 0 160px;">
                    <label>Inject target <span class="hint">(inject mode)</span></label>
                    <select name="reg_ai_inject_target">
                        <option value=""         <?= ($editingRegistryEntry['ai_inject_target'] ?? '') === ''         ? 'selected' : '' ?>>None</option>
                        <option value="previous" <?= ($editingRegistryEntry['ai_inject_target'] ?? '') === 'previous' ? 'selected' : '' ?>>Previous block</option>
                        <option value="next"     <?= ($editingRegistryEntry['ai_inject_target'] ?? '') === 'next'     ? 'selected' : '' ?>>Next block</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 150px;">
                    <label>Target field <span class="hint">(inject mode)</span></label>
                    <input type="text" name="reg_ai_inject_field" value="<?= h($editingRegistryEntry['ai_inject_field'] ?? '') ?>" placeholder="e.g. hs_subtext, fq_items">
                </div>
                <div class="form-group" style="flex:0 0 150px;">
                    <label>Inject mode</label>
                    <select name="reg_ai_inject_mode">
                        <option value=""        <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === ''        ? 'selected' : '' ?>>None</option>
                        <option value="replace" <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === 'replace' ? 'selected' : '' ?>>Replace</option>
                        <option value="append"  <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === 'append'  ? 'selected' : '' ?>>Append</option>
                        <option value="prepend" <?= ($editingRegistryEntry['ai_inject_mode'] ?? '') === 'prepend' ? 'selected' : '' ?>>Prepend</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Prompt <span class="hint">Use {city}, {SS}, {industries}, {top_employers}, {salary_note}, {market_blurb}, {business}, {keyword} as variables</span></label>
                <textarea name="reg_ai_prompt" rows="16" style="font-family:monospace;font-size:0.82rem;"><?= h($editingRegistryEntry['ai_prompt'] ?? '') ?></textarea>
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:1 1 220px;">
                    <label>Output schema <span class="hint">(JSON — field name → type)</span></label>
                    <textarea name="reg_ai_output_schema" rows="5" style="font-family:monospace;font-size:0.82rem;"><?= h(json_encode($editingRegistryEntry['ai_output_schema'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                </div>
                <div class="form-group" style="flex:1 1 220px;">
                    <label>Default fields <span class="hint">(JSON — merged into generated block)</span></label>
                    <textarea name="reg_default_fields" rows="5" style="font-family:monospace;font-size:0.82rem;"><?= h(json_encode($editingRegistryEntry['default_fields'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn">Save Entry</button>
        </div>
    </form>

    <form action="templates_save.php" method="post" style="margin-top:8px;"
          onsubmit="return confirm('Delete registry entry &quot;<?= h(addslashes($editingRegistryId)) ?>&quot;? This cannot be undone.');">
        <input type="hidden" name="action"      value="registry_delete">
        <input type="hidden" name="registry_id" value="<?= h($editingRegistryId) ?>">
        <input type="hidden" name="csrf_token"  value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">Delete Entry</button>
    </form>

<?php elseif ($editingTemplate === null): ?>

    <!-- ── How to build a GREAT-SEO template (process + Claude prompt) ───── -->
    <details class="card" id="tpl-greatseo" style="background:#f8fafc;border-left:3px solid #7c3aed;">
        <summary style="cursor:pointer;font-weight:700;font-size:1.02rem;color:#4c1d95;list-style:none;user-select:none;"><span style="display:inline-block;width:1em;">&#9656;</span> How to build a GREAT-SEO landing template <span style="font-weight:400;font-size:.8rem;color:#64748b;">(process + a ready-to-paste Claude prompt)</span></summary>
        <div style="margin-top:14px;font-size:.9rem;line-height:1.6;color:#334155;">
            <p style="margin:0 0 8px;">A template is one of <strong>three human inputs</strong>; everything else is mechanical. Get these right and two passes (structure, then AI) produce unique, grounded, keyword-focused pages for every city.</p>
            <ol style="margin:0 0 12px 18px;padding:0;">
                <li><strong>Keyword map</strong> (<a href="?tab=keywords">Keywords</a>) — each page's ONE primary + a few secondaries.</li>
                <li><strong>Niche brief + archetypes</strong> (<a href="?tab=niche_brief">Niche Brief</a>) — the AI voice, guardrails, research fields, and per-block prompts. Fold the SEO rules into <em>tone</em> (angle-variation, search-intent) and <em>guardrails</em> (no boilerplate, no fabricated numbers). Then <em>Compile</em>.</li>
                <li><strong>Template blocks</strong> (this tab) — true-AI blocks for local/descriptive content + real-DATA blocks (pricing, schedule, stats, testimonials) that stay factual.</li>
            </ol>
            <p style="margin:0 0 4px;font-weight:700;color:#4c1d95;">The GREAT-SEO rules</p>
            <ul style="margin:0 0 12px 18px;padding:0;">
                <li><strong>One primary is the focus</strong> — in the &lt;title&gt;, H1 hero, intro H2, and slug. Don't dilute it.</li>
                <li><strong>Secondaries are light</strong> — FAQ block only, one long-tail per page, only if natural. Never stuff.</li>
                <li><strong>Uniqueness</strong> — each page leads with the most distinctive fact about THAT city (real employer / industry / project) so no two open alike. That's the anti-doorway defense.</li>
                <li><strong>Never fabricate</strong> — no invented pass rates, review counts, salaries (ranges from research only), or contact-hour numbers in AI prose. Remove any fake <code>aggregateRating</code> from schema.</li>
                <li><strong>Real signals rank high</strong> — real prices, real schedule, real guarantees. AI prose supports; it never stars.</li>
            </ul>
            <p style="margin:0 0 4px;font-weight:700;color:#4c1d95;">The 8 phases (what Claude runs)</p>
            <p style="margin:0 0 12px;">1 Keyword map → 2 Niche brief → 3 Archetypes + <code>compile.php</code> → 4 City research (real employers/salary/projects) → 5 Template block skeleton (~11-12 blocks; fill SEO incl <code>service_name</code>; strip fake rating) → 6 Generate (Pass A structure <code>force_locked</code> when blocks change, then Pass B AI <code>--file … --refresh</code>) → 7 Verify (HTTP 200, no stray tokens, no fabricated stats, FAQ open, valid schema) → 8 Deploy (<strong>session-mode build</strong>, force, verify images 200 + 301s).</p>
            <p style="margin:0 0 6px;font-weight:700;color:#4c1d95;">Reusable Claude prompt — paste into Claude Code to build/rework a template this way</p>
            <textarea id="tplPrompt" readonly rows="10" style="width:100%;font-family:ui-monospace,monospace;font-size:.72rem;line-height:1.45;background:#0f172a;color:#e2e8f0;border-radius:6px;padding:10px;border:0;"><?= h(@file_get_contents(BASE_DIR . '/uploads/template-build-prompt.txt')) ?></textarea>
            <div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
                <button type="button" class="btn btn-small" onclick="(function(t){t.select();document.execCommand('copy');this.textContent='✓ Copied';setTimeout(()=>this.textContent='⎘ Copy prompt',1500);}).call(this,document.getElementById('tplPrompt'))">&#9096; Copy prompt</button>
                <a class="btn btn-secondary btn-small" href="/uploads/template-build-prompt.txt" download="template-build-prompt.txt">&#11015; Download .txt</a>
                <span class="hint" style="margin:0;">Full write-up: <a href="docs.php?doc=reference#template-great-seo" target="_blank">Docs → Templates</a> · <code>docs/landing-page-build-process-V1-20260709.md</code></span>
            </div>
        </div>
    </details>

    <?php
    // Masters carry a free-text "role" label (any niche): appliance uses Home /
    // Type Hub / Brand Hub / Leaf; pest uses Extermination / Inspection / Category;
    // etc. It's an organizational label — nothing is hardcoded to a niche.
    $roleSuggestions = ['Home', 'Type Hub', 'Brand Hub', 'Leaf', 'Category', 'Hub', 'Extermination', 'Inspection'];

    // Partition templates: "Master Template" (base masters) vs the rest.
    $baseTemplates    = array_values(array_filter($templates, fn($t) => !empty($t['base'])));
    $regularTemplates = array_values(array_filter($templates, fn($t) => empty($t['base'])));
    // Group masters by their role label (labelled first, alphabetical; unlabelled last).
    usort($baseTemplates, function ($a, $b) {
        $ra = trim($a['base_role'] ?? ''); $rb = trim($b['base_role'] ?? '');
        if (($ra === '') !== ($rb === '')) return $ra === '' ? 1 : -1;   // unlabelled to the bottom
        return strcasecmp($ra, $rb);
    });

    // Renders one template row (Edit / base-toggle / Duplicate / Delete; + role label for masters).
    $renderTplRow = function (array $tpl, bool $isBase) use ($csrfToken) {
        $role = trim($tpl['base_role'] ?? '');
        ?>
        <div class="repeat-row" style="align-items:center;">
            <div style="flex:1;">
                <strong><?= h($tpl['title'] ?: '(untitled)') ?></strong>
                <?php if ($isBase): ?><span style="display:inline-block;margin-left:6px;padding:1px 8px;background:<?= $role !== '' ? '#7c3aed' : '#94a3b8' ?>;color:#fff;border-radius:10px;font-size:.68rem;font-weight:700;vertical-align:middle;"><?= h($role !== '' ? $role : 'unlabelled') ?></span><?php endif; ?>
                <br>
                <span class="hint">
                    Slug pattern: <code><?= h($tpl['slug_pattern'] ?? '') ?></code>
                    &mdash;
                    <?= count($tpl['content_blocks'] ?? []) ?> block<?= count($tpl['content_blocks'] ?? []) !== 1 ? 's' : '' ?>
                    &mdash;
                    <?= count($tpl['generation_steps'] ?? []) ?> generation step<?= count($tpl['generation_steps'] ?? []) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if ($isBase): ?>
            <form action="templates_save.php" method="post" style="display:inline;" title="Master role / archetype label (free text)">
                <input type="hidden" name="action" value="set_master_role">
                <input type="hidden" name="template_id" value="<?= h($tpl['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="text" name="base_role" value="<?= h($role) ?>" list="master-role-suggest" placeholder="role e.g. Brand Hub" style="width:120px;padding:2px 6px;font-size:.82rem;">
                <button type="submit" class="btn btn-secondary btn-small">Set role</button>
            </form>
            <?php endif; ?>

            <a class="btn btn-secondary btn-small" href="?tab=templates&template=<?= h($tpl['id']) ?>">Edit</a>

            <form action="templates_save.php" method="post" style="display:inline;">
                <input type="hidden" name="action" value="set_base">
                <input type="hidden" name="template_id" value="<?= h($tpl['id']) ?>">
                <input type="hidden" name="on" value="<?= $isBase ? '0' : '1' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <button type="submit" class="btn btn-secondary btn-small" title="<?= $isBase ? 'Move back to the Templates list' : 'Mark as a Master Template (base master)' ?>">
                    <?= $isBase ? '&darr; Move to Templates' : '&uarr; Set as Master' ?>
                </button>
            </form>

            <form action="templates_save.php" method="post" style="display:inline;">
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="template_id" value="<?= h($tpl['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <button type="submit" class="btn btn-secondary btn-small">Duplicate</button>
            </form>

            <form action="templates_save.php" method="post" style="display:inline;"
                  onsubmit="return confirm('Delete template \'<?= h(addslashes($tpl['title'])) ?>\'? This cannot be undone.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="template_id" value="<?= h($tpl['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <button type="submit" class="remove-row" title="Delete template">&times;</button>
            </form>
        </div>
        <?php
    };
    ?>

    <!-- ── List view ─────────────────────────────────────────────────── -->
    <div class="card">
        <h2>Add a New Template</h2>
        <p class="hint" style="margin-bottom:18px;">
            Templates are master landing pages. The generation engine clones each template
            for every city in your city list, replacing <code>{city}</code>, <code>{SS}</code>,
            and other city vars throughout.
        </p>
        <form action="templates_save.php" method="post">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="form-group">
                <label>Template title</label>
                <input type="text" name="title" placeholder="e.g. PMP Certification Training {city} {SS}" required>
            </div>
            <div class="form-group">
                <label>Slug pattern <span style="font-weight:400;color:#888;">(optional)</span></label>
                <input type="text" name="slug_pattern" placeholder="e.g. pmp-certification-training-{city_slug}">
                <span class="hint">Use <code>{city_slug}</code> where the city goes. Leave blank to auto-generate from the title.</span>
            </div>
            <button type="submit" class="btn">Add Template</button>
        </form>
    </div>

    <?php
    // ── Bulk Template Generator (niche-agnostic) ──────────────────────────────
    $bulk       = $_SESSION['tpl_bulk'] ?? null;
    unset($_SESSION['tpl_bulk']);
    $bulkBase   = $bulk['base']   ?? '';
    $bulkRows   = $bulk['rows']   ?? '';
    $bulkReport = $bulk['report'] ?? [];
    $mediaFiles = [];
    $mdir = rtrim(UPLOAD_DIR, '/') . '/media';
    if (is_dir($mdir)) {
        foreach (scandir($mdir) as $f) {
            if (preg_match('/\.(jpe?g|png|webp)$/i', $f)) $mediaFiles[] = $f;
        }
    }
    sort($mediaFiles);
    ?>
    <div class="card" id="bulkgen">
        <h2>Bulk Template Generator</h2>
        <p class="hint" style="margin-bottom:14px;">
            Clone a base template into many at once. Each row becomes a new landing template with the
            <strong>same block structure and image count</strong> as the base, its subject words swapped, and its own images.
            Nothing here is niche-specific — pick any base template and paste that niche's rows, and it works the same way.
        </p>
        <form action="templates_save.php" method="post">
            <input type="hidden" name="action" value="bulk_generate">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="form-group">
                <label>Base template <span class="hint">(the prototype that gets cloned)</span></label>
                <select name="base_id" required>
                    <option value="">— pick a base template —</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= h($t['id']) ?>" <?= $bulkBase === $t['id'] ? 'selected' : '' ?>><?= h($t['title'] ?: $t['id']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Rows <span class="hint">(one template per line, pipe-delimited)</span></label>
                <textarea name="rows" rows="9" style="font-family:monospace;font-size:0.82rem;"
                    placeholder="Mosquito Control | mosquito-control | Mosquito Control | cockroach=mosquito;roach=mosquito;roaches=mosquitoes | mosquito-katy_aa45f9.webp | about-mosquito-control-katy_68697d.webp | best-mosquito-control-katy_44af32.webp"><?= h($bulkRows) ?></textarea>
                <span class="hint" style="display:block;margin-top:6px;">
                    Columns: <code>Service | slug-base | Primary keyword | find=repl;… | hero img | intro img | local img | Title(optional)</code><br>
                    Only <strong>Service</strong> is required. <strong>find=repl</strong> swaps the base's subject words — whole-word &amp; case-aware, so
                    <code>cockroach=mosquito</code> also fixes <code>Cockroach</code>/<code>COCKROACH</code> (plurals need their own pair, e.g. <code>roaches=mosquitoes</code>).
                    Images are bare filenames from this site's media library (or a full path); blank keeps the base's image. Lines starting with <code>#</code> are skipped.
                </span>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" name="mode" value="preview" class="btn" style="background:#64748b;">Preview (dry run)</button>
                <button type="submit" name="mode" value="commit" class="btn"
                    onclick="return confirm('Generate these templates and append them to templates.json?\nA backup (templates.json.bak) is saved first.');">Generate Templates</button>
            </div>
        </form>

        <?php if ($bulkReport): ?>
            <div style="margin-top:18px;">
                <h3 style="margin-bottom:8px;">Preview — <?= count($bulkReport) ?> template(s) will be created</h3>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                    <thead><tr style="text-align:left;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:6px 8px;">Service</th><th style="padding:6px 8px;">New id</th>
                        <th style="padding:6px 8px;">Slug pattern</th><th style="padding:6px 8px;">Images (hero / intro / local)</th>
                        <th style="padding:6px 8px;">Checks</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($bulkReport as $r): ?>
                        <tr style="border-bottom:1px solid #eef2f6;">
                            <td style="padding:6px 8px;"><strong><?= h($r['service']) ?></strong></td>
                            <td style="padding:6px 8px;"><code><?= h($r['id']) ?></code></td>
                            <td style="padding:6px 8px;"><code><?= h($r['slug']) ?></code></td>
                            <td style="padding:6px 8px;font-size:0.76rem;">
                                <?php foreach (['hero','intro','local'] as $slot): $p = $r['images'][$slot] ?? ''; ?>
                                    <?= $slot[0] ?>: <?= $p ? h(basename($p)) : '<span style="color:#94a3b8;">(base)</span>' ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td style="padding:6px 8px;">
                                <?php if ($r['leftover'] > 0): ?>
                                    <span style="color:#b45309;">⚠ <?= (int)$r['leftover'] ?> leftover subject word(s)</span><br>
                                <?php endif; ?>
                                <?php if (!empty($r['img_missing'])): ?>
                                    <span style="color:#dc2626;">⚠ missing: <?= h(implode(', ', $r['img_missing'])) ?></span><br>
                                <?php endif; ?>
                                <?php if ($r['leftover'] === 0 && empty($r['img_missing'])): ?>
                                    <span style="color:#16a34a;">✓ clean</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="hint" style="margin-top:8px;">
                    Review above, then click <strong>Generate Templates</strong> to commit.
                    <strong style="color:#b45309;">⚠ leftover</strong> = the base's subject word is still present (add/fix a find=repl pair).
                    <strong style="color:#dc2626;">⚠ missing</strong> = image file not found in the media library.
                </p>
            </div>
        <?php endif; ?>

        <details style="margin-top:14px;">
            <summary style="cursor:pointer;">Available media files (<?= count($mediaFiles) ?>) — for image columns</summary>
            <div style="font-family:monospace;font-size:0.72rem;column-width:220px;margin-top:8px;max-height:260px;overflow:auto;">
                <?php foreach ($mediaFiles as $f): ?><div><?= h($f) ?></div><?php endforeach; ?>
            </div>
        </details>
    </div>

    <!-- ── Master Template (base masters) ─────────────────────────────── -->
    <div class="card">
        <h2>Master Template</h2>
        <datalist id="master-role-suggest"><?php foreach ($roleSuggestions as $rs): ?><option value="<?= h($rs) ?>"><?php endforeach; ?></datalist>
        <p class="hint" style="margin-bottom:16px;">
            The master template(s) the <strong>Bulk Template Generator</strong> clones from. Kept separate here
            so the base pattern is easy to find. Use <strong>&uarr; Set as Master</strong> on any template below to move it here,
            or <strong>&darr; Move to Templates</strong> to send it back.
            <br>With <strong>3+ masters</strong> (different page archetypes), give each a <strong>role label</strong>
            (free text &mdash; e.g. Home, Type Hub, Brand Hub, Leaf for appliance; Extermination, Inspection, Category for pest).
            They sort by label so the archetypes read top-down.
        </p>
        <?php if (empty($baseTemplates)): ?>
            <p class="hint">No master template set. Use <strong>&uarr; Set as Master</strong> on a template below to mark your base master.</p>
        <?php else: ?>
            <div class="repeat-items">
            <?php foreach ($baseTemplates as $tpl) $renderTplRow($tpl, true); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Templates</h2>
        <?php if (empty($regularTemplates)): ?>
            <p class="hint"><?= empty($templates) ? 'No templates yet. Add one above.' : 'All templates are in the Master Template section above.' ?></p>
        <?php else: ?>
            <div class="repeat-items">
            <?php foreach ($regularTemplates as $tpl) $renderTplRow($tpl, false); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Prompt Registry ─────────────────────────────────────────────── -->
    <div class="card">
        <h2>Prompt Registry</h2>
        <p class="hint" style="margin-bottom:16px;">
            Named AI block configurations. Each entry defines the prompt, mode, and output schema for one type of AI-generated content.
            Templates reference these by <strong>Type ID</strong> — update the prompt here and all templates pick it up automatically.
        </p>

        <?php if (empty($aiRegistry)): ?>
            <p class="hint">No registry file yet.</p>
        <?php else: ?>
            <div class="repeat-items">
            <?php foreach ($aiRegistry as $rid => $rentry): ?>
                <div class="repeat-row" style="align-items:flex-start;flex-wrap:wrap;gap:8px;">
                    <div style="flex:1;min-width:220px;">
                        <strong><?= h($rentry['label'] ?? $rid) ?></strong>
                        &nbsp;<code style="font-size:.78rem;color:#64748b;"><?= h($rid) ?></code>
                        &nbsp;<?php
                            $modeClr = ($rentry['ai_mode'] ?? '') === 'inject' ? '#7c3aed' : '#0369a1';
                            echo '<span style="display:inline-block;padding:1px 7px;background:'.h($modeClr).';color:#fff;border-radius:10px;font-size:.72rem;font-weight:700;">' . h(strtoupper($rentry['ai_mode'] ?? 'standalone')) . '</span>';
                        ?>
                        <?php if (!empty($rentry['ai_render_as'])): ?>
                        &nbsp;<span style="font-size:.78rem;color:#64748b;">renders as <code><?= h($rentry['ai_render_as']) ?></code></span>
                        <?php elseif (!empty($rentry['ai_inject_field'])): ?>
                        &nbsp;<span style="font-size:.78rem;color:#64748b;">injects into <code><?= h($rentry['ai_inject_field']) ?></code></span>
                        <?php endif; ?>
                        <br><span class="hint"><?= h($rentry['description'] ?? '') ?></span>
                    </div>
                    <a class="btn btn-secondary btn-small" href="?tab=templates&registry=<?= h($rid) ?>">Edit</a>
                    <form action="templates_save.php" method="post" style="display:inline;"
                          onsubmit="return confirm('Delete registry entry &quot;<?= h(addslashes($rid)) ?>&quot;?');">
                        <input type="hidden" name="action"      value="registry_delete">
                        <input type="hidden" name="registry_id" value="<?= h($rid) ?>">
                        <input type="hidden" name="csrf_token"  value="<?= h($csrfToken) ?>">
                        <button type="submit" class="remove-row" title="Delete entry">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr style="margin:20px 0;">
        <h3 style="margin:0 0 12px;">Add Registry Entry</h3>
        <form action="templates_save.php" method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="action"     value="registry_add">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="form-group" style="flex:1 1 180px;margin:0;">
                <label>Type ID</label>
                <input type="text" name="registry_id" placeholder="e.g. city_benefits_section" pattern="[a-z][a-z0-9_]{1,59}" required>
                <span class="hint">Lowercase letters, numbers, underscores only</span>
            </div>
            <div class="form-group" style="flex:1 1 180px;margin:0;">
                <label>Label</label>
                <input type="text" name="reg_label" placeholder="e.g. City Benefits Section" required>
            </div>
            <button type="submit" class="btn" style="align-self:flex-end;margin-bottom:20px;">Add Entry</button>
        </form>
    </div>

<?php else: ?>

    <!-- ── Edit view ─────────────────────────────────────────────────── -->
    <p style="margin-bottom:16px;">
        <a href="?tab=templates">&larr; Back to all templates</a>
    </p>

    <?php
    // Find first generated page for this template to enable a preview link
    $tplPreviewSlug = '';
    if (defined('PAGES_DIR') && is_dir(PAGES_DIR)) {
        foreach (glob(PAGES_DIR . $editingTemplateId . '_*.json') ?: [] as $pf) {
            $pd = json_decode(file_get_contents($pf), true);
            if (!empty($pd['slug'])) { $tplPreviewSlug = $pd['slug']; break; }
        }
    }
    ?>
    <form action="templates_save.php" method="post" enctype="multipart/form-data" id="tpl-save-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="template_id" value="<?= h($editingTemplateId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="block_count_submitted" id="block_count_submitted" value="0">
        <script>
        document.getElementById('tpl-save-form').addEventListener('submit', function() {
            var count = document.querySelectorAll('#tpl-save-form .block-type-select, #tpl-save-form input[name="block_type[]"]').length;
            document.getElementById('block_count_submitted').value = count;
        });
        </script>

        <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn">Save Template</button>
            <?php if ($tplPreviewSlug): ?>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
            <?php endif; ?>
        </div>

        <!-- Template settings -->
        <div class="card">
            <h2>Template Settings</h2>
            <div class="form-group">
                <label>Template title</label>
                <input type="text" name="template_title" value="<?= h($editingTemplate['title'] ?? '') ?>">
                <span class="hint">Use <code>{city}</code> and <code>{SS}</code> shortcodes — e.g. "PMP Certification Training {city} {SS}"</span>
            </div>
            <div class="form-group">
                <label>Slug pattern</label>
                <input type="text" name="slug_pattern" value="<?= h($editingTemplate['slug_pattern'] ?? '') ?>" required>
                <span class="hint">
                    Use <code>{city_slug}</code> where the city goes — e.g. <code>pmp-certification-training-{city_slug}</code>.
                    Generated pages will be available at <code>/{slug-pattern-resolved}</code>.
                </span>
            </div>
            <div class="form-group">
                <label>Generation steps <span style="font-weight:400;color:#888;">(JSON)</span></label>
                <textarea name="generation_steps" rows="4" style="font-family:monospace;font-size:13px;"><?= h(json_encode($editingTemplate['generation_steps'] ?? [['step' => 'city_vars']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                <span class="hint">
                    Array of steps the generation engine runs for each city. The built-in step is
                    <code>city_vars</code> (replaces all city shortcodes). Additional steps (AI, custom pricing, etc.)
                    will be added in Phase 5. Each step: <code>{"step":"step_name","options":{}}</code>.
                </span>
            </div>
        </div>

        <!-- Block editor (reuses existing infrastructure) -->
        <?php render_content_blocks_editor($editingTemplate['content_blocks'] ?? []); ?>

        <!-- SEO editor -->
        <?php render_seo_editor($editingTemplate['seo'] ?? [], 'template', '', $editingTemplate['slug_pattern'] ?? ''); ?>

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px;">
            <button type="submit" class="btn">Save Template</button>
            <?php if ($tplPreviewSlug): ?>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>&show_blocks=1" target="_blank" class="btn btn-secondary">Preview Blocks &rarr;</a>
            <a href="../page.php?slug=<?= h($tplPreviewSlug) ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Danger zone -->
    <form action="templates_save.php" method="post" style="margin-top:12px;"
          onsubmit="return confirm('Delete this template? This cannot be undone. Generated city pages are not affected.');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="template_id" value="<?= h($editingTemplateId) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">Delete This Template</button>
    </form>

<?php endif; ?>
</div>
