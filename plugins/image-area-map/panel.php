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
        // Either tier is enough to draw, so either is enough to be worth showing here.
        if (is_array($row) && (array_filter((array) ($row['neighborhoods'] ?? [])) || city_map_towns($row))) { $pmCity = $row; break; }
    }
}
$pmH = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

/**
 * Reference samples — one per STATE the drawing can be in, so the panel shows every shape it
 * produces even on a site whose cities have no data yet (which is most of them today).
 *
 * The area names are all genuinely WITHIN the city and the towns are all genuinely SEPARATE
 * cities, because that distinction is the thing this panel is teaching. An earlier version put
 * Diboll and Huntington under `neighborhoods` — real places, but separate incorporated cities
 * ten miles out — which taught the wrong thing about the field it was documenting.
 */
$pmAreas = ['Montrose', 'The Heights', 'Midtown', 'River Oaks', 'EaDo', 'Rice Village', 'Memorial'];
$pmTowns = [
    ['name' => 'Bellaire', 'miles' => 7],
    ['name' => 'Pasadena', 'miles' => 12],
    ['name' => 'Pearland', 'miles' => 17],
    ['name' => 'Humble', 'miles' => 20],
    ['name' => 'Sugar Land', 'miles' => 22],
    ['name' => 'Katy', 'miles' => 30],
];
$pmBase = ['city' => 'Houston', 'SS' => 'TX', 'state' => 'Texas'];

$pmStates = [
    ['Both tiers', 'The usual case once research has run. Areas on the ring, towns in the column.',
     $pmBase + ['neighborhoods' => $pmAreas, 'nearby_towns' => $pmTowns]],
    ['Areas only', 'No <code>nearby_towns</code> yet. The diagram takes the full width and centres itself &mdash; no empty column.',
     $pmBase + ['neighborhoods' => $pmAreas]],
    ['Towns only', 'No <code>neighborhoods</code>, which is common for a small city. Still a useful picture, so it is still drawn.',
     $pmBase + ['nearby_towns' => $pmTowns]],
    ['A town with no mileage', 'A missing <code>miles</code> just omits the figure &mdash; the town is never dropped, and no distance is invented.',
     $pmBase + ['neighborhoods' => array_slice($pmAreas, 0, 3),
                'nearby_towns' => [['name' => 'Bellaire', 'miles' => 7], ['name' => 'Katy'], ['name' => 'Pasadena', 'miles' => 12]]]],
];

/** The fields this plugin reads, documented once here so the panel IS the reference. */
$pmParams = [
    ['city',          'yes', 'The city name. No city, no diagram &mdash; this is the only hard requirement.'],
    ['SS',            'no',  'State abbreviation. Shown beside the city name in the centre.'],
    ['city_slug',     'no',  'Used for the output filename. Derived from <code>city</code> + <code>SS</code> if absent.'],
    ['neighborhoods', 'no',  'Array of area names <strong>inside</strong> the city. Drawn on the ring, up to 8. Plain strings.'],
    ['nearby_towns',  'no',  '<strong>Separate towns</strong> you also serve, listed beside the diagram, up to 10. '
                           . 'Each is <code>{"name": &hellip;, "miles": &hellip;}</code>; <code>miles</code> is optional. '
                           . 'A bare string works too. Past 10, the rest are counted, never silently dropped.'],
];
?>
<style>.pm-sample svg{width:100%;height:auto;display:block}
.pm-tbl{font-size:.84rem;border-collapse:collapse;width:100%;max-width:820px}
.pm-tbl td{padding:5px 10px 5px 0;border-top:1px solid #eef2f7;vertical-align:top;color:#334155}
.pm-tbl td:first-child{white-space:nowrap;font-family:ui-monospace,monospace;color:#1e3a5f;font-weight:600}
.pm-req{color:#b91c1c;font-size:.74rem;font-weight:700}
.pm-opt{color:#94a3b8;font-size:.74rem}
</style>
<div class="card">
    <h2>&#128506; Image &middot; Area Map</h2>
    <p class="hint" style="margin-bottom:16px;">A service-area diagram drawn from the city's own coverage &mdash; the areas inside it, and the towns around it. One image per city that no other city can have.</p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">What it does</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Draws the city at the centre with its <strong>real named neighbourhoods</strong> on a ring, and the <strong>separate towns it also serves</strong> listed beside it with driving distances &mdash; both from <code>cities.json</code>.</li>
        <li>Every city has different names, counts and distances, so <strong>every diagram is different by construction</strong> &mdash; not a photo shuffled or cropped.</li>
        <li><strong>Writes its own alt text</strong> from the same data it drew, so no two sites ship the same alt string.</li>
        <li>Takes its colours from the site's own theme, so it doesn't look like a stock graphic dropped in.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">How it works</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Drop <code>{city_map}</code> into any photo field, <code>{city_map_alt}</code> beside it, and <code>{city_map_caption}</code> under it &mdash; the same way <code>{city_image}</code> works.</li>
        <li>In a <strong>Map + Info</strong> block, set map position to <strong>&ldquo;across the top&rdquo;</strong>: the diagram runs full width with the city text and photo side by side beneath. In a half-width panel the nearby-towns column is too small to read.</li>
        <li>Drawn <strong>once per city and cached</strong>. Ten sites covering one city draw it once.</li>
        <li>A landing page gets <em>its own</em> city's diagram, not the homepage's.</li>
        <li>Saved as SVG, then rasterised to WebP &mdash; the WebP is what the page uses, so it stays an ordinary indexable image.</li>
        <li>A city with <strong>neither areas nor towns gets no diagram</strong>, rather than a lone dot. Either one on its own is enough to draw.</li>
        <li>The cache is keyed on the <strong>drawing</strong>, not just the filename, so a city that gains new data is redrawn instead of keeping a stale picture.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">Two tiers, because they are two different claims</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><code>neighborhoods</code> &mdash; areas <strong>inside</strong> the city. Drawn on the ring.</li>
        <li><code>nearby_towns</code> &mdash; <strong>separate towns</strong> you also serve, each with its driving distance. Set out as a
            <strong>list beside the diagram</strong>: the mileages line up so they compare at a glance, and a list makes no claim about where
            a town lies &mdash; which is right, because we don't know. Entries look like <code>{"name": "Diboll", "miles": 10}</code>;
            a town with no <code>miles</code> still appears, just without the figure.</li>
        <li>Listing Diboll (a city ten miles out) beside Crown Colony (a Lufkin neighbourhood) is <strong>wrong on its face to a local reader</strong>,
            and the two are worth different things &mdash; which is why they are separate fields, and why the picture keeps them visually apart.</li>
        <li>The research gathers both automatically. This plugin declares what it needs in its own <code>research.json</code>, so installing it
            is what makes the question get asked &mdash; and a site without the plugin never pays for it.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">Fields it reads</h3>
    <p class="hint" style="max-width:820px;margin-bottom:8px;">All from the city's row in <code>cities.json</code>. Everything except the city name is optional; the diagram
        draws whatever is there and claims nothing about what isn't.</p>
    <table class="pm-tbl">
        <?php foreach ($pmParams as [$k, $req, $desc]): ?>
        <tr>
            <td><?= $pmH($k) ?></td>
            <td style="white-space:nowrap;"><span class="<?= $req === 'yes' ? 'pm-req' : 'pm-opt' ?>"><?= $req === 'yes' ? 'required' : 'optional' ?></span></td>
            <td><?= $desc ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="hint" style="max-width:820px;margin-top:10px;"><strong>Three tokens come out:</strong>
        <code>{city_map}</code> (the image path, for any photo field), <code>{city_map_alt}</code> (alt text), and
        <code>{city_map_caption}</code> (the text under the picture). Colours come from the site theme &mdash; nothing to configure per site.</p>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:20px 0 8px;">The caption, and what it deliberately leaves out</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li>Everything inside the diagram is <strong>pixels</strong>. The caption is the same coverage claim as text, which is the only part a crawler can read.</li>
        <li><strong>The city is the subject. The nearby towns are deliberately NOT listed.</strong> Eight town names repeated under all 27 pages is boilerplate
            and a well-known low-quality local-SEO pattern; it dilutes the one entity the site is built on, and on a one-site-per-city strategy it competes with
            a future site for those towns. They stay in the diagram, where being unreadable to a crawler is a <em>feature</em> &mdash; a person still gets the
            answer at a glance.</li>
        <li>Phrasing is <strong>chosen per domain</strong>, so sites built from one master don't all carry the same sentence &mdash; that would just be a new
            shared template replacing the one we removed.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:18px 0 8px;">Worth knowing</h3>
    <ul style="margin:0 0 4px 18px;padding:0;line-height:1.7;font-size:.9rem;color:#334155;">
        <li><strong>It is a diagram, not a map.</strong> Positions carry no geography &mdash; they are seeded from the city name so every city's picture differs. The image says so on its face.</li>
        <li><strong>We tried placing towns on their true compass bearing and cut it.</strong> It bought nothing (the diagram was already unique per city, and someone in Diboll is looking for the word "Diboll", not for where its dot sits), and the data would not support it: research returned a direction that was wrong for four of eight towns around Lufkin &mdash; Pollok as "S" when it is NW, off by 132&deg;. A wrong bearing is glaring to a local reader in a way this schematic can never be.</li>
        <li>Every name inside it is <strong>pixels, not text</strong> &mdash; Google cannot read them. The SEO value is in your page copy; this earns its place by being an image no other site can share, and by answering "do you cover my area?" at a glance.</li>
        <li>That cuts both ways for <code>nearby_towns</code>: its real worth is in the <strong>copy</strong>, where "we serve Diboll" is a keyword of its own with its own volume. On the diagram it is decoration &mdash; useful decoration, but the field is the asset, not the picture.</li>
        <li>No map tiles, no API key, no licence, no cost per site.</li>
    </ul>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:22px 0 6px;">What it draws</h3>
    <p class="hint" style="max-width:660px;margin-bottom:14px;">Every shape the drawing takes, from real areas of a real city. The layout is seeded from the
        city name, so a city with four areas and one with nine look different from each other rather than relabelled.</p>
    <?php foreach ($pmStates as $i => [$title, $note, $sample]): ?>
        <div style="margin-bottom:24px;">
            <div style="font-weight:600;color:#1e3a5f;font-size:.9rem;"><?= $pmH($title) ?></div>
            <p class="hint" style="margin:3px 0 8px;max-width:660px;"><?= $note ?></p>
            <div class="pm-sample" style="max-width:660px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                <?= city_map_svg($sample) ?>
            </div>
            <p class="hint" style="margin-top:6px;max-width:660px;"><strong>alt:</strong> <?= $pmH(city_map_alt($sample)) ?></p>
            <?php $pmCap = city_map_caption($sample, function_exists('city_map_domain') ? city_map_domain() : ''); ?>
            <?php if ($pmCap !== ''): ?><p class="hint" style="margin-top:4px;max-width:660px;"><strong>caption:</strong> <?= $pmH($pmCap) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <h3 style="font-size:.95rem;color:#1e3a5f;margin:22px 0 8px;">This site's own data</h3>
    <?php if (!$pmCity): ?>
        <p class="hint" style="color:#92400e;">No city on this site has named areas or nearby towns yet, so nothing would be drawn today. Add them under Cities, or run the city research step, and this site's own diagram appears here.</p>
    <?php else: ?>
        <div class="pm-sample" style="max-width:620px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
            <?= city_map_svg($pmCity) ?>
        </div>
        <p class="hint" style="margin-top:8px;max-width:620px;"><strong>alt:</strong> <?= $pmH(city_map_alt($pmCity)) ?></p>
    <?php endif; ?>
</div>
