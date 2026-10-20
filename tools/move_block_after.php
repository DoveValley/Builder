<?php
/**
 * Move a content block to sit immediately after another, across site.json,
 * templates.json and data/pages/*.json.
 *
 * Either block may be named by its `type` or by its `ai_type_id` — the useful anchors
 * are AI blocks, and those all share the type `ai_block`, so naming by type alone
 * cannot distinguish city_intro from common_problems.
 *
 * Idempotent, and a no-op on templates missing either block.
 *
 * Usage:
 *   php tools/move_block_after.php --site=appliance-site --move=error_codes --after=city_intro
 *   …same, plus --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts  = getopt('', ['site:', 'move:', 'after:', 'apply']);
$site  = $opts['site']  ?? '';
$move  = $opts['move']  ?? '';
$after = $opts['after'] ?? '';
$apply = isset($opts['apply']);

if ($site === '' || $move === '' || $after === '') {
    fwrite(STDERR, "usage: php tools/move_block_after.php --site=<id> --move=<block> --after=<block> [--apply]\n");
    exit(1);
}
$dir = __DIR__ . '/../sites/' . $site;
if (!is_dir($dir . '/data')) { fwrite(STDERR, "no such site: {$site}\n"); exit(1); }

putenv('MULTISITE_SITE_BASE=' . realpath($dir));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/block_ops.php';

$r = block_ops_move_after_everywhere($move, $after, !$apply);

printf("%s  site=%s  move=%s  after=%s\n", $apply ? 'APPLIED' : 'DRY RUN', $site, $move, $after);
printf("  site.json changed      : %d\n", $r['site']);
printf("  templates changed      : %d\n", $r['templates']);
printf("  generated pages changed: %d\n", $r['pages']);
foreach ($r['errors'] as $e) printf("  ERROR: %s\n", $e);

exit($r['errors'] ? 1 : 0);
