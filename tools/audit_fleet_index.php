<?php
/**
 * How much of each live site has Google actually taken — and is that going up or down?
 *
 * A THIN WRAPPER. All the logic lives in admin/infra/lib/index_audit.php, which owns
 * its own table and is the only thing that touches it; this file is the shell face of
 * it and the web button runs this same script. One implementation, so the terminal and
 * the browser can never disagree.
 *
 * `site:domain.com` through DataForSEO's live SERP endpoint rather than fetching Google
 * ourselves — they absorb the rate limits, and this estate has already taken two
 * outages from its own request volume.
 *
 * ⚠ A FAILED PROBE IS NOT ZERO — see index_audit.php's header. Unmeasured prints as
 * "?" and is excluded from every total.
 *
 * Measured cost: $0.0109 per domain. A sweep of ~108 live domains is about $1.18.
 * Weekly is the sane cadence; indexation does not move day to day.
 *
 * Usage:
 *   php tools/audit_fleet_index.php --dry-run     what it would probe, and the cost
 *   php tools/audit_fleet_index.php
 *   php tools/audit_fleet_index.php --domain=wrenappliancerepair.com
 */

require_once dirname(__DIR__) . '/admin/infra/lib/index_audit.php';

$opt  = getopt('', ['domain::', 'dry-run', 'concurrency::']);
$only = strtolower(trim((string) ($opt['domain'] ?? '')));
$conc = (int) ($opt['concurrency'] ?? 4);

function say(string $s = ''): void { echo $s, "\n"; }

$p = index_audit_provider();
if (!$p['ok']) { say($p['msg']); exit(1); }

$targets = index_audit_targets($only);
if (!$targets) { say($only ? "{$only}: not a live domain." : 'No live domains.'); exit(0); }

say(count($targets) . ' live domain(s) · up to ' . INDEX_PROBE_ATTEMPTS . ' attempts each · concurrency ' . $conc);
say('Estimated cost: $' . number_format(count($targets) * index_cost_per_probe(), 2)
    . ' at the measured $' . index_cost_per_probe() . ' per probe.');
if (isset($opt['dry-run'])) { say(''); say('DRY RUN — nothing probed, nothing spent, nothing stored.'); exit(0); }

say(str_repeat('─', 78));
$run = index_audit_run($only, $conc, 'say');
if ($run['sweep_at'] === '') exit(1);

$l = index_audit_latest();
say('');
say('FLEET: ' . $l['indexed'] . ' pages indexed of ' . $l['built'] . ' built across '
    . $l['measured'] . ' measured domain(s)'
    . ($l['unmeasured'] ? ' · ' . $l['unmeasured'] . ' COULD NOT BE MEASURED (not counted as zero)' : ''));

if ($l['prev_at'] === '') {
    say('No earlier sweep to compare against — this one is the baseline.');
} else {
    say('SINCE ' . substr($l['prev_at'], 0, 16) . 'Z: net ' . ($l['net'] >= 0 ? '+' : '') . $l['net']
        . ' pages · ' . $l['up'] . ' site(s) up · ' . $l['down'] . ' down');

    $down = array_filter($l['rows'], fn($r) => $r['delta'] !== null && $r['delta'] < 0);
    if ($down) {
        say('');
        say('⚠ LOST PAGES — a site going backwards is the thing worth looking at');
        foreach ($down as $r) say('   ' . str_pad($r['domain'], 32) . $r['prev'] . ' → ' . $r['indexed'] . '  (' . $r['delta'] . ')');
    }
    $up = array_reverse(array_filter($l['rows'], fn($r) => $r['delta'] !== null && $r['delta'] > 0));
    if ($up) {
        say('');
        say('GAINED');
        foreach (array_slice($up, 0, 15) as $r) say('   ' . str_pad($r['domain'], 32) . $r['prev'] . ' → ' . $r['indexed'] . '  (+' . $r['delta'] . ')');
        if (count($up) > 15) say('   … and ' . (count($up) - 15) . ' more');
    }
}

say('');
say('BY DOMAIN');
$rows = $l['rows'];
usort($rows, fn($a, $b) => $b['indexed'] <=> $a['indexed']);
foreach ($rows as $r) {
    $pct = ($r['indexed'] >= 0 && $r['built'] > 0) ? round($r['indexed'] / $r['built'] * 100) . '%' : '—';
    $d   = $r['delta'] === null ? '' : ($r['delta'] > 0 ? '+' . $r['delta'] : (string) $r['delta']);
    $note = $r['indexed'] < 0 ? 'could not measure' : ($r['too_new'] ? 'too new to judge (' . $r['age_days'] . 'd)' : '');
    printf("   %-32s %5s / %-5d %-5s %-6s %-4s %s\n", $r['domain'],
        $r['indexed'] < 0 ? '?' : $r['indexed'], $r['built'], $pct, $d,
        $r['age_days'] >= 0 ? $r['age_days'] . 'd' : '?', $note);
}
say('');
say('Stored as sweep ' . $run['sweep_at'] . '.');
