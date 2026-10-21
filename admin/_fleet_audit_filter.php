<?php
/**
 * The shared fleet-audit scope filter — one bar, read by crawl/index/rank/calls.
 *
 * ⚠ FINGERPRINT IS NOT SCOPED, ON PURPOSE. Its entire job is catching cross-niche /
 * cross-box leaks — a mold domain and an appliance domain sharing a box, or two
 * domains answering each other's hostname. Narrowing it to "just mold" would hide
 * exactly the thing it exists to catch, so it stays above this bar and always reads
 * the whole fleet regardless of what's picked here.
 *
 * Picks are read from $_GET by each of the four scoped cards independently (same
 * convention as admin/infra/views/domains.php's fniche/fready/etc. filters) — this
 * file only renders the control and resolves the two values once, into $faScope,
 * which the cards below read rather than each re-parsing $_GET.
 */
require_once __DIR__ . '/infra/lib/state.php';

$faNiche = infra_niche((string) ($_GET['faniche'] ?? ''));
$faBatch = trim((string) ($_GET['fabatch'] ?? ''));
// A batch from a DIFFERENT niche than the one now selected is stale leftover from
// before the niche changed — drop it rather than silently AND-ing two picks that
// don't belong together (which would just show zero rows with no explanation).
$faBatches = infra_audit_batches();
if ($faBatch !== '' && $faNiche !== '' && !in_array($faBatch, $faBatches[$faNiche] ?? [], true)) {
    $faBatch = '';
}
$faScope = infra_audit_scope($faNiche, $faBatch);
?>
<div class="card" style="margin:0 0 16px;padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#f8fafc">
    <strong style="font-size:.82rem;color:#475569">Scope</strong>
    <form method="get" action="sites.php#fleet-audit" style="display:inline-flex;gap:8px;align-items:center;margin:0">
        <select name="faniche" onchange="this.form.submit()" style="padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
            <option value="">All niches</option>
            <?php foreach (INFRA_NICHES as $nz): if ($nz === 'other') continue; ?>
                <option value="<?= h($nz) ?>" <?= $faNiche === $nz ? 'selected' : '' ?>><?= h(ucfirst($nz)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="fabatch" onchange="this.form.submit()" style="padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
            <option value="">All batches</option>
            <?php
            // Every niche's batches when nothing is picked yet; only the selected
            // niche's own batches once one is — a batch id from a different niche was
            // never a valid combination to begin with.
            $faBatchList = $faNiche !== '' ? ($faBatches[$faNiche] ?? []) : array_merge([], ...array_values($faBatches));
            foreach ($faBatchList as $bid): ?>
                <option value="<?= h($bid) ?>" <?= $faBatch === $bid ? 'selected' : '' ?>><?= h($bid) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($faNiche !== '' || $faBatch !== ''): ?>
            <a href="sites.php#fleet-audit" style="font-size:.78rem;color:#2563eb;text-decoration:none">Clear</a>
        <?php endif; ?>
    </form>
    <span style="font-size:.78rem;color:#94a3b8">
        applies to crawl / index / rank / calls below — fingerprint is always whole-fleet
        <?php if ($faScope !== null): ?>
            &middot; <strong><?= count($faScope) ?></strong> domain<?= count($faScope) === 1 ? '' : 's' ?> in scope
        <?php endif; ?>
    </span>
</div>
