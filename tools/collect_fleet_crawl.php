<?php
/**
 * Collect Googlebot activity for every live site from its own access log.
 *
 * A THIN WRAPPER. All logic is in admin/infra/lib/crawl_audit.php, which owns its
 * tables; the cron and the panel button both run this same script.
 *
 * ⚠ RUN THIS DAILY. The access log Hestia serves is a ROLLING WINDOW — Bailey's whole
 * file was 3,320 lines covering 3.5 days, and a busier site holds less. Whatever falls
 * out of the window before a run is gone permanently; there is no backfill.
 *
 * ⚠ Googlebot is verified BY IP, never by user-agent. On one site 30 of 78 requests
 * claiming to be Googlebot were impostors probing for .env files. If the ranges cannot
 * be fetched or read from cache this refuses to run rather than recording scanner
 * traffic as crawl.
 *
 * Free. Reads our own machines; nothing is asked of Google.
 *
 * Usage:
 *   php tools/collect_fleet_crawl.php --dry-run
 *   php tools/collect_fleet_crawl.php
 *   php tools/collect_fleet_crawl.php --domain=baileyrestoration.com
 *   php tools/collect_fleet_crawl.php --report          (no collection, just show)
 */

require_once dirname(__DIR__) . '/admin/infra/lib/crawl_audit.php';

$opt  = getopt('', ['domain::', 'dry-run', 'report', 'days::']);
$only = strtolower(trim((string) ($opt['domain'] ?? '')));
$days = max(1, (int) ($opt['days'] ?? 7));

function say(string $s = ''): void { echo $s, "\n"; }

if (!isset($opt['report'])) {
    $targets = crawl_audit_targets($only);
    say(count($targets) . ' live domain(s) · tail ' . (int) infra_audit_setting('crawl_tail_lines')
        . ' lines each · free, reads our own boxes');
    if (isset($opt['dry-run'])) {
        $v = crawl_verify_checker();
        say('Googlebot ranges: ' . ($v['ok'] ? $v['msg'] . ' (' . $v['source'] . ')' : 'UNAVAILABLE — ' . $v['msg']));
        say('');
        say('DRY RUN — nothing read, nothing stored.');
        exit($v['ok'] ? 0 : 1);
    }
    say(str_repeat('─', 78));
    $run = crawl_audit_run($only, 'say');
    if (!$run['ok']) exit(1);
    say('');
    say('Collected ' . $run['counted'] . ' new log line(s) across ' . $run['domains'] . ' domain(s)'
        . ($run['failed'] ? ' · ' . $run['failed'] . ' could not be read' : ''));
}

$l = crawl_audit_latest($days);
say('');
say('LAST ' . $days . ' DAYS: ' . $l['total_hits'] . ' verified Googlebot hits · '
    . $l['crawled'] . ' site(s) crawled · ' . $l['stale'] . ' gone quiet · '
    . $l['never'] . ' never seen · ' . $l['errors'] . ' serving errors to Google'
    . ($l['uncollected'] ? ' · ' . $l['uncollected'] . ' NOT COLLECTED YET (not a crawl problem)' : ''));

$bad = array_filter($l['rows'], fn($r) => $r['never'] || $r['stale'] || $r['s404'] || $r['s5xx']);
if ($bad) {
    say('');
    say('NEEDS A LOOK');
    foreach ($bad as $r) {
        $why = $r['never'] ? 'collected, no Googlebot' : ($r['stale'] ? 'last seen ' . $r['age_days'] . 'd ago' : '');
        $errs = ($r['s404'] ? $r['s404'] . '×404 ' : '') . ($r['s5xx'] ? $r['s5xx'] . '×5xx' : '');
        printf("   %-32s %-22s %s\n", $r['domain'], $why, $errs);
    }
}

say('');
say('BY SITE');
printf("   %-32s %6s %6s %6s %6s %6s %6s  %s\n", 'domain', 'hits', 'urls', '200', '304', '404', '5xx', 'last seen');
foreach ($l['rows'] as $r) {
    printf("   %-32s %6d %6d %6d %6d %6d %6d  %s%s\n", $r['domain'], $r['hits'], $r['urls'],
        $r['s200'], $r['s304'], $r['s404'], $r['s5xx'],
        $r['last_seen'] !== '' ? gmdate('j M H:i', strtotime($r['last_seen'])) : ($r['collected'] ? 'never' : 'not collected'),
        $r['note'] !== '' ? '  ⚠ ' . $r['note'] : '');
}
