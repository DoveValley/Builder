<?php
/**
 * infra/cron/filedate_tick.php — drains the File Date Refresh queue (CLI only).
 *
 * Fully separate from golive_tick.php: own queue table (file_date_refresh), own
 * heartbeat file, own crontab entry (/etc/cron.d/infra-filedate). Deleting this
 * script and its cron entry does not affect go-live or its own freshen pipeline
 * at all — see lib/file_date_refresh.php's docblock.
 *
 * Each run: touch up to <cap> domains whose queued due time has arrived. Re-uploads
 * each domain's existing files unchanged (resets their mtime) and purges its
 * Cloudflare cache — no content is generated or altered.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/file_date_refresh.php';

$cap = max(1, (int) ($argv[1] ?? 3));
$ts  = gmdate('c');

$done = infra_filedate_run_due($cap);
foreach ($done as $domain => $msg) echo "{$ts} filedate {$domain}: {$msg}\n";
echo "{$ts} tick done — refreshed: " . count($done) . "/{$cap}\n";

// Same convention as golive_tick.json: a run that leaves no footprint looks
// identical to a cron that silently stopped firing.
@file_put_contents(dirname(__DIR__) . '/state/filedate_tick.json', json_encode([
    'at' => time(), 'refreshed' => count($done), 'cap' => $cap,
], JSON_PRETTY_PRINT));
