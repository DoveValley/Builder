<?php
/**
 * CLI: lint a multisite master for authoring leaks (literal city/state/zip that
 * should be shortcodes, external master-domain asset URLs).
 *   php multisite/lint_master.php <master_id>
 * Exit 0 = clean, 1 = findings, 2 = usage error.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "lint_master.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/master_lint.php';

$master = $argv[1] ?? '';
if ($master === '' || !is_dir(BASE_DIR . '/sites/' . $master)) {
    fwrite(STDERR, "usage: lint_master.php <master_id>\n");
    exit(2);
}

$r  = ms_lint_master($master);
$id = $r['identity'] ?? [];
echo "Master: {$master}\n";
echo "Identity: city={$id['city']}  state={$id['state']}  SS={$id['ss']}  zip={$id['zip']}  domain={$id['domain']}\n\n";

$f = $r['findings'] ?? [];
if (!$f) { echo "\u{2713} No authoring leaks found — master localizes cleanly.\n"; exit(0); }

echo count($f) . " finding(s) — review each (some may be intentional, e.g. a governing-law state):\n\n";
foreach ($f as $x) {
    $tag = $x['type'] === 'geo' ? "literal '{$x['lit']}' → {$x['fix']}" : "master-domain URL → {$x['fix']}";
    echo "  [{$x['type']}] {$x['file']} :: {$x['path']}\n";
    echo "      {$tag}\n";
    echo "      \"{$x['excerpt']}\"\n\n";
}
exit(1);
