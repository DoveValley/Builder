<?php
/**
 * Remove a content block type from a site, across every layer that holds a copy.
 *
 * A block-level removal has to reach site.json (the homepage), templates.json (every
 * service-page template — a separate copy) AND data/pages/*.json (already-generated
 * pages, frozen snapshots that no master-data fix reaches). It also has to strip the
 * removed block's ID out of every `layout_variants` ordering, which references blocks
 * by id string and would otherwise point at a block that no longer exists.
 *
 * All of that lives in includes/block_ops.php so the panel, this tool and any future
 * caller share one implementation. This file is only argument handling and reporting.
 *
 * Writes go through the subsystem's own primitives — save_data() for site.json,
 * tpl_save() for templates.json — not hand-rolled JSON.
 *
 * Usage:
 *   php tools/remove_block_type.php --site=appliance-site --type=testimonials
 *   php tools/remove_block_type.php --site=appliance-site --type=testimonials --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts = getopt('', ['site:', 'type:', 'apply']);
$siteId = $opts['site'] ?? '';
$type   = $opts['type'] ?? '';
$apply  = isset($opts['apply']);

if ($siteId === '' || $type === '') {
    fwrite(STDERR, "usage: php tools/remove_block_type.php --site=<site-id> --type=<block-type> [--apply]\n");
    exit(1);
}

$siteDir = __DIR__ . '/../sites/' . $siteId;
if (!is_dir($siteDir . '/data')) {
    fwrite(STDERR, "no such site: {$siteId}\n");
    exit(1);
}

// Point config.php at this site the same way the multisite per-row worker does — the
// sanctioned CLI entry (config.php only honours it under PHP_SAPI === 'cli', so a web
// request can never redirect paths this way).
putenv('MULTISITE_SITE_BASE=' . realpath($siteDir));

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/block_ops.php';

$r = block_ops_remove_type_everywhere($type, !$apply);

printf("%s  site=%s  type=%s\n", $apply ? 'APPLIED' : 'DRY RUN', $siteId, $type);
printf("  site.json blocks removed : %d\n", $r['site']);
printf("  template blocks removed  : %d\n", $r['templates']);
printf("  variant ids stripped     : %d\n", $r['variant_ids']);
printf("  generated pages changed  : %d\n", $r['pages']);
foreach ($r['errors'] as $e) printf("  ERROR: %s\n", $e);

exit($r['errors'] ? 1 : 0);
