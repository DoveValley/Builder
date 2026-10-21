<?php
/**
 * infra/lib/index_audit.php — how much of each live site Google has actually taken,
 * and whether that is going up or down.
 *
 * ── SELF-CONTAINED ON PURPOSE ────────────────────────────────────────────────────
 * This module owns its own table, creates it lazily, and is the only thing that reads
 * or writes it. Nothing in the core schema (state.php) knows this feature exists, so
 * it can be changed or deleted whole without touching the fleet's own state. The only
 * things it borrows are the two places it would otherwise be duplicating a rule:
 *
 *   · lib/keywords.php's DataForSEO client — one HTTP/auth/error-shape implementation
 *     for that API, not a second copy that is free to drift from the first.
 *   · lib/pipeline.php's built index — the domain→build-folder slug rule, whose own
 *     docblock warns that restating it has already collided two domains once.
 *
 * Both are read-only uses. Nothing here writes to fleet state.
 *
 * ── WHY A HISTORY AND NOT A NUMBER ───────────────────────────────────────────────
 * "17 pages indexed" means nothing alone: good news on a two-week-old domain, bad news
 * on one that read 25 last week. Every sweep is stored as its own rows and the panel
 * reads the DELTA. A site going DOWN is the single most valuable thing this surfaces,
 * and no snapshot can show it.
 *
 * ── THE RULE THAT MATTERS MOST ───────────────────────────────────────────────────
 * ⚠ A FAILED PROBE IS NOT ZERO. DataForSEO returns a transient "Internal SE Server
 * Error" often enough to matter — 2 of the first 6 probes failed, both fine on retry,
 * and one of them (gannmoldremediation.com) is 81% indexed. Recording that as 0 would
 * invent a deindexing event and rank it as the worst drop on the page. Unmeasured is
 * stored as -1 and never folded in with a real zero, the same convention and the same
 * reasoning as infra_cf_zone_counts()'s -1.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/keywords.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/audit_settings.php';

/**
 * ⚠⚠ READ THIS BEFORE TRUSTING A DROP.
 *
 * Google's `site:` result count is an ESTIMATE and it swings hard. Measured on
 * 2026-10-21, the identical query for baileyrestoration.com run three times
 * back-to-back returned **62, then 10, then nothing at all**. Nothing about the site
 * changed in those ninety seconds.
 *
 * So a single sweep's number is a band, not a measurement, and a difference between
 * two sweeps is mostly noise. The module therefore:
 *   · never treats an absent count as zero (that is a retry, then unmeasured), and
 *   · only calls a drop real when CONSECUTIVE sweeps agree it dropped — the same
 *     two-strikes rule the production monitor uses to avoid paging on one bad probe.
 * A one-sweep fall is recorded but not alarmed on.
 *
 * What this metric is good for: "is this site in the index at all, and roughly how
 * big is its footprint". What it is not good for: exact page counts, or week-on-week
 * movement of less than a step change.
 */
/** Operator-set; see infra/lib/audit_settings.php. The constants remain as the
 * floor the clamp enforces, not as the value anything reads. */
function index_drop_needs_consecutive(): int { return (int) infra_audit_setting('index_drop_sweeps'); }

/** Under this many days since go-live, "not indexed" is just "new". */
function index_too_new_days(): int { return (int) infra_audit_setting('too_new_days'); }
/** Transient SE errors are common; a probe is only hopeless after this many tries. */
const INDEX_PROBE_ATTEMPTS = 3;
/** Measured, not guessed: balance moved $70.3400 → $70.2206 over 11 probes. */
function index_cost_per_probe(): float { return (float) infra_audit_setting('cost_per_probe'); }

/** Create this module's own table the first time anything touches it. */
function index_audit_db(): PDO
{
    static $ready = false;
    $db = infra_state_db();
    if ($ready) return $db;
    $db->exec('CREATE TABLE IF NOT EXISTS index_sweep (
        sweep_at TEXT NOT NULL,          -- ISO-8601; identifies the sweep
        domain   TEXT NOT NULL,
        indexed  INTEGER DEFAULT -1,     -- pages Google reports; -1 = NOT MEASURED
        built    INTEGER DEFAULT 0,      -- pages on disk at sweep time, for the ratio
        age_days INTEGER DEFAULT -1,     -- days since go-live; -1 = unknown
        note     TEXT DEFAULT "",
        PRIMARY KEY (sweep_at, domain)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS index_sweep_domain ON index_sweep (domain, sweep_at)');
    $ready = true;
    return $db;
}

/** Is DataForSEO usable at all? @return array{ok:bool,msg:string,cfg:array} */
function index_audit_provider(): array
{
    $cfg = infra_load_json(infra_config_path('keywords.json'), [])['providers']['dataforseo'] ?? [];
    if (trim((string) ($cfg['login'] ?? '')) === '' || trim((string) ($cfg['password'] ?? '')) === '') {
        return ['ok' => false, 'msg' => 'DataForSEO is not configured (Infrastructure → Keywords).', 'cfg' => []];
    }
    return ['ok' => true, 'msg' => '', 'cfg' => $cfg];
}

/**
 * The domains worth probing: LIVE only.
 *
 * A staged domain's nameservers have not been switched, so Google cannot have indexed
 * it and a zero would carry no information — it would just drag the fleet total down
 * and make the panel look like a problem.
 */
function index_audit_targets(string $only = ''): array
{
    $rows = infra_state_db()->query(
        "SELECT domain, niche, go_live_at FROM domains WHERE status = 'live' ORDER BY domain")->fetchAll(PDO::FETCH_ASSOC);
    $only = strtolower(trim($only));
    return $only ? array_values(array_filter($rows, fn($r) => $r['domain'] === $only)) : $rows;
}

/** index.html files on disk for a domain's newest build — the denominator. */
function index_audit_built_counts(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    foreach (infra_pipeline_built_index() as $slug => $b) {
        $n = 0;
        foreach (new RecursiveIteratorIterator(
                     new RecursiveDirectoryIterator($b['dir'], FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getFilename() === 'index.html') $n++;
        }
        $memo[$slug] = $n;
    }
    return $memo;
}

/** Days since go-live, or -1 when it cannot be worked out. */
function index_audit_age(string $goLiveAt): int
{
    if (trim($goLiveAt) === '') return -1;
    try { return (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(substr($goLiveAt, 0, 10)))->days; }
    catch (Throwable $e) { return -1; }
}

/**
 * Probe every target, retrying transient failures, and store the sweep.
 *
 * @param callable|null $log  optional progress sink, called with one line at a time
 * @return array{sweep_at:string, measured:int, unmeasured:int, indexed:int, built:int}
 */
function index_audit_run(string $only = '', int $concurrency = 4, ?callable $log = null): array
{
    $say = $log ?? function (string $s) {};
    $p = index_audit_provider();
    if (!$p['ok']) { $say($p['msg']); return ['sweep_at' => '', 'measured' => 0, 'unmeasured' => 0, 'indexed' => 0, 'built' => 0]; }

    $rows = index_audit_targets($only);
    if (!$rows) { $say('No live domains to probe.'); return ['sweep_at' => '', 'measured' => 0, 'unmeasured' => 0, 'indexed' => 0, 'built' => 0]; }

    $built  = index_audit_built_counts();
    $result = [];
    $pending = array_column($rows, 'domain');

    for ($attempt = 1; $attempt <= INDEX_PROBE_ATTEMPTS && $pending; $attempt++) {
        // BACK OFF BEFORE RETRYING — the failures are throttling, not bad domains.
        // Measured on the sibling rank sweep: probes that failed all three back-to-back
        // attempts each succeeded first time when run alone a minute later. Retrying
        // instantly spends three probes inside one rate-limit window and reports a
        // measurable site as unmeasurable.
        if ($attempt > 1) {
            $wait = 5 * ($attempt - 1);
            $say('backing off ' . $wait . 's before retry');
            sleep($wait);
        }
        $tasks = array_map(fn($d) => [
            'keyword' => 'site:' . $d, 'location_code' => 2840, 'language_code' => 'en', 'depth' => 100,
        ], $pending);
        $res   = infra_kw_dfs_post_many($p['cfg'], 'serp/google/organic/live/advanced', $tasks, max(1, min(8, $concurrency)));
        $retry = [];
        foreach ($pending as $i => $d) {
            // ⚠ A SUCCESSFUL CALL WITH NO COUNT IS NOT A ZERO. DataForSEO returns ok
            // with se_results_count absent and zero items often enough to matter — one
            // in three on a repeat test — and `?? 0` turned that into "this site has
            // nothing indexed", which is the not-measured-is-not-zero rule being broken
            // through a side door. Absent means retry, then unmeasured.
            $count = $res[$i]['result'][0]['se_results_count'] ?? null;
            if (!empty($res[$i]['ok']) && $count !== null) $result[$d] = (int) $count;
            else $retry[] = $d;
        }
        $say('attempt ' . $attempt . ': ' . (count($pending) - count($retry)) . ' measured, ' . count($retry) . ' to retry');
        $pending = $retry;
    }
    foreach ($pending as $d) $result[$d] = -1;        // still unknown — NOT zero

    $sweepAt = gmdate('c');
    $db  = index_audit_db();
    $ins = $db->prepare('INSERT OR REPLACE INTO index_sweep (sweep_at, domain, indexed, built, age_days, note)
                         VALUES (:s, :d, :i, :b, :a, :n)');
    $totIdx = $totBuilt = $measured = 0;
    foreach ($rows as $r) {
        $d = $r['domain'];
        $n = $result[$d] ?? -1;
        $b = $built[infra_pipeline_slug($d)] ?? 0;
        $ins->execute([':s' => $sweepAt, ':d' => $d, ':i' => $n, ':b' => $b,
                       ':a' => index_audit_age((string) $r['go_live_at']),
                       ':n' => $n < 0 ? 'probe failed after ' . INDEX_PROBE_ATTEMPTS . ' attempts' : '']);
        $totBuilt += $b;
        if ($n >= 0) { $totIdx += $n; $measured++; }
    }
    return ['sweep_at' => $sweepAt, 'measured' => $measured,
            'unmeasured' => count($rows) - $measured, 'indexed' => $totIdx, 'built' => $totBuilt];
}

/**
 * When each domain was FIRST SEEN with anything in the index, from the stored history.
 *
 * ⚠ THE HONEST CAVEAT, AND IT IS THE WHOLE DESIGN OF THIS FUNCTION. We only know what
 * we have looked at. A site already indexed in our earliest sweep was indexed at some
 * unknown point BEFORE that, so "first seen" is an upper bound, not a date — reporting
 * it as the day it got indexed would silently invent a fact, and would do it for every
 * domain that predates the first sweep. Those are returned with bound=true so the panel
 * can show "≤ 34d" rather than "34d", and the two can never be read as the same claim.
 *
 * @return array<string,array{at:string,bound:bool}>
 */
function index_audit_first_indexed(): array
{
    $db = index_audit_db();
    $baseline = (string) ($db->query('SELECT MIN(sweep_at) FROM index_sweep')->fetchColumn() ?: '');
    $out = [];
    $rows = $db->query('SELECT domain, MIN(sweep_at) first_at FROM index_sweep
                        WHERE indexed > 0 GROUP BY domain')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $out[$r['domain']] = ['at' => (string) $r['first_at'],
                              'bound' => (string) $r['first_at'] === $baseline];
    }
    return $out;
}

/** The two most recent sweep timestamps, newest first. */
function index_audit_recent_sweeps(int $n = 2): array
{
    return array_column(index_audit_db()
        ->query('SELECT DISTINCT sweep_at FROM index_sweep ORDER BY sweep_at DESC LIMIT ' . max(1, $n))
        ->fetchAll(PDO::FETCH_ASSOC), 'sweep_at');
}

/**
 * The latest sweep, with each domain's movement since the one before it.
 *
 * Only measurement is compared to measurement: a -1 on either side is not a change,
 * it is an absence of one, and showing it as a drop would be inventing a fact.
 *
 * @return array{at:string, prev_at:string, measured:int, unmeasured:int, indexed:int,
 *               built:int, net:int, up:int, down:int, rows:array}
 */
function index_audit_latest(): array
{
    $empty = ['at' => '', 'prev_at' => '', 'measured' => 0, 'unmeasured' => 0, 'indexed' => 0,
              'built' => 0, 'net' => 0, 'up' => 0, 'down' => 0, 'rows' => []];
    $sweeps = index_audit_recent_sweeps(2);
    if (!$sweeps) return $empty;
    [$at, $prevAt] = [$sweeps[0], $sweeps[1] ?? ''];

    $db = index_audit_db();
    $st = $db->prepare('SELECT domain, indexed, built, age_days, note FROM index_sweep WHERE sweep_at = ?');
    $st->execute([$at]);
    $cur = $st->fetchAll(PDO::FETCH_ASSOC);

    $prev = [];
    if ($prevAt !== '') {
        $st->execute([$prevAt]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $prev[$p['domain']] = (int) $p['indexed'];
    }

    // Go-live dates and first-seen-indexed, so each row can answer "how long did it
    // take?" — the question the age column on its own cannot.
    $firstIdx = index_audit_first_indexed();
    $liveAt = [];
    foreach (infra_state_db()->query("SELECT domain, go_live_at FROM domains")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $liveAt[$r['domain']] = substr((string) $r['go_live_at'], 0, 10);
    }

    $out = $empty;
    $out['at'] = $at; $out['prev_at'] = $prevAt;
    foreach ($cur as $r) {
        $n = (int) $r['indexed'];
        $b = (int) $r['built'];
        $out['built'] += $b;
        $delta = null;
        if ($n >= 0) {
            $out['measured']++; $out['indexed'] += $n;
            if (isset($prev[$r['domain']]) && $prev[$r['domain']] >= 0) {
                $delta = $n - $prev[$r['domain']];
                $out['net'] += $delta;
                if ($delta > 0) $out['up']++; elseif ($delta < 0) $out['down']++;
            }
        } else {
            $out['unmeasured']++;
        }
        // Days from go-live to the first sweep that saw anything indexed. Null while a
        // site has never been seen indexed; `bound` when it was already indexed at our
        // earliest sweep, so the number is a ceiling rather than the actual answer.
        $live  = $liveAt[$r['domain']] ?? '';
        $first = $firstIdx[$r['domain']] ?? null;
        $ttiDays = null;
        if ($live !== '' && $first) {
            $ttiDays = max(0, (int) floor((strtotime($first['at']) - strtotime($live)) / 86400));
        }
        $out['rows'][] = [
            'domain' => $r['domain'], 'indexed' => $n, 'built' => $b,
            'age_days' => (int) $r['age_days'], 'delta' => $delta,
            'prev' => $prev[$r['domain']] ?? null,
            'too_new' => ((int) $r['age_days']) >= 0 && ((int) $r['age_days']) < index_too_new_days(),
            'note' => (string) $r['note'],
            'went_live'     => $live,
            'first_indexed' => $first['at'] ?? '',
            'tti_days'      => $ttiDays,
            'tti_is_bound'  => (bool) ($first['bound'] ?? false),
        ];
    }
    usort($out['rows'], fn($a, $b) => [$a['delta'] ?? 0, -$a['indexed']] <=> [$b['delta'] ?? 0, -$b['indexed']]);
    return $out;
}

/**
 * The per-domain table body, fetched on demand when the <details> is opened rather
 * than shipped on every page load. Same markup and sort the panel used to render inline.
 */
function index_audit_render_rows(array $rows): string
{
    usort($rows, fn($a, $b) => $b['indexed'] <=> $a['indexed']);
    ob_start(); ?>
        <?php foreach ($rows as $r):
            $pct = ($r['indexed'] >= 0 && $r['built'] > 0) ? round($r['indexed'] / $r['built'] * 100) . '%' : '—';
        ?>
            <tr>
                <td><?= h($r['domain']) ?></td>
                <td>
                    <?php if ($r['indexed'] < 0): ?>
                        <span class="fa-warn" title="<?= h($r['note']) ?>">not measured</span>
                    <?php else: ?><?= (int) $r['indexed'] ?><?php endif; ?>
                </td>
                <td><?= (int) $r['built'] ?></td>
                <td><?= $pct ?></td>
                <td>
                    <?php if ($r['delta'] === null): ?><span style="color:#cbd5e1">—</span>
                    <?php elseif ($r['delta'] > 0): ?><span style="color:#16a34a">+<?= (int) $r['delta'] ?></span>
                    <?php elseif ($r['delta'] < 0): ?><span style="color:#dc2626"><?= (int) $r['delta'] ?></span>
                    <?php else: ?><span style="color:#cbd5e1">0</span><?php endif; ?>
                </td>
                <td class="fa-mute">
                    <?= $r['went_live'] !== '' ? h(gmdate('j M', strtotime($r['went_live']))) : '—' ?>
                </td>
                <td class="fa-mute">
                    <?= $r['first_indexed'] !== '' ? h(gmdate('j M', strtotime($r['first_indexed']))) : '—' ?>
                </td>
                <td>
                    <?php if ($r['tti_days'] === null): ?>
                        <span class="fa-dim" title="Not seen in the index yet">
                            <?= $r['age_days'] >= 0 ? 'waiting ' . (int) $r['age_days'] . 'd' : '—' ?></span>
                    <?php elseif ($r['tti_is_bound']): ?>
                        <span class="fa-dim"
                              title="Already indexed when we first looked, so this is an upper bound — it may have been indexed sooner">
                            &le; <?= (int) $r['tti_days'] ?>d</span>
                    <?php else: ?>
                        <strong><?= (int) $r['tti_days'] ?>d</strong>
                    <?php endif; ?>
                </td>
                <td class="<?= $r['too_new'] ? 'fa-dim' : '' ?>">
                    <?= $r['age_days'] >= 0 ? (int) $r['age_days'] . 'd' : '?' ?><?= $r['too_new'] ? ' · too new' : '' ?>
                </td>
            </tr>
        <?php endforeach;
    return ob_get_clean();
}
