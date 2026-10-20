<?php
/**
 * Lift a content block above the run of filler blocks sitting directly in front of it,
 * across every layer that holds it (site.json, templates.json, data/pages/*.json) plus
 * each template's layout_variants.
 *
 * Written for the FAQ: measured across one domain's 100 built pages it is the largest
 * block of page-unique text by a wide margin (~2,526 chars/page vs ~1,400 for the next),
 * yet it sat behind a CTA banner and a links box, both of which carry essentially no
 * unique text. The rule is relative rather than a fixed index because the stacks differ
 * by role — hubs have two filler blocks in front of the FAQ, leaves and standalone pages
 * have one.
 *
 * Idempotent: a block already at the front of its run is left alone.
 *
 * Usage:
 *   php tools/lift_block_above_filler.php --site=appliance-site --type=faq_two_col \
 *       --filler=cta_banner,related_links,cards,custom_html
 *   …same, plus --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts   = getopt('', ['site:', 'type:', 'filler:', 'apply']);
$site   = $opts['site'] ?? '';
$type   = $opts['type'] ?? '';
$filler = array_values(array_filter(array_map('trim', explode(',', $opts['filler'] ?? ''))));
$apply  = isset($opts['apply']);

if ($site === '' || $type === '' || !$filler) {
    fwrite(STDERR, "usage: php tools/lift_block_above_filler.php --site=<id> --type=<block> --filler=<a,b,c> [--apply]\n");
    exit(1);
}
$dir = __DIR__ . '/../sites/' . $site;
if (!is_dir($dir . '/data')) { fwrite(STDERR, "no such site: {$site}\n"); exit(1); }

putenv('MULTISITE_SITE_BASE=' . realpath($dir));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/block_ops.php';

$r = block_ops_lift_above_filler_everywhere($type, $filler, !$apply);

printf("%s  site=%s  type=%s  filler=%s\n", $apply ? 'APPLIED' : 'DRY RUN', $site, $type, implode(',', $filler));
printf("  site.json changed      : %d\n", $r['site']);
printf("  templates changed      : %d\n", $r['templates']);
printf("  generated pages changed: %d\n", $r['pages']);
printf("  layout variants updated: %d\n", $r['variants']);
foreach ($r['errors'] as $e) printf("  ERROR: %s\n", $e);

exit($r['errors'] ? 1 : 0);
