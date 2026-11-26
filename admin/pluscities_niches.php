<?php
/**
 * admin/pluscities_niches.php — the real Niche Library admin screen from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 2/10: Platform-Owner-only
 * management of families, niche blueprints, and compliance presets.
 *
 * Modeled directly on admin/niche_profiles.php's list+form pattern (same
 * CSRF/role gate), extended with the new field set — family inheritance,
 * compliance presets, and real per-service keyword validation — rather than
 * inventing a new screen pattern.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:The+niche+library+is+managed+by+the+platform+only');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

$editingFamily = !empty($_GET['edit_family']) ? pluscities_family_load($_GET['edit_family']) : null;
$editingNiche  = !empty($_GET['edit_niche'])  ? pluscities_niche_load($_GET['edit_niche'])   : null;
$editingPreset = !empty($_GET['edit_preset']) ? pluscities_preset_load($_GET['edit_preset']) : null;

$families = array_filter(array_map('pluscities_family_load', pluscities_family_list()));
$niches   = array_filter(array_map('pluscities_niche_load', pluscities_niche_list()));
$presets  = array_filter(array_map('pluscities_preset_load', array_map(
    fn($f) => basename($f, '.json'),
    glob(PLUSCITIES_COMPLIANCE_PRESETS_DIR . '*.json') ?: []
)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Niche Library — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_niches', 'Niche Library');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:160px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input,.pc-form-row select,.pc-form-row textarea{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.pc-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);}
.pc-row:last-child{border-bottom:none;}
.pc-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px;}
.pc-photo-tile img{width:100%;aspect-ratio:3/2;object-fit:cover;border-radius:10px;border:1px solid var(--pc-line,#e2e8f2);background:var(--pc-mist,#f4f7fb);}
.pc-photo-tile .caption{font-size:.78rem;color:var(--pc-muted,#52607a);margin:8px 0 6px;}
.pc-photo-tile .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;">Compliance Presets</h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Named, reusable banned-phrase lists + validator-rule toggles — referenced by a family, swappable
        per niche if one is a genuine outlier.
    </p>
    <?php foreach ($presets as $p): ?>
        <div class="pc-row">
            <div><strong><?= h($p['label'] ?: $p['preset_id']) ?></strong>
                <div style="font-size:.78rem;color:var(--pc-muted,#52607a);"><?= h($p['preset_id']) ?></div></div>
            <a href="?edit_preset=<?= h($p['preset_id']) ?>" class="btn-sm-outline">Edit</a>
        </div>
    <?php endforeach; ?>
    <form method="post" action="pluscities_niches_save.php" class="pc-form-row" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_preset">
        <?php if ($editingPreset): ?><input type="hidden" name="existing_preset_id" value="<?= h($editingPreset['preset_id']) ?>"><?php endif; ?>
        <div class="fg"><label>Preset ID</label><input type="text" name="preset_id" required placeholder="e.g. contractor"
            value="<?= h($editingPreset['preset_id'] ?? '') ?>" <?= $editingPreset ? 'readonly' : '' ?>></div>
        <div class="fg"><label>Label</label><input type="text" name="label" value="<?= h($editingPreset['label'] ?? '') ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Banned Phrases (comma-separated)</label>
            <input type="text" name="banned_phrases" value="<?= h(implode(', ', $editingPreset['banned_phrases'] ?? [])) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Validator Rule Toggles (comma-separated keys, e.g. show_license, no_unverifiable_pricing)</label>
            <input type="text" name="validator_rule_toggles" value="<?= h(implode(', ', array_keys($editingPreset['validator_rule_toggles'] ?? []))) ?>"></div>
        <div style="flex-basis:100%;"><button type="submit" class="btn-open"><?= $editingPreset ? 'Save Preset' : 'Add Preset' ?></button></div>
    </form>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Families</h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Sets defaults every niche in it inherits — schema type, default city-block pool, compliance
        preset, approved sources, radius, which research topics are relevant.
    </p>
    <?php foreach ($families as $f): ?>
        <div class="pc-row">
            <div><strong><?= h($f['label'] ?: $f['family_id']) ?></strong>
                <div style="font-size:.78rem;color:var(--pc-muted,#52607a);"><?= h($f['family_id']) ?> — preset: <?= h($f['compliance_preset'] ?: '—') ?></div></div>
            <a href="?edit_family=<?= h($f['family_id']) ?>" class="btn-sm-outline">Edit</a>
        </div>
    <?php endforeach; ?>
    <form method="post" action="pluscities_niches_save.php" class="pc-form-row" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_family">
        <?php if ($editingFamily): ?><input type="hidden" name="existing_family_id" value="<?= h($editingFamily['family_id']) ?>"><?php endif; ?>
        <div class="fg"><label>Family ID</label>
            <select name="family_id" required <?= $editingFamily ? 'disabled' : '' ?>>
                <?php foreach (PLUSCITIES_FAMILY_IDS as $fid): ?>
                    <option value="<?= h($fid) ?>" <?= ($editingFamily['family_id'] ?? '') === $fid ? 'selected' : '' ?>><?= h($fid) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($editingFamily): ?><input type="hidden" name="family_id" value="<?= h($editingFamily['family_id']) ?>"><?php endif; ?>
        </div>
        <div class="fg"><label>Label</label><input type="text" name="label" value="<?= h($editingFamily['label'] ?? '') ?>"></div>
        <div class="fg"><label>Schema Type Family</label><input type="text" name="schema_type_family" placeholder="e.g. LocalBusiness" value="<?= h($editingFamily['schema_type_family'] ?? '') ?>"></div>
        <div class="fg"><label>Compliance Preset</label>
            <select name="compliance_preset">
                <option value="">—</option>
                <?php foreach ($presets as $p): ?>
                    <option value="<?= h($p['preset_id']) ?>" <?= ($editingFamily['compliance_preset'] ?? '') === $p['preset_id'] ? 'selected' : '' ?>><?= h($p['label'] ?: $p['preset_id']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Default Radius (minutes)</label><input type="number" name="default_radius_minutes" value="<?= h($editingFamily['default_radius_minutes'] ?? 25) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Default City Blocks (comma-separated)</label>
            <input type="text" name="default_city_blocks" placeholder="climate_data, housing_stock, neighborhoods, permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city"
                value="<?= h(implode(', ', $editingFamily['default_city_blocks'] ?? [])) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Default Service Blocks (comma-separated)</label>
            <input type="text" name="default_service_blocks" placeholder="common_problems, cited_expert_guidance, niche_data_table, process, cost_factors, why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives"
                value="<?= h(implode(', ', $editingFamily['default_service_blocks'] ?? [])) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Approved Sources (comma-separated domains)</label>
            <input type="text" name="approved_sources" value="<?= h(implode(', ', $editingFamily['approved_sources'] ?? [])) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Research Topics (comma-separated)</label>
            <input type="text" name="research_topics" placeholder="neighborhoods, permits, utilities, hoa, hazards, seasons, civic"
                value="<?= h(implode(', ', $editingFamily['research_topics'] ?? [])) ?>"></div>
        <div style="flex-basis:100%;"><button type="submit" class="btn-open"><?= $editingFamily ? 'Save Family' : 'Add Family' ?></button></div>
    </form>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Niche Blueprints</h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Inherits a family's defaults and adds what's actually niche-specific — services with real,
        validated keywords; a denylist against the family's block pool; extra approved sources.
    </p>
    <?php foreach ($niches as $n): ?>
        <div class="pc-row">
            <div><strong><?= h($n['label'] ?: $n['niche_id']) ?></strong>
                <div style="font-size:.78rem;color:var(--pc-muted,#52607a);"><?= h($n['niche_id']) ?> — family: <?= h($n['family_id']) ?> — <?= count($n['services']) ?> service(s)</div></div>
            <a href="?edit_niche=<?= h($n['niche_id']) ?>" class="btn-sm-outline">Edit</a>
        </div>
    <?php endforeach; ?>
    <form method="post" action="pluscities_niches_save.php" class="pc-form-row" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_niche">
        <?php if ($editingNiche): ?><input type="hidden" name="existing_niche_id" value="<?= h($editingNiche['niche_id']) ?>"><?php endif; ?>
        <div class="fg"><label>Niche ID</label><input type="text" name="niche_id" required placeholder="e.g. hvac"
            value="<?= h($editingNiche['niche_id'] ?? '') ?>" <?= $editingNiche ? 'readonly' : '' ?>></div>
        <div class="fg"><label>Label</label><input type="text" name="label" value="<?= h($editingNiche['label'] ?? '') ?>"></div>
        <div class="fg"><label>Family</label>
            <select name="family_id" required>
                <?php foreach (PLUSCITIES_FAMILY_IDS as $fid): ?>
                    <option value="<?= h($fid) ?>" <?= ($editingNiche['family_id'] ?? '') === $fid ? 'selected' : '' ?>><?= h($fid) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Schema Subtype</label><input type="text" name="schema_subtype" placeholder="e.g. HVACBusiness" value="<?= h($editingNiche['schema_subtype'] ?? '') ?>"></div>
        <div class="fg"><label>Service Noun</label><input type="text" name="service_noun" value="<?= h($editingNiche['service_noun'] ?? '') ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Local Angle</label><input type="text" name="local_angle" value="<?= h($editingNiche['local_angle'] ?? '') ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Seasonal Calendar</label><input type="text" name="seasonal_calendar" value="<?= h($editingNiche['seasonal_calendar'] ?? '') ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Blocks Off (denylist against family's city pool, comma-separated)</label>
            <input type="text" name="blocks_off" value="<?= h(implode(', ', $editingNiche['blocks_off'] ?? [])) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Service Blocks Off (denylist against family's service pool, comma-separated)</label>
            <input type="text" name="service_blocks_off" value="<?= h(implode(', ', $editingNiche['service_blocks_off'] ?? [])) ?>"></div>

        <h4 style="flex-basis:100%;margin:10px 0 0;">Services</h4>
        <p style="flex-basis:100%;font-size:.78rem;color:var(--pc-muted,#52607a);margin:0;">
            One per line: <code>slug|label|primary_keyword</code>. Secondary keywords and real keyword
            data are managed once a service exists (Validate Keyword button appears after saving).
        </p>
        <div class="fg" style="flex-basis:100%;">
            <textarea name="services_lines" style="min-height:70px;" placeholder="ac-repair|AC Repair|ac repair"><?php
                foreach (($editingNiche['services'] ?? []) as $svc) {
                    echo h(($svc['slug'] ?? '') . '|' . ($svc['label'] ?? '') . '|' . ($svc['primary_keyword'] ?? '')) . "\n";
                }
            ?></textarea>
        </div>
        <div style="flex-basis:100%;"><button type="submit" class="btn-open"><?= $editingNiche ? 'Save Niche' : 'Add Niche' ?></button></div>
    </form>

    <?php if ($editingNiche): ?>
        <h4 style="margin-top:18px;">Images</h4>
        <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:0 0 8px;">
            The default photo every client in this niche gets until they upload their own (see
            <code>pluscities_resolve_image_url()</code>) — same slot concept as a client's own Photos screen,
            one level up.
        </p>
        <?php if (!$editingNiche['image_slots']): ?>
            <p style="font-size:.84rem;color:var(--pc-muted,#52607a);">No image slots yet — add one below.</p>
        <?php else: ?>
        <div class="pc-photo-grid" style="margin-bottom:14px;">
            <?php foreach ($editingNiche['image_slots'] as $slot):
                $imgPath = $slot['default_image_path'] ?? '';
                $imgUrl  = $imgPath ? '/' . ltrim($imgPath, '/') : '';
            ?>
            <div class="pc-photo-tile">
                <?php if ($imgUrl): ?><img src="<?= h($imgUrl) ?>" alt="<?= h($slot['label']) ?>"><?php endif; ?>
                <div class="caption"><strong><?= h($slot['label']) ?></strong> — <?= $imgUrl ? 'default set' : 'no image yet' ?></div>
                <div class="actions">
                    <form method="post" action="pluscities_niches_save.php" enctype="multipart/form-data" style="display:flex;gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="upload_niche_image">
                        <input type="hidden" name="niche_id" value="<?= h($editingNiche['niche_id']) ?>">
                        <input type="hidden" name="slot_id" value="<?= h($slot['slot_id']) ?>">
                        <input type="file" name="image_file" accept="image/png,image/jpeg,image/webp" required style="font-size:.76rem;max-width:140px;">
                        <button type="submit" class="btn-sm-outline">Upload</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
            // Tied to a real block type, not a free-text name — a slot only
            // ever gets used by pluscities_resolve_image_url() because some
            // block's render code looks up its own block_type as the slot_id
            // (today, only Hero does), so "add a slot" really means "pick
            // which block this niche's clients will get a default photo for."
            $usedSlotIds  = array_column($editingNiche['image_slots'], 'slot_id');
            $blockOptions = [];
            foreach (platform_info_city_page_blocks_data() as $section) {
                foreach ($section['rows'] as $r) {
                    if (!in_array($r['slug'], $usedSlotIds, true)) $blockOptions[$r['slug']] = $r['block'];
                }
            }
        ?>
        <?php if ($blockOptions): ?>
        <form method="post" action="pluscities_niches_save.php" class="pc-form-row">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_image_slot">
            <input type="hidden" name="niche_id" value="<?= h($editingNiche['niche_id']) ?>">
            <div class="fg"><label>Add a default photo for block</label>
                <select name="slot_id" required>
                    <?php foreach ($blockOptions as $slug => $label): ?>
                        <option value="<?= h($slug) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="align-self:flex-end;"><button type="submit" class="btn-sm-outline">Add Image Slot</button></div>
        </form>
        <?php endif; ?>

        <h4 style="margin-top:18px;">Validate a service's keyword</h4>
        <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:0 0 8px;">
            Runs once, against real search data, using the same shared keyword-scoring module the fleet
            already uses (<code>infra_kw_score()</code>) — not a per-page cost.
        </p>
        <?php foreach ($editingNiche['services'] as $svc): ?>
            <form method="post" action="pluscities_niches_save.php" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="validate_keyword">
                <input type="hidden" name="niche_id" value="<?= h($editingNiche['niche_id']) ?>">
                <input type="hidden" name="service_slug" value="<?= h($svc['slug']) ?>">
                <span style="min-width:160px;"><?= h($svc['label']) ?></span>
                <span style="font-size:.78rem;color:var(--pc-muted,#52607a);">
                    <?= !empty($svc['primary_keyword_data'])
                        ? 'score ' . h((string) $svc['primary_keyword_data']['score']) . '/10 (checked ' . h($svc['primary_keyword_data']['checked_at']) . ')'
                        : 'not yet validated' ?>
                </span>
                <button type="submit" class="btn-sm-outline">Validate Keyword</button>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php lpc_admin_close(); ?>
