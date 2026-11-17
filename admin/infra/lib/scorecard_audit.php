<?php
/**
 * infra/lib/scorecard_audit.php — one row per live domain, joining crawl + index +
 * rank + calls side by side.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────────────
 * The other four fleet-audit cards are each independent: answering "how is
 * domain.com actually doing" means opening four separate per-domain tables and
 * combining them by hand. This needs no new data — every number here already lives
 * in one of those four modules' own tables; this only joins what they already
 * computed. See [[project_fleet_reports_recommendations]] in memory: flagged as the
 * highest-leverage fleet-reporting gap precisely because it needs zero new sources.
 *
 * SELF-CONTAINED in the same sense the others are "self-contained" — it owns no
 * table of its own (nothing to collect, nothing to store), it just requires the
 * other four and calls their own *_latest().
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/audit_settings.php';
require_once __DIR__ . '/crawl_audit.php';
require_once __DIR__ . '/index_audit.php';
require_once __DIR__ . '/rank_audit.php';
require_once __DIR__ . '/calls_audit.php';

/**
 * @param ?array $scope domain(lower) => true, from infra_audit_scope() — null means
 *        every live domain, same convention as every other *_latest() here.
 * @return array{total:int,flagged:int,rows:array}
 */
function scorecard_latest(?array $scope = null): array
{
    $crawl = crawl_audit_latest(7, $scope);
    $index = index_audit_latest($scope);
    $rank  = rank_audit_latest($scope);
    $calls = calls_audit_latest(30, $scope);

    $byCrawl = []; foreach ($crawl['rows']    as $r) $byCrawl[$r['domain']] = $r;
    $byIndex = []; foreach ($index['rows']    as $r) $byIndex[$r['domain']] = $r;
    $byRank  = []; foreach ($rank['domains']  as $r) $byRank[$r['domain']]  = $r;
    $byCalls = []; foreach ($calls['domains'] as $r) $byCalls[$r['domain']] = $r;

    $tooNewDays = (int) infra_audit_setting('too_new_days');
    $today = new DateTimeImmutable('today');

    $domains = infra_state_db()
        ->query("SELECT domain, go_live_at FROM domains WHERE status = 'live' ORDER BY domain")
        ->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($domains as $d) {
        $dom = (string) $d['domain'];
        if ($scope !== null && !isset($scope[$dom])) continue;

        $age = -1;
        if (trim((string) $d['go_live_at']) !== '') {
            try { $age = (int) $today->diff(new DateTimeImmutable(substr((string) $d['go_live_at'], 0, 10)))->days; }
            catch (Throwable $e) {}
        }
        $tooNew = $age >= 0 && $age < $tooNewDays;

        $c  = $byCrawl[$dom] ?? null;
        $ix = $byIndex[$dom] ?? null;
        $rk = $byRank[$dom]  ?? null;
        $cl = $byCalls[$dom] ?? null;

        // Flags only fire for a domain old enough to judge — a 3-day-old site with
        // nothing indexed yet is expected, not a problem; see rank_audit.php's own
        // "too new to judge" convention, same threshold, same reasoning.
        $flags = [];
        if ($c && $c['has_real_error']) $flags[] = 'crawl error';
        if (!$tooNew && $ix && $ix['indexed'] === 0 && $ix['built'] > 0) $flags[] = 'nothing indexed';
        if (!$tooNew && $rk && $rk['measured'] > 0 && $rk['top100'] === 0) $flags[] = 'not ranking';
        if ($c && $c['collected'] && $c['stale']) $flags[] = 'crawl gone quiet';

        $rows[] = [
            'domain' => $dom, 'age' => $age, 'too_new' => $tooNew, 'flags' => $flags,
            'rank_best'     => $rk['best']     ?? 0,
            'rank_top10'    => $rk['top10']    ?? 0,
            'rank_measured' => $rk['measured'] ?? 0,
            'indexed' => $ix['indexed'] ?? null,
            'built'   => $ix['built']   ?? null,
            'crawl_hits'      => $c['hits']           ?? null,
            'crawl_collected' => $c['collected']       ?? false,
            'crawl_error'     => $c['has_real_error']  ?? false,
            'calls'         => $cl['calls']   ?? 0,
            'calls_engaged' => $cl['engaged'] ?? 0,
        ];
    }

    // Actionable first: a flagged, mature domain is the thing worth a look today.
    // Too-new-to-judge sinks to the bottom regardless of flag count — nothing there
    // is wrong, there is just nothing to see yet.
    usort($rows, function ($a, $b) {
        if ($a['too_new'] !== $b['too_new']) return $a['too_new'] <=> $b['too_new'];
        return count($b['flags']) <=> count($a['flags']);
    });

    $flagged = count(array_filter($rows, fn($r) => $r['flags'] && !$r['too_new']));
    return ['total' => count($rows), 'flagged' => $flagged, 'rows' => $rows];
}

/** The per-domain table body, same lazy-render-on-open contract as the other cards. */
function scorecard_render_rows(array $rows, array $cityInfo = []): string
{
    ob_start(); ?>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['too_new'] ? 'fa-row-dim' : '' ?>">
                <td><a href="https://<?= h($r['domain']) ?>/" target="_blank" rel="noopener"><?= h($r['domain']) ?></a><?= ms_fleet_city_subline($r['domain'], $cityInfo) ?></td>
                <td class="<?= $r['too_new'] ? 'fa-dim' : '' ?>">
                    <?= $r['age'] >= 0 ? (int) $r['age'] . 'd' : '?' ?><?= $r['too_new'] ? ' · new' : '' ?>
                </td>
                <td>
                    <?php if ($r['rank_best'] > 0): ?>#<?= (int) $r['rank_best'] ?>
                    <?php elseif ($r['rank_measured'] > 0): ?><span style="color:#dc2626">nowhere</span>
                    <?php else: ?><span class="fa-dim">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['indexed'] === null): ?><span class="fa-dim">—</span>
                    <?php else: ?><?= (int) $r['indexed'] ?>/<?= (int) $r['built'] ?><?php endif; ?>
                </td>
                <td class="<?= $r['crawl_error'] ? 'fa-bad' : '' ?>">
                    <?php if (!$r['crawl_collected']): ?><span class="fa-dim">—</span>
                    <?php else: ?><?= (int) $r['crawl_hits'] ?><?php endif; ?>
                </td>
                <td><?= (int) $r['calls'] ?> / <?= (int) $r['calls_engaged'] ?></td>
                <td>
                    <?php if ($r['flags']): ?><span class="fa-warn"><?= h(implode(', ', $r['flags'])) ?></span>
                    <?php elseif ($r['too_new']): ?><span class="fa-dim">too new to judge</span>
                    <?php else: ?><span style="color:#16a34a">clean</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach;
    return ob_get_clean();
}
