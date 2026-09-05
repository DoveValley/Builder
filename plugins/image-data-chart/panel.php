<?php
/**
 * Image · Data Chart — plugin panel.
 *
 * What it is, how it works, which charts THIS site's niche defines, and a live sample.
 *
 * The sample uses clearly-labelled stand-in numbers when the site's cities have no researched
 * figures — which is every city today. That labelling is not decoration: the whole design rule
 * is that a chart is never drawn from estimates, so a sample that looked like real data would
 * contradict the plugin it is documenting.
 *
 * Read-only: draws in memory, writes nothing.
 */

require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/draw.php';

$pcH = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$pcNiche = defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR ? city_chart_niche(ACTIVE_SITE_DIR) : '';
$pcDefs  = city_chart_definitions($pcNiche);

// A real city from this site if there is one, else a stand-in, so the sample always shows.
$pcCity = ['city' => 'Your City', 'SS' => 'ST'];
if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
    $rows = json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/data/cities.json'), true);
    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (is_array($row) && trim((string) ($row['city'] ?? '')) !== '') { $pcCity = $row; break; }
    }
}
$pcDef = $pcDefs ? reset($pcDefs) : null;

// Does this city have real figures for that chart?
$pcSeries = $pcDef ? city_chart_series($pcDef, $pcCity) : null;
$pcIsReal = $pcSeries !== null;
if ($pcDef && !$pcIsReal) {
    $pcSample = array_merge($pcCity, [
        (string) $pcDef['data_key'] => [4.6, 4.2, 4.1, 3.8, 5.2, 5.0, 3.1, 2.7, 4.0, 5.1, 4.7, 5.0],
        (string) ($pcDef['source_key'] ?? 'src') => 'STAND-IN NUMBERS — not researched',
    ]);
    $pcSeries = city_chart_series($pcDef, $pcSample);
    $pcCity = $pcSample;
}
?>
<style>.pc-sample svg{width:100%;height:auto;display:block}</style>
<div class="card">
    <h2>&#128202; Image &middot; Data Chart</h2>
    <p class="hint" style="margin-bottom:16px;">Per-city charts drawn from that city's own figures. One engine; each niche declares what it charts.</p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">What it does</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Turns a city's own numbers into a chart &mdash; rainfall by month, freezing days, pest season, whatever the niche cares about.</li>
        <li>Different city, different figures, <strong>different picture</strong>. It can never be shared between sites the way a stock photo is.</li>
        <li>A chart is the <strong>right medium</strong> for this: twelve numbers nobody reads as prose, whose shape a person reads instantly.</li>
        <li><strong>Writes its own alt text</strong> from the figures &mdash; "Highest May at 5.2 in, lowest Aug at 2.7 in."</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">How it works</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Each chart is a JSON file <strong>inside this plugin</strong>: <code>niches/{niche}/{chart}.json</code>. Adding one is a file, adding a niche is a folder &mdash; never code.</li>
        <li>The niche comes from this site's own <code>niche_brief.json</code>, so a water site gets water charts and a pest site gets pest charts with nothing to set per site.</li>
        <li>Each chart becomes a token pair: <code>{chart_ID}</code> and <code>{chart_ID_alt}</code>.</li>
        <li>Drawn <strong>once per city and cached</strong>, then rasterised to WebP so it stays an ordinary indexable image.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">The rule that matters</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><strong>No data means no chart.</strong> A city without researched figures is skipped &mdash; never drawn from estimates or averages.</li>
        <li>A chart makes a number look authoritative. An invented one is worse than no chart at all, on a page advising someone about their home.</li>
        <li>The citation is drawn <em>onto the image</em>, because a chart travels away from the page it was published on.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">Charts defined for this site</h3>
    <?php if (!$pcNiche): ?>
        <p class="hint">This site has no niche set in <code>niche_brief.json</code>, so no charts apply.</p>
    <?php elseif (!$pcDefs): ?>
        <p class="hint">Niche <code><?= $pcH($pcNiche) ?></code> has no charts yet. Add one at
        <code>plugins/image-data-chart/niches/<?= $pcH($pcNiche) ?>/</code> and it appears here.</p>
    <?php else: ?>
        <table style="font-size:.86rem;border-collapse:collapse;">
        <?php foreach ($pcDefs as $id => $d): ?>
            <tr>
                <td style="padding:3px 14px 3px 0;"><code>{chart_<?= $pcH($id) ?>}</code></td>
                <td style="padding:3px 14px 3px 0;color:#334155;"><?= $pcH($d['name'] ?? $id) ?></td>
                <td style="padding:3px 0;color:#94a3b8;">reads <code><?= $pcH($d['data_key'] ?? '?') ?></code></td>
            </tr>
        <?php endforeach; ?>
        </table>
        <p class="hint" style="margin-top:6px;">Niche: <code><?= $pcH($pcNiche) ?></code></p>
    <?php endif; ?>

    <?php if ($pcDef && $pcSeries): ?>
        <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">Sample &mdash; <code><?= $pcH($pcDef['id']) ?></code></h3>
        <?php if (!$pcIsReal): ?>
            <p class="hint" style="max-width:640px;color:#92400e;"><strong>Stand-in numbers.</strong> No city on this site has researched figures for this chart yet, so the shape below is illustrative. With real data it draws the same way; without it, the chart is skipped entirely.</p>
        <?php endif; ?>
        <div class="pc-sample" style="max-width:640px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
            <?= city_chart_svg($pcSeries, $pcDef, city_chart_text((string) ($pcDef['title'] ?? ''), $pcCity, $pcDef, $pcSeries)) ?>
        </div>
        <p class="hint" style="margin-top:8px;max-width:640px;"><strong>alt:</strong>
            <?= $pcH(city_chart_text((string) ($pcDef['alt'] ?? ''), $pcCity, $pcDef, $pcSeries)) ?></p>
    <?php endif; ?>
</div>
