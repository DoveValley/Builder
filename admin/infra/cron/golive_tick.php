<?php
/**
 * infra/cron/golive_tick.php — go-live batch runner (CLI only).
 *
 * Run from real cron as www-data, once a day if every domain's go_live_time is left
 * blank (date-only scheduling, the original behaviour):
 *   0 9 * * *  www-data  php /var/www/homepage-builder-new/admin/infra/cron/golive_tick.php 20 >> /var/log/infra-golive.log 2>&1
 *
 * A domain that DOES carry a go_live_time (set from either the batch card or Infra →
 * Bulk) is only actually due once that clock time passes on its scheduled day — see
 * infra_golive_due()'s docblock. Cron itself still only calls this script on whatever
 * schedule the crontab line says; a once-daily cron will pick up a same-day timed
 * domain the next time it happens to run that day, not at the exact minute typed in.
 * For real same-day, different-time releases, change the crontab line to run more
 * often (e.g. every 15 minutes) — this script is idempotent and safe to run that
 * often; it just does nothing on a tick where nothing is due yet.
 *
 * Each run: (1) refresh live status from Cloudflare, (2) release up to <cap>
 * domains that are due right now, (3) refresh again. Idempotent and safe to re-run.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/golive.php';
require_once __DIR__ . '/../lib/cache.php';
require_once __DIR__ . '/../lib/pipeline.php';

$cap = max(1, (int) ($argv[1] ?? 20));
$ts  = gmdate('c');

infra_cache_force();                 // cron always polls Cloudflare live
$pre = infra_golive_refresh_live();
$due = infra_golive_due();

// A domain refused by the gate (nothing uploaded, or nobody has checked) does NOT
// count against the cap: it never went anywhere, and letting it eat a slot would
// quietly shrink the day's release run. It stays due, and says so every morning
// until somebody either uploads the site or overrides deliberately from the grid.
//
// Same exemption for a 'manual' result (found live 2026-11-25 — see
// infra_registrar_set_ns()'s default case): the domain's registrar isn't one of
// the auto-wired ones, so a human has to click "set nameservers" at the registrar
// by hand. Retrying it every tick can never succeed on its own, and until this
// fix it STILL counted against the cap exactly like a real release — so a
// handful of stuck manual-NS domains could permanently monopolize every cap slot,
// forever starving every genuinely-automatable domain due behind them in the
// queue. Reproduced live: the exact same 20 domains were logged as "released"
// on every 5-minute tick for hours, none of them ever actually going live, while
// 33+ other due domains (an entire appliance batch) sat starved behind them.
$released = 0; $gated = 0; $manualHeld = 0;
foreach ($due as $domain => $rec) {
    if ($released >= $cap) break;
    // Locked the same way an interactive Go Live click is (infra_pipeline_do()'s
    // 'golive' step) — this unattended run used to be the one caller that never
    // took the lock at all, so it could race a manual click on the same domain.
    $r = infra_pipeline_lock($domain, 'golive', fn() => infra_golive_release($domain));
    if (!empty($r['gated'])) {
        $gated++;
        echo "{$ts} HELD {$domain}: {$r['message']}\n";
        continue;
    }
    if (!empty($r['manual'])) {
        $manualHeld++;
        echo "{$ts} HELD {$domain} (manual nameserver change required): {$r['message']}\n";
        continue;
    }
    echo "{$ts} release {$domain}: {$r['message']}\n";
    $released++;
}

$post = infra_golive_refresh_live();

// Drain any file-date refreshes that have come due (queued after an upload, and at
// confirmed-live — see infra_golive_freshen_queue()). Each one is a full FTP download
// and re-upload of every file on a domain, so the cap stops a tick running long;
// anything still due waits for the next tick, 10 minutes later. Because the due times
// are staggered per domain this is normally 0 or 1 domain a tick, not a backlog.
$freshened = infra_golive_freshen_run_due(3);
foreach ($freshened as $fDom => $fMsg) echo "{$ts} freshen {$fDom}: {$fMsg}\n";

$remaining = max(0, count($due) - $released - $gated - $manualHeld);
echo "{$ts} tick done — newly-live: " . ($pre + $post) . ", released: {$released}/{$cap}"
   . ($gated ? ", held: {$gated} (nothing uploaded)" : '')
   . ($manualHeld ? ", held: {$manualHeld} (manual nameserver change required)" : '')
   . ($freshened ? ', freshened: ' . count($freshened) : '') . ", still-due: {$remaining}\n";

// A run that leaves a footprint is a run you can ask about later. The grid reads this
// to say when the tick last ran — a scheduler that has silently stopped is worse than
// no scheduler, and until this line existed there was no way to tell the difference.
@file_put_contents(dirname(__DIR__) . '/state/golive_tick.json', json_encode([
    // 'held' stays the combined total ($gated + $manualHeld) since existing readers
    // (_batch_golive.php, _pipeline_grid.php) already display it as one "N held"
    // figure — previously undercounted since manual-NS domains weren't in it at
    // all. 'manual_held' broken out separately for anyone who wants the split.
    'at' => time(), 'due' => count($due), 'released' => $released,
    'held' => $gated + $manualHeld, 'manual_held' => $manualHeld, 'cap' => $cap,
], JSON_PRETTY_PRINT));
