<?php
/**
 * Clear a master's fabricated LocalBusiness aggregateRating.
 *
 * A rating + review count stored on a master with no real reviews behind it becomes a
 * self-serving aggregateRating in the emitted JSON-LD, which breaches Google's
 * structured-data policy and is a straightforwardly false claim to a visitor.
 *
 * The multisite path already refuses to propagate one — ms_differentiate_working_dir()
 * blanks lb_rating/lb_review_count unless the domain's own row supplies them ("carrying
 * the master's rating to every site invents reviews — never do that"). So for a master
 * that is ONLY ever cloned this is hygiene. For a master that also deploys directly,
 * nothing strips it and it ships.
 *
 * ⚠ Deliberately does NOT go through admin/save/local_business.php. That handler
 * rebuilds $data['local_business'] from just four POST fields (lb_name, lb_url,
 * lb_rating, lb_review_count) and assigns the result wholesale — calling it would
 * silently drop lb_type, lb_phone, lb_street, lb_city, lb_state, lb_zip, lb_country,
 * lb_lat, lb_lng, lb_price_range, lb_hours, lb_description and lb_logo. This uses the
 * canonical writer, save_data(), and touches only the two keys.
 *
 * Usage:
 *   php tools/clear_fabricated_rating.php --site=appliance-site
 *   php tools/clear_fabricated_rating.php --site=appliance-site --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts   = getopt('', ['site:', 'apply']);
$siteId = $opts['site'] ?? '';
$apply  = isset($opts['apply']);

if ($siteId === '') {
    fwrite(STDERR, "usage: php tools/clear_fabricated_rating.php --site=<site-id> [--apply]\n");
    exit(1);
}

$siteDir = __DIR__ . '/../sites/' . $siteId;
if (!is_dir($siteDir . '/data')) {
    fwrite(STDERR, "no such site: {$siteId}\n");
    exit(1);
}

putenv('MULTISITE_SITE_BASE=' . realpath($siteDir));

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$data = load_data();
$lb   = $data['local_business'] ?? [];

$rating = trim((string) ($lb['lb_rating'] ?? ''));
$count  = trim((string) ($lb['lb_review_count'] ?? ''));

printf("%s  site=%s\n", $apply ? 'APPLIED' : 'DRY RUN', $siteId);
printf("  lb_rating       : %s\n", $rating === '' ? '(already blank)' : $rating);
printf("  lb_review_count : %s\n", $count  === '' ? '(already blank)' : $count);

if ($rating === '' && $count === '') {
    print("  nothing to do\n");
    exit(0);
}

$before = count($lb);
$lb['lb_rating']       = '';
$lb['lb_review_count'] = '';
$data['local_business'] = $lb;

// The other LocalBusiness fields must survive untouched — this is the exact failure
// mode the panel handler has, so assert it rather than trust it.
if (count($lb) !== $before) {
    fwrite(STDERR, "  ABORT: local_business field count changed {$before} -> " . count($lb) . "\n");
    exit(1);
}

if (!$apply) { print("  would clear both (use --apply)\n"); exit(0); }

if (!save_data($data)) {
    fwrite(STDERR, "  ERROR: save_data() failed\n");
    exit(1);
}
printf("  cleared · %d other local_business fields preserved\n", $before - 2);
exit(0);
