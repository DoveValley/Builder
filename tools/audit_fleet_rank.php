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
 */

require_once dirname(__DIR__) . '/admin/infra/lib/rank_audit.php';

$opt  = getopt('', ['domain::', 'dry-run', 'concurrency::']);
$only = strtolower(trim((string) ($opt['domain'] ?? '')));
$conc = (int) ($opt['concurrency'] ?? 4);

function say(string $s = ''): void { echo $s, "\n"; }

$p = rank_audit_provider();
if (!$p['ok']) { say($p['msg']); exit(1); }

$targets = rank_audit_targets($only);
if (!$targets) { say($only ? "{$only}: not live, or has no city." : 'Nothing to probe.'); exit(0); }

$doms = count(array_unique(array_column($targets, 'domain')));
say(count($targets) . ' keyword(s) across ' . $doms . ' live site(s) · depth ' . RANK_DEPTH
    . ' · up to ' . RANK_PROBE_ATTEMPTS . ' attempts · concurrency ' . $conc);
say('Estimated cost: $' . number_format(count($targets) * rank_cost_per_probe(), 2)
    . ' at the measured $' . rank_cost_per_probe() . ' per probe.');
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
