<?php
/**
 * infra/lib/state.php — self-contained SQLite fleet state (state/fleet.db).
 * Persists provisioned domains + their creds/metadata so the console tracks the
 * fleet (and deploy can reuse FTP creds) instead of re-discovering everything.
 * No external dependency — PHP's built-in pdo_sqlite.
 */


/**
 * The console's operating timezone. Buy dates, schedules and "is it due today"
 * are all decided in US Central, because that is the operator's day — a purchase
 * run at 20:30 in Texas belongs to that evening, not to tomorrow, which is what
 * UTC would have called it.
 *
 * Registrars report their own dates in their own zones; those are recorded as
 * given and not translated.
 */
const INFRA_TZ = 'America/Chicago';

/** Today's date in the operating timezone: 'YYYY-MM-DD'. */
function infra_today(): string
{
    return (new DateTime('now', new DateTimeZone(INFRA_TZ)))->format('Y-m-d');
}

/** Now in the operating timezone: 'YYYY-MM-DD HH:MM:SS'. */
function infra_now(): string
{
    return (new DateTime('now', new DateTimeZone(INFRA_TZ)))->format('Y-m-d H:i:s');
}

/** A date N days from today, in the operating timezone. */
function infra_date_plus(int $days, ?string $from = null): string
{
    $d = new DateTime($from ?: 'now', new DateTimeZone(INFRA_TZ));
    if ($days) $d->modify(($days > 0 ? '+' : '') . $days . ' days');
    return $d->format('Y-m-d');
}

function infra_state_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;

    $dir = dirname(__DIR__) . '/state';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $db = new PDO('sqlite:' . $dir . '/fleet.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS domains (
        domain        TEXT PRIMARY KEY,
        niche         TEXT DEFAULT "",
        server_id     TEXT DEFAULT "",
        cf_account_id TEXT DEFAULT "",
        cf_zone_id    TEXT DEFAULT "",
        nameservers   TEXT DEFAULT "",
        ftp_user      TEXT DEFAULT "",
        ftp_pass      TEXT DEFAULT "",
        registrar     TEXT DEFAULT "",
        status        TEXT DEFAULT "",
        go_live_at    TEXT DEFAULT "",
        created_at    TEXT DEFAULT "",
        updated_at    TEXT DEFAULT ""
    )');
    // Additive migration: any column in INFRA_STATE_COLS missing from an existing
    // table is added. Generalised from the one-off go_live_at migration so the
    // acquisition columns (and anything later) need no bespoke migration step.
    $have = $db->query('PRAGMA table_info(domains)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (INFRA_STATE_COLS as $col) {
        if (!in_array($col, $have, true)) {
            $db->exec('ALTER TABLE domains ADD COLUMN ' . $col . ' TEXT DEFAULT ""');
        }
    }
    // persistent round-robin counters (survive across bulk runs)
    $db->exec('CREATE TABLE IF NOT EXISTS counters (k TEXT PRIMARY KEY, v INTEGER DEFAULT 0)');
    // discovery cache (Plesk/CF API sweeps) — see lib/cache.php
    $db->exec('CREATE TABLE IF NOT EXISTS cache (k TEXT PRIMARY KEY, v TEXT, ts INTEGER)');
    return $db;
}

/** Atomically bump a named counter and return the index to use for THIS pick (0-based). */
function infra_state_counter_next(string $key): int
{
    $db = infra_state_db();
    $db->prepare('INSERT INTO counters (k, v) VALUES (?, 1) ON CONFLICT(k) DO UPDATE SET v = v + 1')->execute([$key]);
    $stmt = $db->prepare('SELECT v FROM counters WHERE k = ?');
    $stmt->execute([$key]);
    return max(0, (int) $stmt->fetchColumn() - 1);
}

const INFRA_STATE_COLS = ['domain','niche','server_id','cf_account_id','cf_zone_id',
    'nameservers','ftp_user','ftp_pass','registrar','status','go_live_at','created_at','updated_at',
    // ── acquisition stage (begin → ready → owned), all TEXT ──
    'ready_to_buy',      // 'yes' | 'no' | ''      col 2 — set by the availability check, manually overridable
    'buy_registrar',     // registrar.json key     col 3 — which registrar will buy it
    'buy_at',            // 'YYYY-MM-DD'           col 4 — the date the system buys it
    'owned',             // 'yes' | ''             col 5 — sticky receipt; never cleared once purchased
    'owned_at',          // 'YYYY-MM-DD HH:MM:SS'  when the purchase completed
    'avail_note',        // why not ready: taken / premium / self-owned / check-failed
    'avail_price',       // registrar-quoted price at check time (NameSilo returns one)
    'avail_checked_at',  // last availability check
    'buy_error',         // last purchase failure (pass two)
    'auto_renew',        // 'yes'|'no'|'unknown' — VERIFIED after purchase, not assumed.
                         // Namecheap cannot set this over its API, so a domain can be
                         // owned and quietly set to lapse; that has to be visible.
    'contact_set',       // which registrant contact set to register with (pass two)
];

/**
 * The niches a domain can belong to. A fixed list rather than free text: it is
 * what the fleet is organised by, and "appliance" / "Appliance" / "appliances"
 * as three separate groups would quietly break every count.
 */
const INFRA_NICHES = ['pest', 'mold', 'appliance', 'restoration', 'other'];

/** Coerce anything to a known niche. Unrecognised input becomes 'other'. */
function infra_niche(string $v): string
{
    $v = strtolower(trim($v));
    return in_array($v, INFRA_NICHES, true) ? $v : ($v === '' ? '' : 'other');
}

/** Lifecycle values, in order. The first four are the acquisition stage. */
const INFRA_STATUSES = ['begin','ready','owned','buy-failed',
    'staged','queued','releasing','awaiting-ns','live','partial','register-failed'];

/**
 * The statuses that mean "no infrastructure exists yet, BY DESIGN".
 *
 * One definition, because every screen that forgets it says something false about
 * the same rows: the Domains view would report 400 loaded names as drift, and the
 * Go-Live queue would offer to release domains nobody has bought. Both had their
 * own copy of this list — one of them incomplete — which is exactly how they came
 * to disagree about the same fleet.
 *
 * 'owned' belongs here too: a bought domain has a receipt and nothing else until
 * it is provisioned, at which point provisioning moves it on to 'staged'.
 */
const INFRA_ACQUISITION_STATUSES = ['begin', 'ready', 'buy-failed', 'owned'];

/**
 * The statuses a human may set by hand on the domain page.
 *
 * The acquisition ones are deliberately absent: 'ready' is earned by an
 * availability check and 'owned' is a receipt written by a purchase. Offering
 * them in a dropdown would let someone type a fact that never happened — and,
 * because the dropdown listed exactly these seven while every real domain sat in
 * one of the other four, no option matched, the browser silently selected the
 * first, and saving an unrelated field reset the domain to 'staged'.
 */
const INFRA_STATUSES_MANUAL = ['staged', 'queued', 'releasing', 'awaiting-ns', 'live', 'partial', 'register-failed'];

/** Is this record still in the acquisition stage (bought or not, never provisioned)? */
function infra_is_acquiring(?array $rec): bool
{
    if (!$rec) return false;
    return in_array($rec['status'] ?: 'begin', INFRA_ACQUISITION_STATUSES, true);
}

/** Insert/merge a domain record (preserves existing fields not supplied). */
function infra_state_upsert_domain(array $in): void
{
    $in['domain'] = strtolower(trim($in['domain'] ?? ''));
    if ($in['domain'] === '') return;

    $now = infra_now();
    $cur = infra_state_get_domain($in['domain']) ?: [];
    $defaults = array_fill_keys(INFRA_STATE_COLS, '');
    $rec = array_merge($defaults, ['created_at' => $now], $cur, $in);
    $rec['updated_at'] = $now;
    if (empty($cur)) $rec['created_at'] = $now;

    $cols = INFRA_STATE_COLS;
    $ph   = implode(',', array_map(fn($c) => ':' . $c, $cols));
    $stmt = infra_state_db()->prepare('REPLACE INTO domains (' . implode(',', $cols) . ") VALUES ($ph)");
    $stmt->execute(array_intersect_key($rec, array_flip($cols)));
}

function infra_state_get_domain(string $domain): ?array
{
    $stmt = infra_state_db()->prepare('SELECT * FROM domains WHERE domain = ?');
    $stmt->execute([strtolower(trim($domain))]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return array domain(lower) => record */
function infra_state_all_domains(): array
{
    $rows = infra_state_db()->query('SELECT * FROM domains ORDER BY domain')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['domain']] = $r;
    return $out;
}

function infra_state_delete_domain(string $domain): void
{
    infra_state_db()->prepare('DELETE FROM domains WHERE domain = ?')->execute([strtolower(trim($domain))]);
}

/**
 * Insert a domain at 'begin' if it isn't tracked yet. Never touches an existing
 * row — loading a list twice must not reset progress on domains already moving.
 * @return bool true if a new row was created
 */
function infra_state_add_new_domain(string $domain, string $niche = ''): bool
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || infra_state_get_domain($domain)) return false;
    infra_state_upsert_domain(['domain' => $domain, 'status' => 'begin', 'niche' => $niche]);
    return true;
}

/**
 * Apply the same field set to many domains (bulk edit: registrar, buy date…).
 * Only domains already tracked are touched. @return int rows changed
 */
function infra_state_bulk_set(array $domains, array $fields): int
{
    unset($fields['domain']);
    if (!$fields) return 0;
    $n = 0;
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if ($d === '' || !infra_state_get_domain($d)) continue;
        infra_state_upsert_domain(['domain' => $d] + $fields);
        $n++;
    }
    return $n;
}

/** @return array status => count, over every tracked domain */
function infra_state_status_counts(): array
{
    $out = [];
    foreach (infra_state_db()->query('SELECT status, COUNT(*) c FROM domains GROUP BY status') as $r) {
        $out[$r['status'] ?: 'begin'] = (int) $r['c'];
    }
    return $out;
}

/**
 * Domains in the acquisition stage, optionally filtered by status.
 * @return array domain => record
 */
function infra_state_acquisition(array $statuses = ['begin', 'ready', 'buy-failed']): array
{
    $out = [];
    foreach (infra_state_all_domains() as $dom => $r) {
        if (in_array($r['status'] ?: 'begin', $statuses, true)) $out[$dom] = $r;
    }
    return $out;
}

/**
 * Spread a set of domains across daily buy batches — same shape as the go-live
 * scheduler, because buying 400 domains inside one minute is its own footprint.
 * @return int number scheduled
 */
function infra_state_schedule_buys(array $domains, int $perDay, string $startDate): int
{
    $perDay = max(1, $perDay);
    $start  = $startDate ?: infra_today();
    sort($domains);
    $i = 0;
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if ($d === '' || !infra_state_get_domain($d)) continue;
        // Calendar days in the operating timezone, not 86400-second hops — so a
        // DST change cannot slide a batch onto the wrong date.
        infra_state_upsert_domain([
            'domain' => $d,
            'buy_at' => infra_date_plus(intdiv($i, $perDay), $start),
        ]);
        $i++;
    }
    return $i;
}
