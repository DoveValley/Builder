<?php
/**
 * infra/lib/rank_audit.php — where each live site sits in Google for its own
 * keywords, searched FROM ITS OWN CITY.
 *
 * ── SELF-CONTAINED ───────────────────────────────────────────────────────────────
 * Owns its own table, creates it lazily, nothing in the core schema knows it exists.
 * Borrows only lib/keywords.php's DataForSEO client — one auth/error-shape
 * implementation for that API rather than a second free to drift from the first.
 *
 * ── WHY THE CITY MATTERS, MEASURED ───────────────────────────────────────────────
 * The same keyword answered differently depending on where it was asked from:
 *
 *   "water damage restoration Provo, UT"   national (2840)  → 66 organic, rank 66
 *                                          Provo, Utah      → 98 organic, rank 72
 *
 * Different position AND a different SERP. For a local business the city query is the
 * only honest one, so every probe is located and the location used is STORED — a rank
 * of 72 is meaningless if a later reader cannot tell where it was measured from.
 *
 * ── WHY depth 100 ────────────────────────────────────────────────────────────────
 * baileyrestoration.com sits at #72 after 34 days. A depth-10 or depth-20 sweep would
 * have reported "not ranking", which is false and is exactly the confidently-wrong
 * output this estate keeps getting bitten by. Depth costs nothing extra.
 *
 * ── THREE VALUES THAT ARE NOT THE SAME ───────────────────────────────────────────
 *   position = -1  the probe failed; WE DO NOT KNOW
 *   position =  0  measured, and the site is not in the top 100
 *   position >  0  measured, and this is where it sits
 * Collapsing the first two would turn an API hiccup into a reported ranking collapse.
 * Same convention, same reasoning, as index_audit.php and infra_cf_zone_counts().
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/keywords.php';
require_once __DIR__ . '/audit_settings.php';

/** Which keyword tiers are worth paying for: the homepage term and the money pages. */
/** Operator-set depth; see infra/lib/audit_settings.php. */
function rank_tiers(): array { return infra_audit_rank_tier_list(); }
/** Under this many days since go-live, "not ranking" is just "new". */
function rank_too_new_days(): int { return (int) infra_audit_setting('too_new_days'); }
const RANK_PROBE_ATTEMPTS = 3;
const RANK_DEPTH = 100;
/** Two sweeps closer together than this are the same measurement attempt, not a
 *  trend — see the note in rank_audit_latest(). */
const RANK_MIN_SWEEP_GAP_HOURS = 6;
/** Measured on the same endpoint the index sweep uses. */
function rank_cost_per_probe(): float { return (float) infra_audit_setting('cost_per_probe'); }

function rank_audit_db(): PDO
{
    static $ready = false;
    $db = infra_state_db();
    if ($ready) return $db;
    $db->exec('CREATE TABLE IF NOT EXISTS rank_sweep (
        sweep_at   TEXT NOT NULL,
        domain     TEXT NOT NULL,
        keyword    TEXT NOT NULL,
        position   INTEGER DEFAULT -1,  -- -1 not measured · 0 not in top N · >0 rank
        url        TEXT DEFAULT "",     -- WHICH page ranked; often not the intended one
        local_pack INTEGER DEFAULT -1,  -- -1 not measured · 0 pack shown, absent · >0 slot
        pack_shown INTEGER DEFAULT 0,   -- was a map pack on the SERP at all
        depth      INTEGER DEFAULT 0,   -- how deep we looked, so an old row stays readable
        location   TEXT DEFAULT "",     -- the location actually used, incl. any fallback
        PRIMARY KEY (sweep_at, domain, keyword)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS rank_sweep_domain ON rank_sweep (domain, sweep_at)');
    $ready = true;
    return $db;
}

/** @return array{ok:bool,msg:string,cfg:array} */
function rank_audit_provider(): array
{
    $cfg = infra_load_json(infra_config_path('keywords.json'), [])['providers']['dataforseo'] ?? [];
    if (trim((string) ($cfg['login'] ?? '')) === '' || trim((string) ($cfg['password'] ?? '')) === '') {
        return ['ok' => false, 'msg' => 'DataForSEO is not configured (Infrastructure → Keywords).', 'cfg' => []];
    }
    return ['ok' => true, 'msg' => '', 'cfg' => $cfg];
}

/** domain => the params.csv row it came from, for city/state. Parsed once. */
function rank_audit_params(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    foreach (glob(dirname(__DIR__, 3) . '/sites/*/batches/*/params.csv') ?: [] as $csv) {
        $fh = @fopen($csv, 'r');
        if (!$fh) continue;
        $head = fgetcsv($fh);
        if (!is_array($head)) { fclose($fh); continue; }
        $col = array_flip($head);
        while (($row = fgetcsv($fh)) !== false) {
            $get = fn(string $c) => isset($col[$c]) ? trim((string) ($row[$col[$c]] ?? '')) : '';
            $d = strtolower($get('domain'));
            if ($d !== '') $memo[$d] = ['city' => $get('city'), 'state' => $get('state'), 'ss' => $get('SS')];
        }
        fclose($fh);
    }
    return $memo;
}

/** The keyword templates worth tracking for one master, already tier-filtered. */
function rank_audit_templates(string $master): array
{
    static $memo = [];
    if (isset($memo[$master])) return $memo[$master];
    $f = dirname(__DIR__, 3) . '/sites/' . $master . '/data/keyword_map.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $out = [];
    foreach ((array) ($d['services'] ?? []) as $s) {
        if (!in_array((string) ($s['tier'] ?? ''), rank_tiers(), true)) continue;
        $p = trim((string) ($s['primary'] ?? ''));
        if ($p !== '') $out[] = $p;
    }
    return $memo[$master] = array_values(array_unique($out));
}

/**
 * Every (domain, keyword) pair this sweep would probe.
 *
 * LIVE only — a staged site has no nameservers switched, so it cannot rank and a zero
 * would be noise. The master comes from the `batch` tag ("water-site/b5") rather than
 * a hardcoded niche→folder map, so a new niche needs no change here.
 *
 * @return array<int,array{domain:string,keyword:string,city:string,state:string,ss:string,age:int}>
 */
function rank_audit_targets(string $only = ''): array
{
    $params = rank_audit_params();
    $rows = infra_state_db()->query(
        "SELECT domain, niche, batch, go_live_at FROM domains WHERE status = 'live' ORDER BY domain")
        ->fetchAll(PDO::FETCH_ASSOC);
    $only = strtolower(trim($only));
    $today = new DateTimeImmutable('today');
    $out = [];
    foreach ($rows as $r) {
        $d = $r['domain'];
        if ($only !== '' && $d !== $only) continue;
        $p = $params[$d] ?? null;
        if (!$p || $p['city'] === '') continue;                   // cannot localise it; skip rather than guess
        $master = explode('/', (string) $r['batch'])[0] ?? '';
        $age = -1;
        if (trim((string) $r['go_live_at']) !== '') {
            try { $age = (int) $today->diff(new DateTimeImmutable(substr((string) $r['go_live_at'], 0, 10)))->days; }
            catch (Throwable $e) { $age = -1; }
        }
        foreach (rank_audit_templates($master) as $tpl) {
            $kw = str_replace(['{city}', '{ST}', '{state}'], [$p['city'], $p['ss'], $p['state']], $tpl);
            $out[] = ['domain' => $d, 'keyword' => $kw, 'city' => $p['city'],
                      'state' => $p['state'], 'ss' => $p['ss'], 'age' => $age];
        }
    }
    return $out;
}

/**
 * Probe every target, retrying transient failures, and store the sweep.
 *
 * Location falls back city → state → national, and WHICH was used is stored, so two
 * sweeps are never silently compared across different measurements.
 */
function rank_audit_run(string $only = '', int $concurrency = 4, ?callable $log = null): array
{
    $say = $log ?? function (string $s) {};
    $p = rank_audit_provider();
    if (!$p['ok']) { $say($p['msg']); return ['sweep_at' => '', 'probed' => 0, 'measured' => 0]; }

    $targets = rank_audit_targets($only);
    if (!$targets) { $say('No live domains with keywords to probe.'); return ['sweep_at' => '', 'probed' => 0, 'measured' => 0]; }

    $locOf = fn(array $t) => $t['city'] . ',' . $t['state'] . ',United States';
    $found = [];                                  // index into $targets => row to store
    $pending = array_keys($targets);

    // ⚠ THE SWEEP ID IS MINTED NOW, AND RESULTS ARE WRITTEN AFTER EVERY ATTEMPT.
    //
    // The first version stored nothing until all three passes finished. A run killed
    // partway through on 2026-10-21 had already spent $12.28 across two passes and
    // left NOTHING — every probe paid for and discarded. Money spent is a fact the
    // moment the API answers; holding it in memory until the end means an interrupted
    // run is pure loss. Now an interruption leaves a smaller but perfectly valid
    // sweep containing exactly what was paid for.
    $sweepAt = gmdate('c');
    $db  = rank_audit_db();
    $ins = $db->prepare('INSERT INTO rank_sweep
        (sweep_at, domain, keyword, position, url, local_pack, pack_shown, depth, location)
        VALUES (:s,:d,:k,:p,:u,:lp,:ps,:dp,:loc)
        ON CONFLICT(sweep_at, domain, keyword) DO UPDATE SET
            position = excluded.position, url = excluded.url, local_pack = excluded.local_pack,
            pack_shown = excluded.pack_shown, depth = excluded.depth, location = excluded.location');
    $store = function (array $idxs) use ($ins, $targets, &$found, $sweepAt) {
        foreach ($idxs as $i) {
            $t = $targets[$i];
            $f = $found[$i] ?? ['position' => -1, 'url' => '', 'local_pack' => -1,
                                'pack_shown' => 0, 'depth' => 0, 'location' => ''];
            $ins->execute([':s' => $sweepAt, ':d' => $t['domain'], ':k' => $t['keyword'],
                           ':p' => $f['position'], ':u' => $f['url'], ':lp' => $f['local_pack'],
                           ':ps' => $f['pack_shown'], ':dp' => $f['depth'], ':loc' => $f['location']]);
        }
    };

    for ($attempt = 1; $attempt <= RANK_PROBE_ATTEMPTS && $pending; $attempt++) {
        // BACK OFF BEFORE RETRYING. Measured: 2 of 6 keywords failed all three attempts
        // in a sweep, then both succeeded first time when run on their own a minute
        // later. The failures are throttling, not bad keywords — so retrying instantly
        // just spends three probes inside the same rate-limit window and calls a
        // measurable site unmeasurable. Growing the gap is what makes the retry real.
        if ($attempt > 1) {
            $wait = 5 * ($attempt - 1);
            $say('backing off ' . $wait . 's before retry — the failures look like throttling, not bad keywords');
            sleep($wait);
        }
        // The last attempt drops to national rather than failing outright: a city
        // DataForSEO cannot resolve should still yield a number, clearly labelled.
        $useNational = ($attempt === RANK_PROBE_ATTEMPTS);
        $tasks = [];
        foreach ($pending as $i) {
            $t = $targets[$i];
            $task = ['keyword' => $t['keyword'], 'language_code' => 'en', 'depth' => RANK_DEPTH];
            if ($useNational) $task['location_code'] = 2840;
            else              $task['location_name'] = $locOf($t);
            $tasks[] = $task;
        }
        $res = infra_kw_dfs_post_many($p['cfg'], 'serp/google/organic/live/advanced', $tasks, max(1, min(8, $concurrency)));
        $retry = [];
        foreach ($pending as $k => $i) {
            if (empty($res[$k]['ok'])) { $retry[] = $i; continue; }
            $items = (array) ($res[$k]['result'][0]['items'] ?? []);
            $t = $targets[$i];
            $pos = 0; $url = ''; $n = 0; $pack = 0; $packShown = 0;
            foreach ($items as $it) {
                $type = (string) ($it['type'] ?? '');
                if ($type === 'local_pack') {
                    $packShown = 1;
                    if ($pack === 0 && stripos((string) ($it['domain'] ?? ''), $t['domain']) !== false) {
                        $pack = ($it['rank_group'] ?? 0) ?: 1;
                    }
                    continue;
                }
                if ($type !== 'organic') continue;
                $n++;
                if ($pos === 0 && stripos((string) ($it['domain'] ?? ''), $t['domain']) !== false) {
                    $pos = $n;
                    $url = (string) ($it['url'] ?? '');
                }
            }
            $found[$i] = ['position' => $pos, 'url' => $url, 'local_pack' => $packShown ? $pack : -1,
                          'pack_shown' => $packShown, 'depth' => RANK_DEPTH,
                          'location' => $useNational ? 'United States (national fallback)' : $locOf($t)];
        }
        // Write what this pass bought BEFORE starting the next one.
        $measuredNow = array_values(array_diff($pending, $retry));
        if ($measuredNow) $store($measuredNow);
        $say('attempt ' . $attempt . ($useNational ? ' (national fallback)' : '') . ': '
            . count($measuredNow) . ' measured and stored, ' . count($retry) . ' to retry');
        $pending = $retry;
    }
    // Anything still unknown is written as -1 — a recorded absence of a measurement,
    // never confused with "not in the top 100".
    if ($pending) $store($pending);

    return ['sweep_at' => $sweepAt, 'probed' => count($targets), 'measured' => count($found)];
}

/**
 * Has enough time passed since the last STORED sweep (manual or automatic — either
 * counts) to justify another paid one? Used by rank_tick.php so a weekly cron can run
 * daily and only actually spend money on the day it's due, and so a cadence change
 * takes effect against whatever was last really run rather than a separate clock.
 *
 * `enabled` is the ON/OFF switch (rank_cadence_enabled): when off, `due` is forced
 * false regardless of the clock, but `next_due_at` is still reported — "paused, would
 * next run on {date}" is a more honest UI than hiding the date entirely. The cron
 * itself is never installed/removed by this toggle, only what it finds when it asks.
 *
 * @return array{due:bool,enabled:bool,last_at:string,next_due_at:string,cadence_weeks:int}
 */
function rank_audit_due(): array
{
    $weeks = (int) infra_audit_setting('rank_cadence_weeks');
    $enabled = (bool) infra_audit_setting('rank_cadence_enabled');
    $db = rank_audit_db();
    $last = (string) ($db->query('SELECT MAX(sweep_at) FROM rank_sweep')->fetchColumn() ?: '');
    if ($last === '') {
        return ['due' => $enabled, 'enabled' => $enabled, 'last_at' => '',
                 'next_due_at' => '', 'cadence_weeks' => $weeks];
    }
    $nextTs = strtotime($last) + $weeks * 7 * 86400;
    return ['due' => $enabled && time() >= $nextTs, 'enabled' => $enabled, 'last_at' => $last,
             'next_due_at' => gmdate('c', $nextTs), 'cadence_weeks' => $weeks];
}

/**
 * The latest sweep, per keyword and rolled up per domain, with movement since the
 * sweep before it.
 *
 * ⚠ Only measurement is compared to measurement. A -1 on either side is an absence of
 * a reading, not a change, and showing it as movement would invent a fact.
 * "Improved" means a SMALLER position number, so the delta is reported as
 * previous − current: positive = moved up the page.
 *
 * @param ?array $scope domain(lower) => true, from infra_audit_scope() — null means
 *        every live domain, unchanged from before this parameter existed.
 */
function rank_audit_latest(?array $scope = null): array
{
    $empty = ['at' => '', 'prev_at' => '', 'keywords' => 0, 'measured' => 0, 'unmeasured' => 0,
              'top10' => 0, 'top20' => 0, 'top100' => 0, 'nowhere' => 0, 'in_pack' => 0,
              'up' => 0, 'down' => 0, 'domains' => []];
    $db = rank_audit_db();
    // Every DISTINCT sweep_at, not just the last two — a same-session rerun sits
    // between them and would otherwise be picked as "prev" by LIMIT 2 alone.
    $sweeps = array_column($db->query('SELECT DISTINCT sweep_at FROM rank_sweep ORDER BY sweep_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC), 'sweep_at');
    if (!$sweeps) return $empty;
    $at = $sweeps[0];
    // ⚠ "PREV" MUST BE A DIFFERENT MEASUREMENT, NOT A RERUN OF THE SAME ONE.
    // 2026-10-21: gannmoldremediation.com's "mold remediation Vista, CA" read #1 then
    // "nowhere" — a real-looking collapse. The two sweeps compared were 59 SECONDS
    // apart, both from the same evening of testing this module. That is SERP noise on
    // a 21-day-old site, not Google re-ranking it twice inside a minute. Rank sweeps
    // are button-only (monthly/weekly cadence once mature), so anything closer than
    // this counts as the same attempt, not a trend — skip it rather than report it.
    $prevAt = '';
    $atTs = strtotime($at);
    foreach (array_slice($sweeps, 1) as $s) {
        if (($atTs - strtotime($s)) >= RANK_MIN_SWEEP_GAP_HOURS * 3600) { $prevAt = $s; break; }
    }

    $st = $db->prepare('SELECT domain, keyword, position, url, local_pack, pack_shown, location
                        FROM rank_sweep WHERE sweep_at = ?');
    $st->execute([$at]);
    $cur = $st->fetchAll(PDO::FETCH_ASSOC);
    $prev = [];
    if ($prevAt !== '') {
        $st->execute([$prevAt]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $prev[$r['domain'] . "\0" . $r['keyword']] = (int) $r['position'];
    }

    // Ages, so the panel can mark a site too new rather than failing it.
    $age = [];
    foreach (infra_state_db()->query("SELECT domain, go_live_at FROM domains WHERE status='live'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = -1;
        if (trim((string) $r['go_live_at']) !== '') {
            try { $a = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(substr((string) $r['go_live_at'], 0, 10)))->days; }
            catch (Throwable $e) {}
        }
        $age[$r['domain']] = $a;
    }

    $out = $empty;
    $out['at'] = $at; $out['prev_at'] = $prevAt;
    $byDomain = [];
    foreach ($cur as $r) {
        $d = $r['domain'];
        if ($scope !== null && !isset($scope[$d])) continue;
        $pos = (int) $r['position'];
        $out['keywords']++;
        $byDomain[$d] ??= ['domain' => $d, 'age' => $age[$d] ?? -1, 'keywords' => 0, 'measured' => 0,
                           'best' => 0, 'top10' => 0, 'top100' => 0, 'in_pack' => 0,
                           'up' => 0, 'down' => 0, 'rows' => []];
        $b = &$byDomain[$d];
        $b['keywords']++;
        if ($pos < 0) { $out['unmeasured']++; }
        else {
            $out['measured']++; $b['measured']++;
            if ($pos === 0) $out['nowhere']++;
            else {
                $out['top100']++; $b['top100']++;
                if ($pos <= 10) { $out['top10']++; $b['top10']++; }
                if ($pos <= 20) $out['top20']++;
                if ($b['best'] === 0 || $pos < $b['best']) $b['best'] = $pos;
            }
            if ((int) $r['local_pack'] > 0) { $out['in_pack']++; $b['in_pack']++; }
        }
        $delta = null;
        $pk = $d . "\0" . $r['keyword'];
        if ($pos >= 0 && isset($prev[$pk]) && $prev[$pk] >= 0) {
            // Both "0" means still nowhere — no movement, not a tie at rank zero.
            if (!($pos === 0 && $prev[$pk] === 0)) {
                // Entering or leaving the top 100 is movement; treat absent as 101.
                $a1 = $prev[$pk] === 0 ? 101 : $prev[$pk];
                $b1 = $pos === 0 ? 101 : $pos;
                $delta = $a1 - $b1;                 // positive = moved UP the page
                if ($delta > 0) { $out['up']++; $b['up']++; }
                elseif ($delta < 0) { $out['down']++; $b['down']++; }
            }
        }
        $b['rows'][] = ['keyword' => $r['keyword'], 'position' => $pos, 'url' => (string) $r['url'],
                        'local_pack' => (int) $r['local_pack'], 'pack_shown' => (int) $r['pack_shown'],
                        'delta' => $delta, 'prev' => $prev[$pk] ?? null, 'location' => (string) $r['location']];
        unset($b);
    }
    usort($byDomain, function ($x, $y) {
        $bx = $x['best'] ?: 999; $by = $y['best'] ?: 999;
        return [$bx, -$x['top100']] <=> [$by, -$y['top100']];
    });
    $out['domains'] = array_values($byDomain);
    return $out;
}

/**
 * The per-site table body, fetched on demand when the <details> is opened rather
 * than shipped on every page load. Same markup the panel used to render inline.
 */
function rank_audit_render_rows(array $domains, int $tooNewDays): string
{
    ob_start(); ?>
        <?php foreach ($domains as $d):
            $tooNew = $d['age'] >= 0 && $d['age'] < $tooNewDays; ?>
            <tr class="<?= $tooNew ? 'fa-dim' : '' ?>">
                <td><a href="https://<?= h($d['domain']) ?>/" target="_blank" rel="noopener"><?= h($d['domain']) ?></a></td>
                <td><strong><?= $d['best'] ?: '—' ?></strong></td>
                <td class="<?= $d['top10'] ? 'fa-good' : '' ?>"><?= (int) $d['top10'] ?></td>
                <td><?= (int) $d['top100'] ?></td>
                <td><?= (int) $d['keywords'] ?></td>
                <td class="<?= $d['in_pack'] ? 'fa-good' : '' ?>"><?= (int) $d['in_pack'] ?></td>
                <td style="color:#16a34a"><?= $d['up'] ?: '' ?></td>
                <td style="color:#dc2626"><?= $d['down'] ?: '' ?></td>
                <td><?= $d['age'] >= 0 ? (int) $d['age'] . 'd' : '?' ?><?= $tooNew ? ' · too new' : '' ?></td>
            </tr>
        <?php endforeach;
    return ob_get_clean();
}
