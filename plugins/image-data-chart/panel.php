<?php
/**
 * Image · Data Chart — plugin panel.
 *
 * What it is, every parameter a definition accepts, and a gallery of every chart THIS site's
 * niche defines — drawn live from real city data where it exists.
 *
 * A chart with no figures is shown as "no figures yet", never with stand-in numbers. An earlier
 * version drew a sample from invented data and it immediately produced a nonsense chart
 * (rainfall inches under a freezing-days title), which is exactly what the plugin's own rule
 * warns against. Documentation that contradicts the thing it documents is worse than none.
 *
 * Read-only: draws in memory, writes nothing.
 */

require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/draw.php';

$pcH = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$pcNiche = defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR ? city_chart_niche(ACTIVE_SITE_DIR) : '';
$pcDefs  = city_chart_definitions($pcNiche);

$pcCities = [];
if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
    $rows = json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/data/cities.json'), true);
    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (is_array($row) && trim((string) ($row['city'] ?? '')) !== '') $pcCities[] = $row;
    }
}

/**
 * Reference samples — one per TYPE, so the panel shows what each actually draws even on a site
 * whose cities have no researched figures yet (which is most of them today).
 *
 * These use REAL, SOURCED NOAA figures for one real city, not invented numbers. That matters:
 * this plugin's rule is that a chart is never drawn from made-up data, and a sample built from
 * made-up data would contradict the rule on the same screen. An earlier version did exactly
 * that and drew rainfall inches under a freezing-days title.
 */
$pcRefCity = [
    'city' => 'Lufkin', 'SS' => 'TX', 'state' => 'Texas',
    'rainfall_monthly' => [4.31, 3.74, 3.86, 3.49, 5.12, 4.72, 3.44, 3.02, 4.16, 4.53, 4.62, 4.72],
    'freeze_days_monthly' => [8, 5, 1, 0, 0, 0, 0, 0, 0, 0, 2, 6],
    'rainfall_annual' => 49.7,
    'noaa' => 'NOAA 1991-2020 Climate Normals (Angelina County Airport station)',
];
$pcMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$pcTypes = [
    [
        'type' => 'bars',
        'note' => 'A labelled series &mdash; twelve numbers whose <em>shape</em> reads instantly. '
                . 'Tallest and shortest bars are called out, and the citation is drawn on the image.',
        'def'  => ['id' => 'rainfall', 'type' => 'bars', 'data_key' => 'rainfall_monthly',
                   'source_key' => 'noaa', 'labels' => $pcMonths, 'unit' => 'in',
                   'title' => 'Average monthly rainfall in {city}, {SS}',
                   'alt' => 'Chart of average monthly rainfall in {city}, {SS}, in inches. {summary}'],
    ],
    [
        'type' => 'bars',
        'note' => 'The same type with a different series and unit. Months at zero draw as zero &mdash; '
                . 'a flat stretch is information, not a gap.',
        'def'  => ['id' => 'freeze_days', 'type' => 'bars', 'data_key' => 'freeze_days_monthly',
                   'source_key' => 'noaa', 'labels' => $pcMonths, 'unit' => 'days',
                   'title' => 'Days below freezing each month in {city}, {SS}',
                   'alt' => 'Chart of days below freezing per month in {city}, {SS}. {summary}'],
    ],
    [
        'type' => 'compare',
        'note' => 'Horizontal, this city highlighted against benchmarks, with a headline <strong>computed '
                . 'from the figures</strong> rather than asserted. A lone number is a fact; a number against '
                . 'a benchmark is an argument.',
        'def'  => ['id' => 'rainfall_vs_average', 'type' => 'compare', 'data_key' => 'rainfall_annual',
                   'source_key' => 'noaa', 'self_label' => '{city}, {SS}', 'unit' => 'in',
                   'benchmarks' => [['label' => 'US average', 'value' => 30.3, 'source' => 'NOAA 1991-2020 US normals']],
                   'title' => 'Annual rainfall: {city} vs average',
                   'alt' => 'Chart comparing annual rainfall in {city}, {SS} with the national average. {summary}'],
    ],
];

/** Definition parameters, documented once here so the panel IS the reference. */
$pcParams = [
    ['id',         'yes', 'Token-safe name. Becomes <code>{chart_ID}</code> and <code>{chart_ID_alt}</code>.'],
    ['type',       'no',  '<code>bars</code> (default) for a series, or <code>compare</code> for this city against benchmarks.'],
    ['name',       'no',  'Human label, shown in this panel.'],
    ['data_key',   'yes', 'The field read from the city\'s row in <code>cities.json</code>. No value there means no chart.'],
    ['source_key', 'no',  'The field holding the citation. Strongly recommended &mdash; the citation is drawn onto the image.'],
    ['labels',     'no',  'Bar labels, e.g. the twelve months. Ignored if the data is a {label: value} object.'],
    ['unit',       'no',  'Appended to values: <code>in</code>, <code>days</code>. Leave empty for a bare count.'],
    ['title',      'no',  'Heading on the image. Accepts <code>{city}</code> <code>{SS}</code> <code>{state}</code>.'],
    ['alt',        'no',  'Alt text. Same tokens plus <code>{summary}</code>, which is written from the figures.'],
    ['research',   'no',  '<code>{ask, source_ask}</code> &mdash; what the city research should gather. Adding this is what makes the data appear.'],
    ['self_label', 'no',  '<em>compare only.</em> Label for this city\'s own bar. Default <code>{city}</code>.'],
    ['benchmarks', 'no',  '<em>compare only.</em> List of <code>{label, value}</code> or <code>{label, value_key}</code>. A per-city field beats a constant.'],
];
?>
<style>.pc-sample svg{width:100%;height:auto;display:block}
.pc-tbl{font-size:.84rem;border-collapse:collapse;width:100%;max-width:820px}
.pc-tbl td{padding:5px 10px 5px 0;border-top:1px solid #eef2f7;vertical-align:top;color:#334155}
.pc-tbl td:first-child{white-space:nowrap;font-family:ui-monospace,monospace;color:#1e3a5f;font-weight:600}
.pc-req{color:#b91c1c;font-size:.74rem;font-weight:700}
.pc-opt{color:#94a3b8;font-size:.74rem}
.pc-code{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:12px 14px;font-size:.78rem;line-height:1.55;overflow:auto;max-width:820px}
.pc-code .k{color:#7dd3fc}.pc-code .c{color:#64748b}
</style>
<div class="card">
    <h2>&#128202; Image &middot; Data Chart</h2>
    <p class="hint" style="margin-bottom:16px;">Per-city charts drawn from that city's own figures. One engine; each niche declares what it charts.</p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">What it does</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Turns a city's own numbers into a chart &mdash; rainfall by month, freezing days, pest season, whatever the niche cares about.</li>
        <li>Different city, different figures, <strong>different picture</strong>. It can never be shared between sites the way a stock photo is.</li>
        <li>A chart is the <strong>right medium</strong> for this: twelve numbers nobody reads as prose, whose shape a person reads instantly.</li>
        <li><strong>Writes its own alt text</strong> from the figures &mdash; "Highest May at 5.1 in, lowest Aug at 3 in."</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">How it works</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Each chart is a JSON file <strong>inside this plugin</strong>: <code>niches/{niche}/{chart}.json</code>. Adding one is a file, adding a niche is a folder &mdash; never code.</li>
        <li>The niche comes from this site's own <code>niche_brief.json</code>, so a water site gets water charts and a pest site gets pest charts with nothing to set per site.</li>
        <li>The figures come from <strong>city research</strong>: a definition's <code>research</code> block is appended to this niche's research prompt automatically, so adding a chart makes the research start gathering its data.</li>
        <li>Drawn <strong>once per city and cached</strong>, then rasterised to WebP so it stays an ordinary indexable image.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">The rule that matters</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><strong>No data means no chart.</strong> A city without researched figures is skipped &mdash; never drawn from estimates or averages.</li>
        <li>A figure returned <strong>without a source is discarded</strong>, because the chart cannot be drawn without one.</li>
        <li>A field the research declines twice is left alone &mdash; some cities have no published figure, and asking forever re-bills a question already answered.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:22px 0 6px;">What each type draws</h3>
    <p class="hint" style="max-width:820px;margin-bottom:14px;">Real NOAA figures for one real city, so these samples obey the same rule the charts do.
        Change <code>type</code> in a definition and this is what you get.</p>
    <?php foreach ($pcTypes as $i => $t):
        $s = city_chart_series($t['def'], $pcRefCity); ?>
        <div style="margin-bottom:24px;">
            <div style="font-weight:600;color:#1e3a5f;font-size:.9rem;">
                <code>"type": "<?= $pcH($t['type']) ?>"</code>
                <span style="color:#94a3b8;font-weight:400;">&middot; <?= $pcH($t['def']['data_key']) ?><?= $t['def']['unit'] ? ' (' . $pcH($t['def']['unit']) . ')' : '' ?></span>
            </div>
            <p class="hint" style="margin:3px 0 8px;max-width:660px;"><?= $t['note'] ?></p>
            <?php if ($s): ?>
                <div class="pc-sample" style="max-width:660px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                    <?= city_chart_svg($s, $t['def'], city_chart_text($t['def']['title'], $pcRefCity, $t['def'], $s)) ?>
                </div>
                <p class="hint" style="margin-top:6px;max-width:660px;"><strong>alt:</strong>
                    <?= $pcH(city_chart_text($t['def']['alt'], $pcRefCity, $t['def'], $s)) ?></p>
            <?php else: ?>
                <p class="hint" style="color:#b91c1c;">Sample failed to build &mdash; the drawing code and this panel have gone out of step.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">Definition parameters</h3>
    <table class="pc-tbl">
        <?php foreach ($pcParams as [$k, $req, $desc]): ?>
        <tr>
            <td><?= $pcH($k) ?></td>
            <td style="white-space:nowrap;"><span class="<?= $req === 'yes' ? 'pc-req' : 'pc-opt' ?>"><?= $req === 'yes' ? 'required' : 'optional' ?></span></td>
            <td><?= $desc ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">Example &mdash; a <code>bars</code> chart</h3>
    <pre class="pc-code">{
  <span class="k">"id"</span>: "rainfall",                  <span class="c">// -&gt; {chart_rainfall}</span>
  <span class="k">"type"</span>: "bars",
  <span class="k">"data_key"</span>: "rainfall_monthly",     <span class="c">// 12 numbers in the city's row</span>
  <span class="k">"source_key"</span>: "rainfall_source",
  <span class="k">"labels"</span>: ["Jan","Feb", ... ,"Dec"],
  <span class="k">"unit"</span>: "in",
  <span class="k">"title"</span>: "Average monthly rainfall in {city}, {SS}",
  <span class="k">"alt"</span>: "Monthly rainfall for {city}, {SS}. {summary}",
  <span class="k">"research"</span>: {                       <span class="c">// appended to the research prompt</span>
    <span class="k">"ask"</span>: "\"rainfall_monthly\" — 12 monthly figures, inches",
    <span class="k">"source_ask"</span>: "\"rainfall_source\" — the source"
  }
}</pre>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">Example &mdash; a <code>compare</code> chart</h3>
    <pre class="pc-code">{
  <span class="k">"id"</span>: "rainfall_vs_average",
  <span class="k">"type"</span>: "compare",
  <span class="k">"data_key"</span>: "rainfall_annual",      <span class="c">// ONE number for this city</span>
  <span class="k">"self_label"</span>: "{city}, {SS}",
  <span class="k">"benchmarks"</span>: [
    { <span class="k">"label"</span>: "{state} average", <span class="k">"value_key"</span>: "rainfall_state_avg" },   <span class="c">// per-city field</span>
    { <span class="k">"label"</span>: "US average", <span class="k">"value"</span>: 30.3, <span class="k">"source"</span>: "NOAA normals" }  <span class="c">// constant</span>
  ],
  <span class="k">"unit"</span>: "in"
}</pre>
    <p class="hint" style="max-width:820px;">A benchmark with no figure is simply left out. If none survive, the chart is skipped &mdash; one bar on its own is not a comparison.</p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:22px 0 8px;">Charts defined for this site</h3>
    <?php if (!$pcNiche): ?>
        <p class="hint">This site has no niche set in <code>niche_brief.json</code>, so no charts apply.</p>
    <?php elseif (!$pcDefs): ?>
        <p class="hint">Niche <code><?= $pcH($pcNiche) ?></code> has no charts yet. Add one at
        <code>plugins/image-data-chart/niches/<?= $pcH($pcNiche) ?>/</code> and it appears here.</p>
    <?php else: ?>
        <p class="hint" style="margin-bottom:14px;">Niche <code><?= $pcH($pcNiche) ?></code> &middot; <?= count($pcDefs) ?> chart(s) &middot; drawn below from real city data where it exists.</p>
        <?php foreach ($pcDefs as $id => $def):
            $drawn = null; $drawnCity = null;
            foreach ($pcCities as $c) {
                $s = city_chart_series($def, $c);
                if ($s) { $drawn = $s; $drawnCity = $c; break; }
            }
        ?>
            <div style="margin-bottom:22px;">
                <div style="font-weight:600;color:#1e3a5f;font-size:.9rem;">
                    <code>{chart_<?= $pcH($id) ?>}</code>
                    <span style="color:#94a3b8;font-weight:400;">&middot; <?= $pcH($def['name'] ?? $id) ?> &middot; type <?= $pcH($def['type'] ?? 'bars') ?> &middot; reads <code><?= $pcH($def['data_key'] ?? '?') ?></code></span>
                </div>
                <?php if (!$drawn): ?>
                    <p class="hint" style="margin:5px 0 0;color:#92400e;">No city on this site has figures for <code><?= $pcH($def['data_key'] ?? '?') ?></code> yet, so this chart is skipped. Run the city research step to gather them.</p>
                <?php else: ?>
                    <div class="pc-sample" style="max-width:660px;margin-top:7px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                        <?= city_chart_svg($drawn, $def, city_chart_text((string) ($def['title'] ?? ''), $drawnCity, $def, $drawn)) ?>
                    </div>
                    <p class="hint" style="margin-top:6px;max-width:660px;"><strong>alt:</strong>
                        <?= $pcH(city_chart_text((string) ($def['alt'] ?? ''), $drawnCity, $def, $drawn)) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
