<?php
/**
 * Fleet size — how many sites are live right now, and how many went live each
 * week. Sits above crawl/index/rank/calls so their numbers have growth context:
 * crawl hits or call volume climbing could be real per-site improvement, or it
 * could just be more sites — this card is the thing that tells the two apart.
 *
 * ⚠ ONE PLACE ONLY, same reasoning as the other fleet boxes. Do not add it to
 * batch.php or rollout.php.
 *
 * No "run a check" button, unlike crawl/index/rank/calls — there's no sweep to
 * trigger. A domain's go-live moment is already recorded the instant it happens
 * (infra_golive_refresh_live(), admin/infra/lib/golive.php); this card only
 * reads that existing record via fleet_trends.php, same read-only relationship
 * every other card in this section has with its own module.
 *
 * Deliberately SCOPED by the niche/batch filter above, unlike fingerprint (which
 * is deliberately NOT scoped — see _fleet_fingerprint.php). "12 new sites, 401
 * total" means something different per niche, and that's the point: this card
 * is meant to be read next to whichever of crawl/index/rank/calls is scoped the
 * same way, not as an independent whole-fleet fact.
 */

require_once __DIR__ . '/infra/lib/fleet_trends.php';

$fszWeeks = $faWeeks ?? 8;
$fsz = fleet_trend_live_sites($faScope ?? null, $fszWeeks);
?>
<div id="fsz" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid #1e3a8a">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet size</strong>
        <span style="font-size:.85rem">
            <strong><?= (int) $fsz['total_live'] ?></strong>
            <span style="color:#64748b">site<?= $fsz['total_live'] === 1 ? '' : 's' ?> live right now<?= $faScope !== null ? ' (in scope)' : ', fleet-wide' ?></span>
        </span>
    </div>
    <details open style="margin-top:8px">
        <summary class="fa-sum">Trend (<?= (int) $fszWeeks ?> weeks) &mdash; new sites gone live, per week</summary>
        <div style="margin:8px 0 4px"><?= fleet_trend_svg($fsz['new_live'], ['color' => '#1e3a8a']) ?></div>
        <div style="font:.78rem/1.6 ui-monospace,monospace;color:#475569;max-height:160px;overflow:auto">
            <?php foreach ($fsz['log'] as $fszLogLine): ?>
                <div><strong><?= h($fszLogLine['week']) ?>:</strong> <?= h($fszLogLine['text']) ?></div>
            <?php endforeach; ?>
        </div>
    </details>
</div>
