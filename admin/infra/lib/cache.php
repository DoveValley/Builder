<?php
/**
 * infra/lib/cache.php — TTL cache for expensive external discovery (Plesk site
 * lists, Cloudflare zone lists) so page loads don't re-sweep every API every time.
 * Backed by the `cache` table in the self-contained SQLite fleet.db.
 *
 * "Fresh" flag: set $GLOBALS['__infra_fresh'] = true (e.g. on ?refresh=1 or in cron)
 * to bypass reads and force a live re-fetch + repopulate for this request.
 */
require_once __DIR__ . '/state.php';

function infra_cache_fresh(): bool { return !empty($GLOBALS['__infra_fresh']); }
function infra_cache_force(bool $on = true): void { $GLOBALS['__infra_fresh'] = $on; }

/** Return cached payload if present and younger than $ttl seconds; else null. */
function infra_cache_get(string $key, int $ttl): ?array
{
    if (infra_cache_fresh()) return null;
    $stmt = infra_state_db()->prepare('SELECT v, ts FROM cache WHERE k = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ((time() - (int) $row['ts']) > $ttl) return null;
    $val = json_decode((string) $row['v'], true);
    return is_array($val) ? $val : null;
}

function infra_cache_put(string $key, array $val): void
{
    infra_state_db()
        ->prepare('REPLACE INTO cache (k, v, ts) VALUES (?, ?, ?)')
        ->execute([$key, json_encode($val), time()]);
}

/** Invalidate cache entries whose key starts with $prefix (''=all). */
function infra_cache_forget(string $prefix = ''): void
{
    if ($prefix === '') { infra_state_db()->exec('DELETE FROM cache'); return; }
    infra_state_db()->prepare('DELETE FROM cache WHERE k LIKE ?')->execute([$prefix . '%']);
}

function infra_cache_flush(): void { infra_cache_forget(''); }
