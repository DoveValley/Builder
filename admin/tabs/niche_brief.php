<?php
// Niche Brief tab — panel-managed, no file editing.
// Edits sites/{master}/multisite/niche_brief.json and compiles it into the
// master's ai_block_types.json (via multisite/ai/compile.php).
// $tab, $csrfToken, $nicheBrief, $aiArchetypes set by index.php.

$b  = $nicheBrief ?: [];
$bv = fn(string $k) => h($b[$k] ?? '');
$offeringsText = implode("\n", (array)($b['offerings'] ?? []));
$enabled = (array)($b['enabled_archetypes'] ?? []);

// Compiled-registry status
$registryCount = 0;
if (defined('AI_REGISTRY_FILE') && file_exists(AI_REGISTRY_FILE)) {
    $reg = json_decode((string)file_get_contents(AI_REGISTRY_FILE), true);
    if (is_array($reg)) $registryCount = count(array_filter(array_keys($reg), fn($k) => $k === '' || $k[0] !== '_'));
}
?>
<div class="tab-content" style="<?= $tab === 'niche_brief' ? '' : 'display:none;' ?>">
<?php tab_header('Niche Brief', 'The domain vocabulary for this master site\'s vertical. Fill it in, then Compile to (re)generate the AI Block Registry from the shared, read-only archetypes. Each master site is one niche.', 'tab-niche-brief'); ?>

<form action="niche_brief_save.php" method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <div class="card">
        <h3 style="margin-top:0;margin-bottom:16px;">Brief</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
            <div class="form-group">
                <label>Niche ID</label>
                <input type="text" name="niche" value="<?= $bv('niche') ?>" placeholder="pest">
                <span class="hint">Label only (e.g. pest, wildlife, lawyers). Master site: <code><?= h(ACTIVE_SITE_ID) ?></code>.</span>
            </div>
            <div class="form-group">
                <label>Service noun <span class="cf-req">*</span></label>
                <input type="text" name="service_noun" value="<?= $bv('service_noun') ?>" placeholder="pest control" required>
                <span class="hint">The core service, used in headings/keywords (&ldquo;{service_noun} {city}&rdquo;).</span>
            </div>
        </div>

        <div class="form-group">
            <label>Business descriptor</label>
            <input type="text" name="business_descriptor" value="<?= $bv('business_descriptor') ?>" placeholder="a trained, locally operated pest control company">
            <span class="hint">How the business is described in prose, after &ldquo;{business}, &hellip;&rdquo;.</span>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px;">
            <div class="form-group">
                <label>Customer noun</label>
                <input type="text" name="customer_noun" value="<?= $bv('customer_noun') ?>" placeholder="homeowner">
                <span class="hint">Who the customer is (singular reads cleaner: homeowner, client, property manager).</span>
            </div>
            <div class="form-group">
                <label>Local angle</label>
                <input type="text" name="local_angle" value="<?= $bv('local_angle') ?>" placeholder="the local climate and seasonal pest cycles">
                <span class="hint">The city-relevance hook the AI grounds copy in. Keep it short — it appears in every prompt.</span>
            </div>
        </div>

        <div class="form-group">
            <label>Offerings</label>
            <textarea name="offerings" rows="6" placeholder="termite treatment&#10;mosquito control&#10;rodent removal" style="font-size:.85rem;"><?= h($offeringsText) ?></textarea>
            <span class="hint">One per line. Referenced in intros, feature columns, and FAQs.</span>
        </div>

        <div class="form-group">
            <label>Tone</label>
            <input type="text" name="tone" value="<?= $bv('tone') ?>" placeholder="reassuring and professional, with appropriate urgency for active infestations">
        </div>

        <div class="form-group">
            <label>Niche guardrails</label>
            <textarea name="guardrails" rows="3" placeholder="Never guarantee total eradication. Do not make efficacy/safety claims." style="font-size:.85rem;"><?= h($b['guardrails'] ?? '') ?></textarea>
            <span class="hint">Appended to the shared accuracy rules on every prompt. Critical for regulated niches (e.g. legal advertising rules for lawyers).</span>
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="uses_research_fields" value="1" <?= !empty($b['uses_research_fields']) ? 'checked' : '' ?> style="width:auto;">
                Uses research fields (industries / employers / salary from cities.json)
            </label>
            <span class="hint">On for data-rich niches (e.g. Granite PM). Off = pure AI-from-geography. Archetypes marked <code>requires_research</code> are skipped when off.</span>
        </div>

        <div class="form-group" style="margin-top:14px;margin-bottom:0;">
            <label for="research_prompt">Research prompt <span class="hint" style="font-weight:400;">(only used when research is on)</span></label>
            <textarea id="research_prompt" name="research_prompt" rows="8" style="font-family:monospace;font-size:0.85rem;"><?= $bv('research_prompt') ?></textarea>
            <span class="hint">What to research per city. Tokens: <code>{city}</code> <code>{state}</code> <code>{SS}</code> (per city) · <code>{business_descriptor}</code> <code>{service_noun}</code> (from this brief). Must ask Claude to return JSON. <strong>Leave blank</strong> for a generic local-market default. The field names you request should match the ones your archetypes reference.</span>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;margin-bottom:6px;">Enabled archetypes</h3>
        <p class="hint" style="margin-top:0;margin-bottom:14px;">The shared archetypes are <strong>seed-once and read-only</strong>. Tick the ones this niche should generate. Tune wording via the brief fields above (or per-block overrides in the Block Registry).</p>
        <?php if (empty($aiArchetypes)): ?>
            <p class="hint">No archetypes found at <code>multisite/ai/archetypes.json</code>.</p>
        <?php else: foreach ($aiArchetypes as $aid => $arch):
            $mode  = $arch['ai_mode'] ?? 'standalone';
            $tgt   = $mode === 'inject' ? ('inject → ' . h($arch['ai_inject_field'] ?? '?')) : ('→ ' . h($arch['ai_render_as'] ?? '?'));
        ?>
        <label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;cursor:pointer;">
            <input type="checkbox" name="enabled_archetypes[]" value="<?= h($aid) ?>" <?= in_array($aid, $enabled, true) ? 'checked' : '' ?> style="width:auto;margin-top:3px;">
            <span>
                <span style="font-weight:600;"><?= h($arch['label'] ?? $aid) ?></span>
                <code style="font-size:.72rem;color:#6b7280;margin-left:6px;"><?= h($aid) ?></code>
                <span style="font-size:.72rem;font-weight:600;padding:1px 6px;border-radius:4px;margin-left:6px;background:<?= $mode === 'inject' ? '#ede9fe;color:#5b21b6' : '#dbeafe;color:#1e40af' ?>;"><?= $tgt ?></span>
                <?php if (!empty($arch['requires_research'])): ?><span style="font-size:.72rem;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;margin-left:4px;">needs research</span><?php endif; ?>
                <br><span class="hint" style="margin:0;"><?= h($arch['description'] ?? '') ?></span>
            </span>
        </label>
        <?php endforeach; endif; ?>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;">
        <button type="submit" class="btn">Save Brief</button>
        <button type="submit" class="btn" name="then_compile" value="1" style="background:#059669;">Save &amp; Compile</button>
        <a href="?tab=ai_blocks" class="btn btn-secondary">View Block Registry &rarr;</a>
    </div>
</form>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">Compile</h3>
    <p class="hint" style="margin-top:0;">
        Merges the read-only archetypes with the saved brief and <strong>overwrites</strong> this site's
        <code>ai_block_types.json</code>.
        <?php if ($registryCount): ?>
            Current registry: <strong><?= $registryCount ?></strong> block type(s).
        <?php else: ?>
            No registry compiled yet.
        <?php endif; ?>
    </p>
    <form action="niche_brief_save.php" method="post" style="margin:0;">
        <input type="hidden" name="action" value="compile">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn">Compile Registry Now</button>
    </form>
</div>

<?php require __DIR__ . '/niche_brief_archetypes.php'; ?>

</div>
