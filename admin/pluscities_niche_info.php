<?php
/**
 * admin/pluscities_niche_info.php — read-only per-niche info sheet
 * (2026-11-26). Every real niche's full built-out content in one view,
 * grouped by family: services + real keyword data, the shared niche_table,
 * trust bar, safety toolkit, resolved block composition (Always/Default/
 * Optional/Excluded — same 4 buckets as the Family to Default Blocks tab,
 * scoped to this niche's actual resolved pool), and the compliance preset
 * actually in effect.
 *
 * Deliberately separate from the Niche Library's own edit form
 * (admin/pluscities_niches.php) — that screen is for authoring raw fields;
 * this one is for seeing what a niche actually amounts to once resolved,
 * same reasoning docs/pluscities-building-a-niche-blueprint.md's own
 * "prove it with one real client" step exists for.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: pluscities_clients.php?msg=error:The+niche+library+is+managed+by+the+platform+only');
    exit;
}

$pcCatalog = [];
foreach (platform_info_city_page_blocks_data() as $pcSection) {
    foreach ($pcSection['rows'] as $pcRow) {
        if (!empty($pcRow['slug'])) $pcCatalog[$pcRow['slug']] = $pcRow;
    }
}

// Group real niches by family, in the family's own declared order — not
// alphabetical — so Home-Trades (the largest, most proven bucket) leads.
$pcNichesByFamily = array_fill_keys(PLUSCITIES_FAMILY_IDS, []);
foreach (pluscities_niche_list() as $pcNicheId) {
    $pcNiche = pluscities_niche_load($pcNicheId);
    if (!$pcNiche) continue;
    $pcNichesByFamily[$pcNiche['family_id']][] = $pcNiche;
}

$activeNiche = $_GET['niche'] ?? '';
if ($activeNiche === '') {
    foreach ($pcNichesByFamily as $fam => $list) { if ($list) { $activeNiche = $list[0]['niche_id']; break; } }
}
$resolved = $activeNiche !== '' ? pluscities_niche_resolved($activeNiche) : null;
$familyData = $resolved ? pluscities_family_load($resolved['family_id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Niche Information — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php lpc_admin_open('pluscities_niche_info', 'Niche Information'); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-fam-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#52607a;margin:14px 0 6px;}
.pc-fam-label:first-child{margin-top:0;}
.pc-niche-tab{display:inline-block;margin:0 8px 8px 0;padding:7px 14px;border-radius:20px;border:1px solid var(--pc-line,#e2e8f2);font-size:.84rem;font-weight:600;text-decoration:none;color:#374151;background:#f8fafc;}
.pc-niche-tab.is-active{background:var(--pc-lime,#c8f55a);color:var(--pc-navy,#0b1b33);border-color:var(--pc-lime,#c8f55a);}
.pc-niche-tab.is-empty{color:#9ca3af;font-weight:400;font-style:italic;background:none;border-style:dashed;}
.pc-bucket{font-size:.76rem;line-height:1.6;}
table.pc-info-table{width:100%;border-collapse:collapse;font-size:.82rem;}
table.pc-info-table th,table.pc-info-table td{text-align:left;padding:6px 10px;border-bottom:1px solid #f1f5f9;}
table.pc-info-table th{color:#52607a;font-weight:600;font-size:.72rem;text-transform:uppercase;}
.pc-score{font-weight:700;padding:1px 7px;border-radius:9px;font-size:.74rem;}
.pc-score-hi{background:#dcfce7;color:#166534;}
.pc-score-mid{background:#fef3c7;color:#92400e;}
.pc-score-lo{background:#fee2e2;color:#991b1b;}
</style>

<div class="pc-card">
    <h3 style="margin-top:0;">Niches, by family</h3>
    <?php foreach ($pcNichesByFamily as $famId => $list): ?>
        <div class="pc-fam-label"><?= h(ucwords(str_replace('-', ' ', $famId))) ?></div>
        <?php if (!$list): ?>
            <span class="pc-niche-tab is-empty">none built yet</span><br>
        <?php else: foreach ($list as $n): ?>
            <a class="pc-niche-tab<?= $n['niche_id'] === $activeNiche ? ' is-active' : '' ?>"
               href="?niche=<?= h($n['niche_id']) ?>"><?= h($n['label'] ?: $n['niche_id']) ?></a>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
</div>

<?php if (!$resolved): ?>
    <div class="lpc-empty"><h2>No niches built yet</h2><p>Build one from the Niche Library first.</p></div>
<?php else: ?>
    <div class="pc-card">
        <h3 style="margin-top:0;"><?= h($resolved['label']) ?>
            <span style="font-size:.78rem;font-weight:400;color:#52607a;">
                — <?= h($resolved['family_id']) ?> family, <code><?= h($resolved['niche_id']) ?></code>
            </span>
        </h3>
        <?php
        $heroSlot = null;
        foreach (($familyData ? pluscities_niche_load($activeNiche)['image_slots'] ?? [] : []) as $slot) {
            if ($slot['slot_id'] === 'hero' && !empty($slot['default_image_path'])) { $heroSlot = $slot; break; }
        }
        ?>
        <?php if ($heroSlot): ?>
            <img src="../<?= h($heroSlot['default_image_path']) ?>" alt="" style="max-width:360px;border-radius:10px;margin-bottom:14px;display:block;">
        <?php endif; ?>
        <p style="font-size:.9rem;line-height:1.7;color:#1C2B45;margin:0 0 14px;">
            <strong><?= h($resolved['label']) ?></strong> is a real niche in the
            <strong><?= h(ucwords(str_replace('-', ' ', $resolved['family_id']))) ?></strong> family —
            <?= count($resolved['services']) ?> real service<?= count($resolved['services']) === 1 ? '' : 's' ?>
            offered within a <?= h($resolved['radius_minutes']) ?>-minute service radius of each client's hub.
            <?php if ($resolved['local_angle']): ?><?= h($resolved['local_angle']) ?><?php endif; ?>
            <?php if ($resolved['seasonal_calendar']): ?> <?= h($resolved['seasonal_calendar']) ?><?php endif; ?>
        </p>
        <table class="pc-info-table">
            <tr><th style="width:200px;">Schema type</th><td><?= h($resolved['schema_subtype'] ?: '—') ?></td></tr>
            <tr><th>Service noun</th><td><?= h($resolved['service_noun'] ?: '—') ?></td></tr>
            <tr><th>Local angle</th><td><?= h($resolved['local_angle'] ?: '—') ?></td></tr>
            <tr><th>Seasonal calendar</th><td><?= h($resolved['seasonal_calendar'] ?: '—') ?></td></tr>
            <tr><th>Service radius</th><td><?= h($resolved['radius_minutes']) ?> minutes</td></tr>
            <tr><th>Compliance preset</th><td><?= h($resolved['compliance_preset']['label'] ?? '—') ?>
                <?php if (!empty($resolved['compliance_preset']['banned_phrases'])): ?>
                    <div style="color:#6b7280;font-size:.78rem;margin-top:2px;">Banned phrases: <?= h(implode(', ', $resolved['compliance_preset']['banned_phrases'])) ?></div>
                <?php endif; ?>
            </td></tr>
        </table>
    </div>

    <div class="pc-card">
        <h3 style="margin-top:0;">Services (<?= count($resolved['services']) ?>) — real keyword data</h3>
        <table class="pc-info-table">
            <tr><th>Service</th><th>Primary keyword</th><th>Volume</th><th>KD</th><th>CPC</th><th>Score</th></tr>
            <?php foreach ($resolved['services'] as $svc): $d = $svc['primary_keyword_data'] ?? null; $score = $d['score'] ?? null; ?>
            <tr>
                <td><?= h($svc['label']) ?></td>
                <td><?= h($svc['primary_keyword']) ?></td>
                <td><?= h($d['volume'] ?? '—') ?></td>
                <td><?= h($d['kd'] ?? '—') ?></td>
                <td><?= $d['cpc'] ?? null ? '$' . h($d['cpc']) : '—' ?></td>
                <td><?php if ($score !== null):
                    $cls = $score >= 8 ? 'pc-score-hi' : ($score >= 5 ? 'pc-score-mid' : 'pc-score-lo'); ?>
                    <span class="pc-score <?= $cls ?>"><?= h($score) ?>/10</span>
                <?php else: ?>not validated<?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php
        $validated = array_filter($resolved['services'], fn($s) => ($s['primary_keyword_data']['score'] ?? null) !== null);
        if ($validated) {
            $totalVol = array_sum(array_map(fn($s) => (int) ($s['primary_keyword_data']['volume'] ?? 0), $validated));
            $avgScore = round(array_sum(array_map(fn($s) => (int) $s['primary_keyword_data']['score'], $validated)) / count($validated), 1);
            usort($validated, fn($a, $b) => $b['primary_keyword_data']['score'] <=> $a['primary_keyword_data']['score']);
            $best = $validated[0];
        ?>
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;font-size:.82rem;color:#374151;">
            <strong><?= count($validated) ?> of <?= count($resolved['services']) ?></strong> validated ·
            combined monthly search volume <strong><?= number_format($totalVol) ?></strong> ·
            average score <strong><?= $avgScore ?>/10</strong> ·
            best-scoring service: <strong><?= h($best['label']) ?></strong> (<?= h($best['primary_keyword_data']['score']) ?>/10)
        </div>
        <?php } ?>
    </div>

    <?php
    $pcNicheClients = [];
    foreach (glob(PLUSCITIES_CLIENTS_DIR . '*.json') ?: [] as $f) {
        $c = json_decode((string) @file_get_contents($f), true);
        if (($c['niche_id'] ?? '') === $activeNiche) $pcNicheClients[] = $c;
    }
    ?>
    <div class="pc-card">
        <h3 style="margin-top:0;">Clients in this niche (<?= count($pcNicheClients) ?>)</h3>
        <?php if (!$pcNicheClients): ?>
            <p style="font-size:.84rem;color:#6b7280;">No real clients in this niche yet.</p>
        <?php else: foreach ($pcNicheClients as $c): ?>
            <div class="pc-bucket" style="padding:4px 0;">
                <a href="pluscities_client_overview.php?client_id=<?= h($c['client_id']) ?>"><?= h($c['identity']['business_name'] ?? $c['client_id']) ?></a>
                — <?= h($c['domain'] ?? '') ?> · <?= count($c['target_cities'] ?? []) ?> cit<?= count($c['target_cities'] ?? []) === 1 ? 'y' : 'ies' ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="pc-card">
        <h3 style="margin-top:0;">Block composition for this niche</h3>
        <p style="font-size:.78rem;color:#6b7280;margin:0 0 10px;">Always = auto-included, never swappable · Default = happy-path, pre-checked but swappable · Optional = in the pool, not pre-selected · Excluded = not in this family's pool at all.</p>
        <div class="pc-bucket"><strong>City</strong> —
            Always: <?= h(implode(', ', $resolved['always_city_blocks']) ?: '—') ?> ·
            Default: <?= h(implode(', ', $resolved['happy_path_city_blocks']) ?: '—') ?> ·
            Optional: <?= h(implode(', ', array_diff($resolved['city_blocks'], $resolved['happy_path_city_blocks'])) ?: '—') ?>
        </div>
        <div class="pc-bucket" style="margin-top:6px;"><strong>Service</strong> —
            Always: <?= h(implode(', ', $resolved['always_service_blocks']) ?: '—') ?> ·
            Default: <?= h(implode(', ', $resolved['happy_path_service_blocks']) ?: '—') ?> ·
            Optional: <?= h(implode(', ', array_diff($resolved['service_blocks'], $resolved['happy_path_service_blocks'])) ?: '—') ?>
        </div>
    </div>

    <?php if (!empty($resolved['niche_table']['rows'])): ?>
    <div class="pc-card">
        <h3 style="margin-top:0;"><?= h($resolved['niche_table']['title'] ?: 'Niche reference table') ?></h3>
        <table class="pc-info-table">
            <tr><?php foreach ($resolved['niche_table']['columns'] as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr>
            <?php foreach ($resolved['niche_table']['rows'] as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?= h($cell) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($resolved['trust_bar_items']) || !empty($resolved['safety_toolkit_items'])): ?>
    <div class="pc-card">
        <?php if (!empty($resolved['trust_bar_items'])): ?>
            <h3 style="margin-top:0;">Trust bar</h3>
            <p style="font-size:.84rem;"><?= h(implode(' · ', $resolved['trust_bar_items'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($resolved['safety_toolkit_items'])): ?>
            <h3>Safety Toolkit items (<?= count($resolved['safety_toolkit_items']) ?> on file — 3 shown per client, deterministic per domain)</h3>
            <?php foreach ($resolved['safety_toolkit_items'] as $item): ?>
                <div style="font-size:.84rem;margin-bottom:8px;"><strong><?= h($item['heading']) ?></strong> — <?= h($item['text']) ?>
                    <?php if (!empty($item['link'])): ?> (<a href="<?= h($item['link']) ?>" target="_blank" rel="noopener"><?= h($item['btn_text'] ?: 'Link') ?></a>)<?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php lpc_admin_close(); ?>
