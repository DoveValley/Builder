<?php
/**
 * Give a master's about-us / contact-us pages a real <title> and JSON-LD.
 *
 * Several masters ship these two with `title: "About Us"` / `"Contact Us"` — no
 * brand, no city, ~8-10 characters — and no schema on any of the four trust pages.
 * For a rank-and-rent build with no Google Business Profile, these pages carry most
 * of what a search engine can use to decide the business is real, so they are the
 * worst pages on the site to leave unfinished.
 *
 * Writes seo.seo_title (NOT page.title): static_build.php:279 prefers seo_title for
 * the <title> tag and falls back to page.title, so this leaves the admin-facing page
 * name alone. seo.bc_label is already set on these pages, so the breadcrumb keeps
 * reading "Home › About Us" rather than inheriting the longer title.
 *
 * ⚠ NO PostalAddress. The LocalBusiness node carries name, url, telephone, email and
 * areaServed only — Scott has a business name and a phone number, not a street
 * address, and inventing one is the kind of fabrication the fleet already got burned
 * by (see tools/clear_fabricated_rating.php).
 *
 * Two fixes versus the shape water-site already uses, which this is modelled on:
 *   · its ContactPoint is a top-level @graph node, so it hangs off nothing — here it
 *     is nested inside the LocalBusiness it describes.
 *   · its about-us `url` has no trailing slash while the canonical does — mismatched
 *     URLs for the same page.
 *
 * Usage:
 *   php tools/add_trust_page_schema.php --site=mold-site --service="Mold Remediation"
 *   php tools/add_trust_page_schema.php --site=mold-site --service="Mold Remediation" --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts    = getopt('', ['site:', 'service:', 'apply']);
$siteId  = $opts['site']    ?? '';
$service = trim($opts['service'] ?? '');
$apply   = isset($opts['apply']);

if ($siteId === '' || $service === '') {
    fwrite(STDERR, "usage: php tools/add_trust_page_schema.php --site=<id> --service=\"<Label>\" [--apply]\n");
    fwrite(STDERR, "  --service is the niche label used in the <title>, e.g. \"Pest Control\".\n");
    exit(1);
}

$file = __DIR__ . '/../sites/' . $siteId . '/data/site.json';
if (!is_file($file)) { fwrite(STDERR, "no site.json for {$siteId}\n"); exit(1); }

$raw  = file_get_contents($file);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['pages'])) { fwrite(STDERR, "unreadable site.json\n"); exit(1); }

/** Shared business node — referenced by @id from both pages, defined inline on each. */
function lb_node(bool $withContactPoint): array {
    $node = [
        '@type'      => 'LocalBusiness',
        '@id'        => '{website}/#localbusiness',
        'name'       => '{business}',
        'url'        => '{website}',
        'telephone'  => '{tel}',
        'areaServed' => ['@type' => 'City', 'name' => '{city}'],
    ];
    if ($withContactPoint) {
        $node['email']        = '{email}';
        $node['contactPoint'] = [
            '@type'             => 'ContactPoint',
            'telephone'         => '{tel}',
            'email'             => '{email}',
            'contactType'       => 'customer service',
            'areaServed'        => 'US',
            'availableLanguage' => 'English',
        ];
    }
    return $node;
}

$PLAN = [
    'about-us' => [
        'seo_title' => 'About {business} | {city_state} ' . $service,
        'schema'    => ['@context' => 'https://schema.org', '@graph' => [
            [
                '@type'       => 'AboutPage',
                '@id'         => '{website}/about-us/#aboutpage',
                'url'         => '{website}/about-us/',
                'name'        => 'About {business}',
                'description' => 'How {business} connects {city_state} property owners with independent local '
                               . strtolower($service) . ' providers.',
                'isPartOf'    => ['@id' => '{website}/#website'],
                'about'       => ['@id' => '{website}/#localbusiness'],
            ],
            lb_node(false),
        ]],
    ],
    'contact-us' => [
        'seo_title' => 'Contact {business} | {city_state} ' . $service,
        'schema'    => ['@context' => 'https://schema.org', '@graph' => [
            [
                '@type'       => 'ContactPage',
                '@id'         => '{website}/contact-us/#contactpage',
                'url'         => '{website}/contact-us/',
                'name'        => 'Contact {business}',
                'description' => 'Call or email {business} to be connected with a local '
                               . strtolower($service) . ' provider in {city_state}.',
                'isPartOf'    => ['@id' => '{website}/#website'],
                'about'       => ['@id' => '{website}/#localbusiness'],
            ],
            lb_node(true),
        ]],
    ],
];

printf("%s  site=%s  service=%s\n\n", $apply ? 'APPLYING' : 'DRY RUN', $siteId, $service);

$changed = 0;
foreach ($data['pages'] as &$page) {
    $slug = $page['slug'] ?? '';
    if (!isset($PLAN[$slug])) continue;
    $plan = $PLAN[$slug];
    $seo  = $page['seo'] ?? [];

    $oldTitle = $seo['seo_title'] ?? ($page['title'] ?? '');
    $hadSchema = trim((string) ($seo['schema'] ?? '')) !== '';

    printf("  %s\n", $slug);
    printf("     title : %s\n           → %s\n", var_export($oldTitle, true), $plan['seo_title']);
    printf("     schema: %s → %s nodes\n", $hadSchema ? 'REPLACED (had one)' : 'added',
        count($plan['schema']['@graph']));

    $seo['seo_title'] = $plan['seo_title'];
    $seo['schema']    = json_encode($plan['schema'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $page['seo'] = $seo;
    $changed++;
}
unset($page);

if (!$changed) { print("  no about-us/contact-us pages found\n"); exit(0); }

/*
 * Indent width is NOT uniform across this fleet. save_data() uses JSON_PRETTY_PRINT,
 * which is hard-wired to 4 spaces, but some site.json files on disk are 2-space —
 * mold-site is, written by something other than the canonical writer. Re-encoding one
 * of those at 4 spaces reformats every line (10 KB of noise on mold) and buries a
 * two-page change. So: detect what the file actually uses and match it.
 *
 * Rescaling leading whitespace is safe because every real newline in a JSON document
 * is structural — a newline inside a string value is the two characters \n, not a
 * line break — so no string value can be touched by this.
 */
function json_encode_matching_indent(array $data, string $sample): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return '';
    $width = 4;
    if (preg_match('/\n(\x20+)"/', $sample, $m)) $width = strlen($m[1]);
    if ($width === 4) return $json;
    return preg_replace_callback('/^(\x20+)/m',
        fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 4) * $width), $json);
}

// Identity round-trip: re-encode the UNTOUCHED structure and require it to reproduce
// the file byte for byte. If it doesn't, this writer would change lines it has no
// business changing — refuse rather than hand back an unreviewable diff.
$probe    = json_decode($raw, true);
$identity = json_encode_matching_indent($probe, $raw);
if ($identity !== $raw) {
    printf("\n⚠ site.json does not round-trip byte-identically (%+d bytes).\n", strlen($identity) - strlen($raw));
    print("  Writing would reformat lines this change does not touch. Refusing.\n");
    exit(1);
}

printf("\npages changed: %d   (indent matched to existing file)\n", $changed);

if ($apply) {
    $out = json_encode_matching_indent($data, $raw);
    if ($out === '' || file_put_contents($file, $out) === false) {
        fwrite(STDERR, "write failed\n"); exit(1);
    }
    print("written.\n");
} else {
    print("\nDRY RUN — nothing written. Re-run with --apply.\n");
}
