<?php
/**
 * Move a content block type to the bottom of the page, across every layer that holds it.
 *
 * Same three-layer problem as removal (site.json, templates.json, data/pages/*.json), plus
 * `layout_variants`, which order blocks by ID string. Rotation is off fleet-wide today
 * (`"layout":{"enabled":false}` in the build output), so variants are inert — but they are
 * stored per template and layout_apply() honours them the moment rotation is switched back
 * on, which would silently undo the reorder. They are kept in step for that reason.
 *
 * Idempotent: a block already last is left alone and reported as unchanged.
 *
 * Usage:
 *   php tools/move_block_to_end.php --site=appliance-site --type=map_info
 *   php tools/move_block_to_end.php --site=appliance-site --type=map_info --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts  = getopt('', ['site:', 'type:', 'apply']);
$site  = $opts['site'] ?? '';
$type  = $opts['type'] ?? '';
$apply = isset($opts['apply']);

if ($site === '' || $type === '') {
    fwrite(STDERR, "usage: php tools/move_block_to_end.php --site=<site-id> --type=<block-type> [--apply]\n");
    exit(1);
}
$dir = __DIR__ . '/../sites/' . $site;
if (!is_dir($dir . '/data')) { fwrite(STDERR, "no such site: {$site}\n"); exit(1); }

putenv('MULTISITE_SITE_BASE=' . realpath($dir));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/block_ops.php';

$r = block_ops_move_type_to_end_everywhere($type, !$apply);

printf("%s  site=%s  type=%s\n", $apply ? 'APPLIED' : 'DRY RUN', $site, $type);
printf("  site.json changed        : %d\n", $r['site']);
printf("  templates reordered      : %d\n", $r['templates']);
printf("  generated pages reordered: %d\n", $r['pages']);
printf("  layout variants updated  : %d\n", $r['variants']);
foreach ($r['errors'] as $e) printf("  ERROR: %s\n", $e);

exit($r['errors'] ? 1 : 0);
