<?php
/**
 * lib/serp.php — what the first page actually looks like for a city keyword.
 *
 * Keyword difficulty is a backlink measure, and for local service terms the
 * pages ranking have almost no backlinks — which is why half the cities come
 * back KD 0 from Ahrefs and nearly all of them from DataForSEO. It is not that
 * the metric is broken; it is measuring the wrong barrier.
 *
 * For a site with no Google Business Profile and no address, the real barriers
 * are: a map pack pushing organic below the fold, ads stacked above it, and
 * directories (Yelp, Angi, Thumbtack) holding the organic slots. This asks the
 * search engine directly and records those three facts.
 *
 * One call per keyword — SERPs cannot be batched — at about $0.002 each, so a
 * top-50-per-niche pass is well under a dollar. DataForSEO allows 2,000 calls a
 * minute here, unlike the 12 on its keyword endpoints, so throughput is not the
 * constraint.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/keywords.php';

/**
 * Domains that are somebody else's directory rather than a local business.
 * Each one holding an organic slot is a slot you cannot take with a rank-and-rent
 * site, and they rank on domain authority you will never out-link.
 */
function infra_serp_directories(): array
{
    return [
        'yelp.com', 'angi.com', 'angieslist.com', 'homeadvisor.com', 'thumbtack.com',
        'bbb.org', 'porch.com', 'networx.com', 'houzz.com', 'yellowpages.com',
        'mapquest.com', 'facebook.com', 'nextdoor.com', 'tripadvisor.com',
        'expertise.com', 'threebestrated.com', 'bark.com', 'buildzoom.com',
        'trustedpros.com', 'manta.com', 'chamberofcommerce.com', 'superpages.com',
        'yellowbook.com', 'local.com', 'citysearch.com', 'alignable.com',
        'indeed.com', 'reddit.com', 'amazon.com', 'homeguide.com', 'wikipedia.org',
    ];
}

function infra_serp_is_directory(string $domain): bool
{
    $d = strtolower(preg_replace('/^www\./', '', trim($domain)));
    foreach (infra_serp_directories() as $dir) {
        if ($d === $dir || substr($d, -strlen('.' . $dir)) === '.' . $dir) return true;
    }
    return false;
}

/**
 * Read one SERP.
 *
 * @return array{ok:bool,msg:string,data:array}
 *   data: map (yes/no) · ads (int) · dirs (int of top 10) · first (absolute rank
 *   of the first organic result) · top (first organic domain)
 */
function infra_serp_fetch(array $c, string $keyword): array
{
    $loc  = (int) ($c['location'] ?? 2840) ?: 2840;
    $lang = trim((string) ($c['language'] ?? 'en')) ?: 'en';

    $r = infra_kw_dfs_post($c, 'serp/google/organic/live/advanced', [
        'keyword'       => $keyword,
        'location_code' => $loc,
        'language_code' => $lang,
        'device'        => 'desktop',
        'depth'         => 10,
    ]);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'data' => []];

    $items = (array) ($r['result'][0]['items'] ?? []);
    $map = 'no'; $ads = 0; $dirs = 0; $first = null; $top = '';

    foreach ($items as $it) {
        $type = (string) ($it['type'] ?? '');
        $abs  = (int) ($it['rank_absolute'] ?? 0);

        if ($type === 'local_pack' || $type === 'local_services' || $type === 'map') {
            $map = 'yes';
        } elseif ($type === 'paid') {
            // Only ads ABOVE the organic results push you down the page; the ones
            // at the foot of the page cost nothing.
            if ($first === null) $ads++;
        } elseif ($type === 'organic') {
            if ($first === null) { $first = $abs; $top = (string) ($it['domain'] ?? ''); }
            if (infra_serp_is_directory((string) ($it['domain'] ?? ''))) $dirs++;
        }
    }
    return ['ok' => true, 'msg' => '', 'data' => [
        'map'   => $map,
        'ads'   => (string) $ads,
        'dirs'  => (string) $dirs,
        'first' => $first === null ? '' : (string) $first,
        'top'   => $top,
    ]];
}

/**
 * How open the first page is, 1-10, from what the SERP looks like.
 *
 * Deliberately separate from the keyword score. That one answers "is this worth
 * winning"; this one answers "can it be won by a site with no map presence".
 * Multiplying them into a single number would hide which of the two killed a
 * city, and they are acted on differently — a low keyword score means skip, a
 * low SERP score means this niche needs a different approach in that city.
 */
function infra_serp_score(array $d): ?int
{
    if (($d['map'] ?? '') === '' && ($d['first'] ?? '') === '') return null;

    $s = 10.0;
    if (($d['map'] ?? 'no') === 'yes') $s -= 3.0;   // a map pack takes the top of the page
    $s -= min(3.0, (int) ($d['ads'] ?? 0) * 1.0);   // each ad above organic pushes further down
    $s -= min(4.0, (int) ($d['dirs'] ?? 0) * 0.8);  // every directory slot is one you cannot take
    return (int) max(1, min(10, round($s)));
}

function infra_serp_verdict(array $d): string
{
    $s = infra_serp_score($d);
    if ($s === null) return '';
    if ($s >= 8) return 'open';
    if ($s >= 5) return 'tight';
    return 'crowded';
}
