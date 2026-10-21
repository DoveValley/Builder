<?php
/**
 * Where each live site sits in Google for its own keywords, searched from its own city.
 *
 * A THIN WRAPPER. All logic is in admin/infra/lib/rank_audit.php, which owns its own
 * table; this is the shell face of it and the web button runs this same script, so the
 * terminal and the browser can never disagree.
 *
 * ⚠ position -1 = not measured · 0 = measured, not in the top 100 · >0 = the rank.
 * Those are three different facts and are never collapsed — see the module header.
 *
 * Tracks the homepage term plus the high-1 money keywords, located to each site's own
 * city because the same keyword answers differently from a different place (measured:
 * Provo 72 vs national 66 for the same query). ~$6.18 per sweep of 108 live sites.
 *
 * Usage:
 *   php tools/audit_fleet_rank.php --dry-run
 *   php tools/audit_fleet_rank.php
 *   php tools/audit_fleet_rank.php --domain=baileyrestoration.com
 *   php tools/audit_fleet_rank.php --if-due     # cron: only spends if the
 *                                                 configured cadence has elapsed
 */

require_once dirname(__DIR__) . '/admin/infra/lib/rank_audit.php';
require_once dirname(__DIR__) . '/admin/infra/lib/audit_settings.php';

$opt  = getopt('', ['domain::', 'dry-run', 'concurrency::', 'if-due']);
$only = strtolower(trim((string) ($opt['domain'] ?? '')));
$conc = (int) ($opt['concurrency'] ?? 4);

function say(string $s = ''): void { echo $s, "\n"; }

// ⚠ THIS IS THE ONLY GATE BETWEEN A DAILY CRON AND A DAILY BILL. --if-due lets the
// crontab line run every day (cheap to check, nothing stored by a no-op check) while
// still only actually probing on the day the operator-set cadence says to. Always
// writes a heartbeat so a cron that's silently stopped firing is visible, the same
// reason golive_tick/filedate_tick do.
if (isset($opt['if-due'])) {
    $due = rank_audit_due();
    @file_put_contents(dirname(__DIR__) . '/admin/infra/state/rank_tick.json', json_encode([
        'checked_at' => gmdate('c'), 'due' => $due['due'], 'last_at' => $due['last_at'],
        'next_due_at' => $due['next_due_at'], 'cadence_weeks' => $due['cadence_weeks'],
    ], JSON_PRETTY_PRINT));
    if (!$due['due']) {
        say('Not due — cadence is every ' . $due['cadence_weeks'] . ' week(s), last swept '
            . $due['last_at'] . ', next due ' . $due['next_due_at'] . '. Nothing probed, nothing spent.');
        exit(0);
    }
    say('Due (cadence ' . $due['cadence_weeks'] . ' week(s), last swept '
        . ($due['last_at'] ?: 'never') . ') — running the full sweep.');
}

$p = rank_audit_provider();
if (!$p['ok']) { say($p['msg']); exit(1); }

$targets = rank_audit_targets($only);
if (!$targets) { say($only ? "{$only}: not live, or has no city." : 'Nothing to probe.'); exit(0); }

$doms = count(array_unique(array_column($targets, 'domain')));
say(count($targets) . ' keyword(s) across ' . $doms . ' live site(s) · depth ' . RANK_DEPTH
    . ' · up to ' . RANK_PROBE_ATTEMPTS . ' attempts · concurrency ' . $conc);
// ⚠ QUOTE THE RANGE, NOT THE BEST CASE. The first full sweep was quoted at $6.53 for
// 599 probes and actually cost $12.28, because 136 keywords needed a second pass and
// 70 a third — retries are probes and they are billed. Quoting only the floor is the
// same wrong-in-the-reassuring-direction habit this estate keeps paying for.
$rkLow  = count($targets) * rank_cost_per_probe();
say('Estimated cost: $' . number_format($rkLow, 2) . ' if nothing retries, up to about $'
    . number_format($rkLow * 2.0, 2) . ' with retries — the last full sweep came in at 1.9x.');
if (isset($opt['dry-run'])) {
    say('');
    foreach (array_slice($targets, 0, 10) as $t)
        say('   ' . str_pad($t['domain'], 30) . '"' . $t['keyword'] . '"  from ' . $t['city'] . ', ' . $t['ss']);
    if (count($targets) > 10) say('   … and ' . (count($targets) - 10) . ' more');
    say('');
    say('DRY RUN — nothing probed, nothing spent, nothing stored.');
    exit(0);
}

say(str_repeat('─', 78));
$run = rank_audit_run($only, $conc, 'say');
if ($run['sweep_at'] === '') exit(1);

$l = rank_audit_latest();
say('');
say('FLEET: ' . $l['top10'] . ' keyword(s) in the top 10 · ' . $l['top20'] . ' top 20 · '
    . $l['top100'] . ' in the top 100 · ' . $l['nowhere'] . ' nowhere'
    . ($l['unmeasured'] ? ' · ' . $l['unmeasured'] . ' COULD NOT BE MEASURED (not counted as nowhere)' : ''));
say('LOCAL PACK: in it for ' . $l['in_pack'] . ' keyword(s).');
if ($l['prev_at'] === '') {
    say('No earlier sweep to compare against — this one is the baseline.');
} else {
    say('SINCE ' . substr($l['prev_at'], 0, 16) . 'Z: ' . $l['up'] . ' keyword(s) up · ' . $l['down'] . ' down');
}

say('');
say('BY SITE  (best = highest-ranking keyword; "—" = nothing in the top 100)');
foreach ($l['domains'] as $d) {
    $tooNew = $d['age'] >= 0 && $d['age'] < rank_too_new_days();
    printf("   %-32s best %-5s  top10 %-3d top100 %-3d pack %-3d %-5s %s\n",
        $d['domain'], $d['best'] ?: '—', $d['top10'], $d['top100'], $d['in_pack'],
        $d['age'] >= 0 ? $d['age'] . 'd' : '?', $tooNew ? 'too new to judge' : '');
}

$moved = [];
foreach ($l['domains'] as $d) foreach ($d['rows'] as $r)
    if ($r['delta'] !== null && $r['delta'] !== 0) $moved[] = [$d['domain'], $r];
if ($moved) {
    usort($moved, fn($a, $b) => $a[1]['delta'] <=> $b[1]['delta']);
    say('');
    say('BIGGEST MOVES  (positive = moved up the page)');
    foreach (array_slice($moved, 0, 10) as [$dom, $r])
        printf("   %-28s %-42s %s → %s  (%s)\n", $dom, substr($r['keyword'], 0, 40),
            $r['prev'] === 0 ? '—' : $r['prev'], $r['position'] === 0 ? '—' : $r['position'],
            ($r['delta'] > 0 ? '+' : '') . $r['delta']);
    if (count($moved) > 10) {
        say('   … and ' . (count($moved) - 10) . ' more');
    }
}
say('');
say('Stored as sweep ' . $run['sweep_at'] . '.');
