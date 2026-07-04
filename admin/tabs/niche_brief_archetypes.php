<?php
/**
 * Niche Brief tab → "Prompt templates (archetypes)" — per-master override editor.
 * Isolated include. Edits each AI archetype's prompt skeleton / model / labels for
 * THIS master; stores only the diffs vs the shared seed and recompiles on save.
 * Reads multisite/ai/archetypes.json (shared) + sites/{master}/multisite/archetypes.json
 * (override). Saves via admin/archetypes_save.php. Needs $csrfToken (from index.php).
 */
$archShared = json_decode((string)@file_get_contents(BASE_DIR . '/multisite/ai/archetypes.json'), true) ?: [];
$archOv     = json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/archetypes.json'), true) ?: [];
$archIds    = array_values(array_filter(array_keys($archShared), fn($k) => $k !== '_about' && $k !== '_shared' && is_array($archShared[$k])));
$archModels = ['claude-haiku-4-5-20251001' => 'Haiku 4.5 (fast, cheap — default)',
               'claude-sonnet-5'           => 'Sonnet 5 (stronger)',
               'claude-opus-4-8'           => 'Opus 4.8 (strongest, priciest)'];
$archEff = function (string $id, string $k) use ($archShared, $archOv) {
    return $archOv[$id][$k] ?? ($archShared[$id][$k] ?? '');
};
$archIsOv = fn(string $id) => isset($archOv[$id]) && is_array($archOv[$id]) && $archOv[$id];
?>
<details class="card" id="ms-archetypes" style="margin-top:16px;">
    <summary style="cursor:pointer;font-weight:700;font-size:1.02rem;color:#1e3a5f;">Prompt templates (archetypes) — advanced</summary>
    <style>
    #ms-archetypes .card input[type=text], #ms-archetypes .card textarea, #ms-archetypes .card select { width:100%; box-sizing:border-box; }
    #ms-archetypes .card label { display:block; margin-top:10px; }
    </style>
    <p class="hint" style="margin:12px 0;">The prompt each AI block is written from. Edits here override the <strong>shared</strong> templates <em>for this master only</em> and survive recompiling. Placeholders: <code>{business}</code> <code>{city}</code> <code>{state}</code> <code>{service}</code> (per-city at build) and <code>[[brief.business_descriptor]]</code> / <code>[[brief.customer_noun]]</code> / <code>[[brief.local_angle]]</code> / <code>[[brief.tone]]</code> … (filled from the Brief above at compile).</p>

    <form action="archetypes_save.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken ?? '') ?>">
        <?php foreach ($archIds as $id):
            $seed = $archShared[$id];
            $ov   = $archIsOv($id);
        ?>
        <div class="card" id="arch-<?= h($id) ?>" style="border:1px solid #e2e8f0;margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                <strong style="font-size:.95rem;"><?= h($seed['label'] ?? $id) ?></strong>
                <code style="font-size:.72rem;"><?= h($id) ?></code>
                <?php if ($ov): ?><span style="font-size:.7rem;color:#065f46;background:#d1fae5;padding:1px 8px;border-radius:999px;">overridden</span><?php endif; ?>
                <button type="button" onclick="archReset('<?= h($id) ?>')" style="margin-left:auto;background:none;border:0;color:#2563eb;cursor:pointer;font-size:.8rem;">↺ Reset to shared default</button>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <label style="margin-top:0;">Label</label>
                    <input type="text" name="label[<?= h($id) ?>]" id="af-label-<?= h($id) ?>" value="<?= h($archEff($id,'label')) ?>">
                </div>
                <div style="flex:0 0 240px;">
                    <label style="margin-top:0;">Model</label>
                    <select name="ai_model[<?= h($id) ?>]" id="af-ai_model-<?= h($id) ?>">
                        <?php $cur = $archEff($id,'ai_model'); $known = false;
                        foreach ($archModels as $mv => $ml): if ($mv === $cur) $known = true; ?>
                            <option value="<?= h($mv) ?>" <?= $mv === $cur ? 'selected' : '' ?>><?= h($ml) ?></option>
                        <?php endforeach; if (!$known && $cur !== ''): ?><option value="<?= h($cur) ?>" selected><?= h($cur) ?></option><?php endif; ?>
                    </select>
                </div>
            </div>
            <label>Description <span class="hint">(shown in the block editor)</span></label>
            <input type="text" name="description[<?= h($id) ?>]" id="af-description-<?= h($id) ?>" value="<?= h($archEff($id,'description')) ?>">
            <label>Prompt skeleton</label>
            <textarea name="prompt_skeleton[<?= h($id) ?>]" id="af-prompt_skeleton-<?= h($id) ?>" rows="12" style="font-family:monospace;font-size:.8rem;line-height:1.45;"><?= h($archEff($id,'prompt_skeleton')) ?></textarea>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn" style="background:#059669;">Save prompts &amp; recompile</button>
        <span class="hint" style="margin-left:8px;">Saves overrides for this master and regenerates its AI Block Registry.</span>
    </form>

    <script>
    var ARCH_DEFAULTS = <?php
        $defs = [];
        foreach ($archIds as $id) $defs[$id] = ['label'=>$archShared[$id]['label']??'','description'=>$archShared[$id]['description']??'','ai_model'=>$archShared[$id]['ai_model']??'','prompt_skeleton'=>$archShared[$id]['prompt_skeleton']??''];
        echo json_encode($defs, JSON_UNESCAPED_SLASHES);
    ?>;
    function archReset(id){
        var d = ARCH_DEFAULTS[id]; if(!d) return;
        ['label','description','ai_model','prompt_skeleton'].forEach(function(k){
            var el = document.getElementById('af-'+k+'-'+id); if(el) el.value = d[k];
        });
    }
    </script>
</details>
