<?php
/**
 * infra/lib/domain_lists.php — "Domain Check" tab: named, stored lists of domains
 * for batch liveness checks.
 *
 * Pure storage (domain_lists + domain_list_items in fleet.db) plus the check loop.
 * The actual up/down answer is never re-implemented here — it's lib/uptime.php's
 * infra_site_check_run(), the same function the Servers tab and the go-live
 * pipeline already use, so a domain doesn't need to be anywhere in our own fleet
 * records for this to work.
 */
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/uptime.php';
require_once __DIR__ . '/store.php';   // infra_valid_domain()

/** Parse a pasted domain blob the same way Bulk's provisioning textarea does — one
 *  shared convention for "a human pasted some domains" across this console. */
function infra_domain_list_parse(string $raw): array
{
    $pieces  = preg_split('/[\s,]+/', trim($raw)) ?: [];
    $cleaned = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $pieces)))));
    return array_values(array_filter($cleaned, 'infra_valid_domain'));
}

/** Save a new list. Returns its id. */
function infra_domain_list_create(string $name, array $domains): int
{
    $db   = infra_state_db();
    $name = $name !== '' ? $name : ('Untitled list — ' . infra_now());
    $db->prepare('INSERT INTO domain_lists (name, created_at) VALUES (?, ?)')->execute([$name, infra_now()]);
    $id  = (int) $db->lastInsertId();
    $ins = $db->prepare('INSERT OR IGNORE INTO domain_list_items (list_id, domain) VALUES (?, ?)');
    foreach ($domains as $d) $ins->execute([$id, $d]);
    return $id;
}

/** Every saved list, newest first, with its domain count. */
function infra_domain_lists_all(): array
{
    return infra_state_db()->query(
        'SELECT l.id, l.name, l.created_at, COUNT(i.domain) AS domain_count
         FROM domain_lists l LEFT JOIN domain_list_items i ON i.list_id = l.id
         GROUP BY l.id ORDER BY l.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** One list's own row plus its member domains (alphabetical), or null if it doesn't exist. */
function infra_domain_list_get(int $id): ?array
{
    $db   = infra_state_db();
    $stmt = $db->prepare('SELECT id, name, created_at FROM domain_lists WHERE id = ?');
    $stmt->execute([$id]);
    $list = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$list) return null;
    $stmt = $db->prepare('SELECT domain FROM domain_list_items WHERE list_id = ? ORDER BY domain ASC');
    $stmt->execute([$id]);
    $list['domains'] = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return $list;
}

function infra_domain_list_delete(int $id): void
{
    $db = infra_state_db();
    $db->prepare('DELETE FROM domain_list_items WHERE list_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM domain_lists WHERE id = ?')->execute([$id]);
}

/** Rename a list. No-op (returns false) if it doesn't exist or the name is blank. */
function infra_domain_list_rename(int $id, string $name): bool
{
    $name = trim($name);
    if ($name === '') return false;
    $stmt = infra_state_db()->prepare('UPDATE domain_lists SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Check every domain in a list right now (forces a fresh probe, bypassing cache)
 * and return a simple tally. The per-domain detail isn't returned here — the view
 * reads it back from cache afterward via infra_site_check_cached(), the same
 * "render from the last stored answer, never hit the network just to display"
 * rule every other tab in this console follows.
 */
function infra_domain_list_check(int $id): array
{
    $list = infra_domain_list_get($id);
    if (!$list) return ['checked' => 0, 'up' => 0, 'down' => 0];
    $up = 0;
    foreach ($list['domains'] as $d) {
        $r = infra_site_check_run($d);
        if (!empty($r['up'])) $up++;
    }
    $n = count($list['domains']);
    return ['checked' => $n, 'up' => $up, 'down' => $n - $up];
}
