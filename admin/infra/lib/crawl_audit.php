<?php
/**
 * infra/lib/crawl_audit.php — is Google actually visiting each live site, how often,
 * and what is it getting when it does?
 *
 * ── SELF-CONTAINED ───────────────────────────────────────────────────────────────
 * Owns its three tables, creates them lazily, nothing in the core schema knows this
 * feature exists. Borrows only the Hestia client (to read the logs), the box registry,
 * and crawl_verify.php — which exists as its own file precisely because getting
 * "is this really Googlebot" wrong poisons every number built on top of it.
 *
 * ── WHY THIS BEATS ASKING GOOGLE ─────────────────────────────────────────────────
 * The index check asks Google's `site:` estimate, which returned 62, then 10, then
 * nothing at all for the same query ninety seconds apart. This reads our own access
 * logs: exact, free, zero footprint, and it moves WEEKS EARLIER — crawl precedes
 * indexing, and for a fleet where most sites are days old, "Googlebot came 48 times
 * yesterday and got clean 200s" is a far more useful sentence than a `site:` band.
 *
 * ── WHY IT MUST BE A CRON, NOT A BUTTON ──────────────────────────────────────────
 * ⚠ THE LOG IS A ROLLING WINDOW. Asking Hestia for 10,000 lines of
 * baileyrestoration.com returned 3,320 — the whole file — covering 18→21 Oct, about
 * 3.5 days. A busier site holds less. Miss a week and that week is gone forever;
 * there is no backfill. Daily gives roughly 3× headroom against a missed run.
 *
 * ── THE CURSOR, AND WHY WITHOUT IT EVERY NUMBER INFLATES ─────────────────────────
 * Consecutive runs pull overlapping windows. `crawl_cursor` holds the timestamp of the
 * last line counted per domain, and anything at or before it is skipped — otherwise
 * every run re-counts the overlap and Googlebot activity climbs on its own.
 *
 * ── THREE TABLES, BECAUSE THREE DIFFERENT SHAPES OF FACT ─────────────────────────
 *   crawl_daily  summable counters per day. Re-running a day ADDS the new lines
 *                rather than replacing, which is the only correct behaviour given a
 *                cursor: the second run of a day legitimately sees only what is new.
 *   crawl_url    a RUNNING record per URL, not per day. Distinct counts cannot be
 *                summed across runs, so instead of storing a wrong daily number this
 *                keeps first/last seen per URL — which also answers the more useful
 *                question, "which pages has Google never fetched".
 *   crawl_cursor the high-water mark above.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/hestia.php';
require_once __DIR__ . '/hestia_fleet.php';
require_once __DIR__ . '/crawl_verify.php';
require_once __DIR__ . '/audit_settings.php';

/** Other crawlers worth counting, purely for context on the per-domain row. */
const CRAWL_OTHER_BOTS = ['bingbot', 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'AhrefsBot',
                          'SemrushBot', 'YandexBot', 'Bytespider', 'Applebot', 'facebookexternalhit'];

function crawl_audit_db(): PDO
{
    static $ready = false;
    $db = infra_state_db();
    if ($ready) return $db;
    $db->exec('CREATE TABLE IF NOT EXISTS crawl_daily (
        day         TEXT NOT NULL,        -- YYYY-MM-DD, the day the hits happened
        domain      TEXT NOT NULL,
        gbot_hits   INTEGER DEFAULT 0,    -- IP-VERIFIED Googlebot only
        s200 INTEGER DEFAULT 0, s304 INTEGER DEFAULT 0,
        s404 INTEGER DEFAULT 0, s5xx INTEGER DEFAULT 0,
        impostors   INTEGER DEFAULT 0,    -- UA claimed Googlebot, IP said otherwise
        other_bots  TEXT DEFAULT "{}",    -- {"bingbot":15,...} context only
        PRIMARY KEY (day, domain)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS crawl_url (
        domain     TEXT NOT NULL,
        url        TEXT NOT NULL,
        first_seen TEXT DEFAULT "",
        last_seen  TEXT DEFAULT "",
        hits       INTEGER DEFAULT 0,
        last_code  INTEGER DEFAULT 0,
        PRIMARY KEY (domain, url)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS crawl_cursor (
        domain       TEXT PRIMARY KEY,
        last_line_at TEXT DEFAULT "",     -- newest log line already counted
        last_run_at  TEXT DEFAULT "",
        window_from  TEXT DEFAULT "",     -- oldest line the last pull could see…
        window_to    TEXT DEFAULT "",     -- …and the newest, so a short window shows up
        note         TEXT DEFAULT ""
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS crawl_daily_domain ON crawl_daily (domain, day)');
    $ready = true;
    return $db;
}

/** Live domains with a box to read logs from. */
function crawl_audit_targets(string $only = ''): array
{
    $rows = infra_state_db()->query(
        "SELECT domain, niche, server_id, go_live_at FROM domains
          WHERE status = 'live' AND server_id != '' ORDER BY domain")->fetchAll(PDO::FETCH_ASSOC);
    $only = strtolower(trim($only));
    return $only ? array_values(array_filter($rows, fn($r) => $r['domain'] === $only)) : $rows;
}

/** Apache/nginx "21/Oct/2026:15:46:25 +0000" → ISO, so it sorts and compares. */
function crawl_audit_parse_time(string $stamp): string
{
    $t = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', trim($stamp));
    return $t ? $t->setTimezone(new DateTimeZone('UTC'))->format('c') : '';
}

/**
 * Collect one domain's new log lines into the tables.
 *
 * @return array{ok:bool, domain:string, counted:int, skipped:int, window:string, msg:string}
 */
function crawl_audit_collect_one(array $rec, callable $isGoogle, int $tail): array
{
    $domain = (string) $rec['domain'];
    $box = infra_hestia_server((string) $rec['server_id']);
    if (!$box) return ['ok' => false, 'domain' => $domain, 'counted' => 0, 'skipped' => 0,
                       'window' => '', 'msg' => 'box ' . $rec['server_id'] . ' not in the registry'];

    $r = hestia_api($box, 'v-list-web-domain-accesslog',
                    [hestia_fleet_user($box), $domain, (string) $tail], false);
    if (empty($r['ok'])) return ['ok' => false, 'domain' => $domain, 'counted' => 0, 'skipped' => 0,
                                 'window' => '', 'msg' => 'log read failed: ' . ($r['message'] ?? '?')];

    $db = crawl_audit_db();
    $cur = $db->prepare('SELECT last_line_at FROM crawl_cursor WHERE domain = ?');
    $cur->execute([$domain]);
    $since = (string) ($cur->fetchColumn() ?: '');

    $days = [];        // day => counters
    $urls = [];        // url => [hits, lastAt, lastCode]
    $counted = $skipped = 0;
    $winFrom = $winTo = '';
    $newest = $since;

    foreach (explode("\n", (string) ($r['raw'] ?? '')) as $line) {
        $p = crawl_verify_classify_line($line, $isGoogle);
        if (!$p) continue;
        $at = crawl_audit_parse_time($p['at']);
        if ($at === '') continue;
        if ($winFrom === '' || $at < $winFrom) $winFrom = $at;
        if ($at > $winTo) $winTo = $at;
        // The cursor is why re-running does not double count.
        if ($since !== '' && $at <= $since) { $skipped++; continue; }
        if ($at > $newest) $newest = $at;
        $counted++;

        $day = substr($at, 0, 10);
        $days[$day] ??= ['g' => 0, 's200' => 0, 's304' => 0, 's404' => 0, 's5xx' => 0, 'imp' => 0, 'other' => []];
        if ($p['class'] === 'impostor') { $days[$day]['imp']++; continue; }
        if ($p['class'] === 'other') {
            foreach (CRAWL_OTHER_BOTS as $b) {
                if (stripos($line, $b) !== false) {
                    $days[$day]['other'][$b] = ($days[$day]['other'][$b] ?? 0) + 1;
                    break;
                }
            }
            continue;
        }
        $days[$day]['g']++;
        $c = $p['code'];
        if ($c === 200) $days[$day]['s200']++;
        elseif ($c === 304) $days[$day]['s304']++;
        elseif ($c === 404) $days[$day]['s404']++;
        elseif ($c >= 500) $days[$day]['s5xx']++;
        $u = $urls[$p['url']] ?? ['hits' => 0, 'at' => '', 'code' => 0];
        $u['hits']++;
        if ($at > $u['at']) { $u['at'] = $at; $u['code'] = $c; }
        $urls[$p['url']] = $u;
    }

    // ADD, never replace: with a cursor, a second run in one day legitimately sees
    // only the new lines, so replacing would throw away the morning's hits.
    $ins = $db->prepare('INSERT INTO crawl_daily (day, domain, gbot_hits, s200, s304, s404, s5xx, impostors, other_bots)
        VALUES (:d,:dom,:g,:a,:b,:c,:e,:i,:o)
        ON CONFLICT(day, domain) DO UPDATE SET
            gbot_hits = gbot_hits + excluded.gbot_hits, s200 = s200 + excluded.s200,
            s304 = s304 + excluded.s304, s404 = s404 + excluded.s404,
            s5xx = s5xx + excluded.s5xx, impostors = impostors + excluded.impostors,
            other_bots = excluded.other_bots');
    foreach ($days as $day => $d) {
        $ins->execute([':d' => $day, ':dom' => $domain, ':g' => $d['g'], ':a' => $d['s200'],
                       ':b' => $d['s304'], ':c' => $d['s404'], ':e' => $d['s5xx'], ':i' => $d['imp'],
                       ':o' => json_encode($d['other'])]);
    }
    $iu = $db->prepare('INSERT INTO crawl_url (domain, url, first_seen, last_seen, hits, last_code)
        VALUES (:dom,:u,:f,:l,:h,:c)
        ON CONFLICT(domain, url) DO UPDATE SET
            last_seen = MAX(last_seen, excluded.last_seen),
            hits = hits + excluded.hits, last_code = excluded.last_code');
    foreach ($urls as $u => $v) {
        $iu->execute([':dom' => $domain, ':u' => $u, ':f' => $v['at'], ':l' => $v['at'],
                      ':h' => $v['hits'], ':c' => $v['code']]);
    }

    // The window actually seen is recorded so a silently truncated log shows up as a
    // short window rather than as "Google stopped visiting".
    $hours = ($winFrom !== '' && $winTo !== '') ? round((strtotime($winTo) - strtotime($winFrom)) / 3600, 1) : 0;
    $db->prepare('INSERT INTO crawl_cursor (domain, last_line_at, last_run_at, window_from, window_to, note)
        VALUES (:d,:c,:r,:f,:t,:n)
        ON CONFLICT(domain) DO UPDATE SET last_line_at = excluded.last_line_at,
            last_run_at = excluded.last_run_at, window_from = excluded.window_from,
            window_to = excluded.window_to, note = excluded.note')
       ->execute([':d' => $domain, ':c' => $newest, ':r' => gmdate('c'), ':f' => $winFrom, ':t' => $winTo,
                  ':n' => $hours > 0 && $hours < 24 ? 'window only ' . $hours . 'h — raise the log tail' : '']);

    return ['ok' => true, 'domain' => $domain, 'counted' => $counted, 'skipped' => $skipped,
            'window' => $hours . 'h', 'msg' => ''];
}

/**
 * Collect every live domain.
 *
 * ⚠ If Googlebot's ranges cannot be established this REFUSES to run rather than
 * falling back to the user-agent. On one site 30 of 78 claimed Googlebot hits were
 * impostors probing for .env files; a UA-only pass would write those into the fleet's
 * permanent history as real crawl, and nothing downstream could tell afterwards.
 */
function crawl_audit_run(string $only = '', ?callable $log = null): array
{
    $say = $log ?? function (string $s) {};
    $v = crawl_verify_checker();
    if (!$v['ok']) {
        $say('REFUSING: ' . $v['msg']);
        return ['ok' => false, 'domains' => 0, 'counted' => 0, 'failed' => 0, 'msg' => $v['msg']];
    }
    $say('Googlebot ranges: ' . $v['msg'] . ' (' . $v['source'] . ')');

    $tail = (int) infra_audit_setting('crawl_tail_lines');
    $targets = crawl_audit_targets($only);
    if (!$targets) { $say('No live domains with a box.'); return ['ok' => true, 'domains' => 0, 'counted' => 0, 'failed' => 0, 'msg' => '']; }

    $counted = $failed = 0;
    foreach ($targets as $rec) {
        $r = crawl_audit_collect_one($rec, $v['check'], $tail);
        if (!$r['ok']) { $failed++; $say('  ✗ ' . $r['domain'] . ' — ' . $r['msg']); continue; }
        $counted += $r['counted'];
        $say('  ' . str_pad($r['domain'], 32) . $r['counted'] . ' new, ' . $r['skipped']
            . ' already counted, window ' . $r['window']);
    }
    return ['ok' => true, 'domains' => count($targets), 'counted' => $counted, 'failed' => $failed, 'msg' => ''];
}

/**
 * What the panel shows: per domain, recent crawl activity and whether Google has gone
 * quiet.
 *
 * @return array{days:int, total_hits:int, crawled:int, stale:int, never:int,
 *               errors:int, collected_at:string, rows:array}
 */
/**
 * @param ?array $scope domain(lower) => true, from infra_audit_scope() — null means
 *        every live domain, unchanged from before this parameter existed.
 */
function crawl_audit_latest(int $days = 7, ?array $scope = null): array
{
    $db = crawl_audit_db();
    $since = gmdate('Y-m-d', time() - $days * 86400);
    $staleDays = (int) infra_audit_setting('stale_crawl_days');

    $agg = [];
    $st = $db->prepare('SELECT domain, SUM(gbot_hits) h, SUM(s200) a, SUM(s304) b, SUM(s404) c,
                               SUM(s5xx) e, SUM(impostors) i, MAX(day) last_day
                          FROM crawl_daily WHERE day >= ? GROUP BY domain');
    $st->execute([$since]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $agg[$r['domain']] = $r;

    $cursor = [];
    foreach ($db->query('SELECT domain, last_line_at, last_run_at, note FROM crawl_cursor')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cursor[$r['domain']] = $r;
    }
    $urlCount = [];
    foreach ($db->query('SELECT domain, COUNT(*) n FROM crawl_url GROUP BY domain')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $urlCount[$r['domain']] = (int) $r['n'];
    }

    $out = ['days' => $days, 'total_hits' => 0, 'crawled' => 0, 'stale' => 0, 'never' => 0,
            'uncollected' => 0, 'errors' => 0, 'collected_at' => '', 'rows' => []];
    foreach (crawl_audit_targets() as $t) {
        $d = $t['domain'];
        if ($scope !== null && !isset($scope[$d])) continue;
        $a = $agg[$d] ?? null;
        $c = $cursor[$d] ?? null;
        // ⚠ "WE HAVE NEVER LOOKED" IS NOT "GOOGLE HAS NEVER COME". A domain with no
        // cursor row has simply not been collected yet — on the first partial run that
        // was 107 of 108 sites, and reporting them as never crawled would have been a
        // fleet-wide false alarm on the one metric this module exists to provide.
        // Same distinction as -1 vs 0 everywhere else in these audits.
        $collected = $c !== null && ($c['last_run_at'] ?? '') !== '';
        $lastSeen = (string) ($c['last_line_at'] ?? '');
        $ageDays = $lastSeen !== '' ? (int) floor((time() - strtotime($lastSeen)) / 86400) : -1;
        $hits = (int) ($a['h'] ?? 0);
        $err  = (int) ($a['c'] ?? 0) + (int) ($a['e'] ?? 0);

        if ($c && ($c['last_run_at'] ?? '') > $out['collected_at']) $out['collected_at'] = $c['last_run_at'];
        $out['total_hits'] += $hits;
        if (!$collected) {
            $out['uncollected']++;
        } elseif ($lastSeen === '') {
            // Collected, and the window held no verified Googlebot at all.
            $out['never']++;
        } elseif ($ageDays >= $staleDays) {
            $out['stale']++;
        }
        if ($hits > 0) $out['crawled']++;
        if ($err > 0)  $out['errors']++;

        $out['rows'][] = [
            'domain' => $d, 'hits' => $hits, 'urls' => $urlCount[$d] ?? 0,
            's200' => (int) ($a['a'] ?? 0), 's304' => (int) ($a['b'] ?? 0),
            's404' => (int) ($a['c'] ?? 0), 's5xx' => (int) ($a['e'] ?? 0),
            'impostors' => (int) ($a['i'] ?? 0),
            'last_seen' => $lastSeen, 'age_days' => $ageDays,
            'collected' => $collected,
            'stale' => $collected && $lastSeen !== '' && $ageDays >= $staleDays,
            'never' => $collected && $lastSeen === '',
            'note' => (string) ($c['note'] ?? ''),
        ];
    }
    usort($out['rows'], function ($x, $y) {
        // Problems first, and an uncollected domain is NOT a problem — it ranks last,
        // because the fix for it is "run the collector", not "investigate the site".
        $rank = function ($r) {
            if (!$r['collected']) return 4;
            if ($r['never']) return 0;
            if ($r['stale']) return 1;
            return ($r['s404'] + $r['s5xx']) > 0 ? 2 : 3;
        };
        return [$rank($x), -$x['hits']] <=> [$rank($y), -$y['hits']];
    });
    return $out;
}

/**
 * The per-domain table body, pulled out so it can be rendered on demand (the admin
 * panel fetches this only once the <details> is opened) instead of shipping every
 * row's markup on every page load. Same markup the panel used to render inline.
 */
function crawl_audit_render_rows(array $rows, array $cityInfo = []): string
{
    ob_start(); ?>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= !$r['collected'] ? 'fa-row-dim' : '' ?>">
                <td><a href="https://<?= h($r['domain']) ?>/" target="_blank" rel="noopener"><?= h($r['domain']) ?></a><?= ms_fleet_city_subline($r['domain'], $cityInfo) ?></td>
                <td><?= (int) $r['hits'] ?></td>
                <td><?= (int) $r['urls'] ?></td>
                <td><?= (int) $r['s200'] ?></td>
                <td><?= (int) $r['s304'] ?></td>
                <td class="<?= $r['s404'] ? 'fa-bad' : '' ?>"><?= (int) $r['s404'] ?></td>
                <td class="<?= $r['s5xx'] ? 'fa-bad' : '' ?>"><?= (int) $r['s5xx'] ?></td>
                <td class="fa-dim"><?= (int) $r['impostors'] ?></td>
                <td>
                    <?php if (!$r['collected']): ?><span class="fa-warn">not collected</span>
                    <?php elseif ($r['last_seen'] === ''): ?><span style="color:#dc2626">never</span>
                    <?php else: ?><?= h(gmdate('j M H:i', strtotime($r['last_seen']))) ?><?php endif; ?>
                </td>
            </tr>
        <?php endforeach;
    return ob_get_clean();
}
