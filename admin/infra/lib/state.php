<?php
/**
 * infra/lib/state.php — self-contained SQLite fleet state (state/fleet.db).
 * Persists provisioned domains + their creds/metadata so the console tracks the
 * fleet (and deploy can reuse FTP creds) instead of re-discovering everything.
 * No external dependency — PHP's built-in pdo_sqlite.
 */

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
    // migration: add go_live_at to pre-existing tables
    $have = $db->query('PRAGMA table_info(domains)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('go_live_at', $have, true)) {
        $db->exec('ALTER TABLE domains ADD COLUMN go_live_at TEXT DEFAULT ""');
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
    'nameservers','ftp_user','ftp_pass','registrar','status','go_live_at','created_at','updated_at'];

/** Insert/merge a domain record (preserves existing fields not supplied). */
function infra_state_upsert_domain(array $in): void
{
    $in['domain'] = strtolower(trim($in['domain'] ?? ''));
    if ($in['domain'] === '') return;

    $now = gmdate('Y-m-d H:i:s');
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
