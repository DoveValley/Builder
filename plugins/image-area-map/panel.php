<?php
/**
 * Image · Area Map — plugin panel.
 *
 * What it is, how it works, and a LIVE sample. The sample is drawn on the spot from this
 * site's own city rather than being a stored screenshot, so it can never drift from what the
 * plugin actually produces — and if this site has no named areas, it says so instead of
 * showing a picture that misrepresents the state.
 *
 * Read-only: draws in memory, writes nothing.
 */

require_once __DIR__ . '/draw.php';

$pmCity = [];
if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
    $rows = json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/data/cities.json'), true);
    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (is_array($row) && array_filter((array) ($row['neighborhoods'] ?? []))) { $pmCity = $row; break; }
    }
}
$pmH = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<style>.pm-sample svg{width:100%;height:auto;display:block}</style>
<div class="card">
    <h2>&#128506; Image &middot; Area Map</h2>
    <p class="hint" style="margin-bottom:16px;">A service-area diagram drawn from the city's own named areas. One image per city that no other city can have.</p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">What it does</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Draws the city at the centre with its <strong>real named neighbourhoods</strong> around it, from <code>cities.json</code>.</li>
        <li>Every city has different area names, so <strong>every diagram is different by construction</strong> &mdash; not a photo shuffled or cropped.</li>
        <li><strong>Writes its own alt text</strong> from the same data it drew, so no two sites ship the same alt string.</li>
        <li>Takes its colours from the site's own theme, so it doesn't look like a stock graphic dropped in.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">How it works</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Drop <code>{city_map}</code> into any photo field and <code>{city_map_alt}</code> beside it &mdash; the same way <code>{city_image}</code> works.</li>
        <li>Drawn <strong>once per city and cached</strong>. Ten sites covering one city draw it once.</li>
        <li>A landing page gets <em>its own</em> city's diagram, not the homepage's.</li>
        <li>Saved as SVG, then rasterised to WebP &mdash; the WebP is what the page uses, so it stays an ordinary indexable image.</li>
        <li>A city with <strong>no named areas gets no diagram</strong>, rather than a lone dot.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">Worth knowing</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><strong>It is a diagram, not a map.</strong> The area names carry no coordinates, so placing them on real geography would claim more than the data supports. The image says so on its face.</li>
        <li>The area names inside it are <strong>pixels, not text</strong> &mdash; Google cannot read them. The SEO value is in your page copy, which already names these areas; this earns its place by being an image no other site can share, and by answering "do you cover my area?" at a glance.</li>
        <li>No map tiles, no API key, no licence, no cost per site.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">Sample &mdash; drawn live from this site's data</h3>
    <?php if (!$pmCity): ?>
        <p class="hint">No city on this site has named neighbourhoods yet, so there is nothing to draw. Add them under Cities, or run the city research step, and a diagram appears here.</p>
    <?php else: ?>
        <div class="pm-sample" style="max-width:620px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
            <?= city_map_svg($pmCity) ?>
        </div>
        <p class="hint" style="margin-top:8px;max-width:620px;"><strong>alt:</strong> <?= $pmH(city_map_alt($pmCity)) ?></p>
    <?php endif; ?>
</div>
