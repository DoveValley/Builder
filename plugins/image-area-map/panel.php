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

/**
 * Reference sample — shown when this site has no areas of its own yet, so the panel is not
 * documenting a picture nobody can see.
 *
 * These are all genuinely WITHIN the city, which is what the field means: generate.py asks for
 * "neighborhoods, subdivisions, or districts IN {city}". An earlier version of this sample mixed
 * in Diboll and Huntington — real places, but separate incorporated cities ten miles out — which
 * taught the wrong thing about the field. Surrounding towns are a different claim and would need
 * a different field.
 */
$pmRef = [
    'city' => 'Houston', 'SS' => 'TX', 'state' => 'Texas',
    'neighborhoods' => ['Montrose', 'The Heights', 'Midtown', 'River Oaks', 'EaDo', 'Rice Village', 'Memorial'],
    'nearby_towns' => [
        ['name' => 'Bellaire', 'miles' => 7],
        ['name' => 'Pasadena', 'miles' => 12],
        ['name' => 'Pearland', 'miles' => 17],
        ['name' => 'Humble', 'miles' => 20],
        ['name' => 'Sugar Land', 'miles' => 22],
        ['name' => 'Katy', 'miles' => 30],
    ],
];
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

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">Two tiers, because they are two different claims</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><code>neighborhoods</code> &mdash; areas <strong>inside</strong> the city. Drawn on the ring.</li>
        <li><code>nearby_towns</code> &mdash; <strong>separate towns</strong> you also serve, each with its driving distance. Set out as a
            <strong>list beside the diagram</strong>: the mileages line up so they compare at a glance, and a list makes no claim about where
            a town lies &mdash; which is right, because we don't know. Entries look like <code>{"name": "Diboll", "miles": 10}</code>;
            a town with no <code>miles</code> still appears, just without the figure.</li>
        <li>Listing Diboll (a city ten miles out) beside Crown Colony (a Lufkin neighbourhood) is <strong>wrong on its face to a local reader</strong>,
            and the two are worth different things &mdash; which is why they are separate fields, and why the legend names them separately.</li>
        <li>The research gathers both automatically. This plugin declares what it needs in its own <code>research.json</code>, so installing it
            is what makes the question get asked.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">Worth knowing</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><strong>It is a diagram, not a map.</strong> Positions carry no geography &mdash; they are seeded from the city name so every city's picture differs. The image says so on its face.</li>
        <li><strong>We tried placing towns on their true compass bearing and cut it.</strong> It bought nothing (the diagram was already unique per city, and someone in Diboll is looking for the word "Diboll", not for where its dot sits), and the data would not support it: research returned a direction that was wrong for four of eight towns around Lufkin &mdash; Pollok as "S" when it is NW, off by 132&deg;. A wrong bearing is glaring to a local reader in a way this schematic can never be.</li>
        <li>Every name inside it is <strong>pixels, not text</strong> &mdash; Google cannot read them. The SEO value is in your page copy; this earns its place by being an image no other site can share, and by answering "do you cover my area?" at a glance.</li>
        <li>That cuts both ways for <code>nearby_towns</code>: its real worth is in the <strong>copy</strong>, where "we serve Diboll" is a keyword of its own with its own volume. On the diagram it is decoration &mdash; useful decoration, but the field is the asset, not the picture.</li>
        <li>No map tiles, no API key, no licence, no cost per site.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">What it draws</h3>
    <p class="hint" style="max-width:620px;margin-bottom:10px;">A reference sample showing both tiers: seven areas <strong>inside</strong> Houston on the ring,
        and six <strong>separate towns</strong> it also serves listed beside it with their driving distances. The ring layout is seeded
        from the city name, so a city with four areas and one with nine look different from each other, not just relabelled.</p>
    <div class="pm-sample" style="max-width:620px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
        <?= city_map_svg($pmRef) ?>
    </div>
    <p class="hint" style="margin-top:8px;max-width:620px;"><strong>alt:</strong> <?= $pmH(city_map_alt($pmRef)) ?></p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:22px 0 8px;">This site's own data</h3>
    <?php if (!$pmCity): ?>
        <p class="hint" style="color:#92400e;">No city on this site has named neighbourhoods yet, so nothing would be drawn today. Add them under Cities, or run the city research step, and this site's own diagram appears here.</p>
    <?php else: ?>
        <div class="pm-sample" style="max-width:620px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
            <?= city_map_svg($pmCity) ?>
        </div>
        <p class="hint" style="margin-top:8px;max-width:620px;"><strong>alt:</strong> <?= $pmH(city_map_alt($pmCity)) ?></p>
    <?php endif; ?>
</div>
