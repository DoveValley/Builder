<?php
/**
 * lib/cities.php — the Cities/Niche tab's data layer.
 *
 * Three tables in fleet.db, and the split between them is the point:
 *
 *   cities      reference data, seeded from data/us_cities.csv (Census 2024
 *               population, rank 1 = largest). Read-only; re-seeding refreshes
 *               the facts without touching anything you chose.
 *   niches      the tab row across the top. Add/rename as the network grows.
 *   city_niche  what we know and what we plan, per city per niche: the Ahrefs
 *               figure, the score, the area code, the phone, the domain.
 *
 * A row here does NOT mean the city is selected — `selected` does. Ahrefs is
 * INFORMATIONAL and comes first in the workflow: you record it while browsing,
 * to decide whether the city is worth picking at all. Selecting is a later,
 * separate act. Nothing here is a receipt: a city can be re-scored, a domain
 * re-pointed, a selection dropped. Ownership receipts stay on the domains table
 * where they are sticky.
 */

const INFRA_CITY_COLS  = ['id', 'rank', 'city', 'state', 'ss', 'population', 'lat', 'lng', 'area_codes', 'ac_source'];
const INFRA_CN_COLS    = ['niche', 'city_id', 'selected', 'ahrefs', 'score', 'score_src', 'area_code', 'phone', 'domain', 'note', 'created_at', 'updated_at'];
const INFRA_NICHE_SEED = ['appliance', 'mold', 'pest', 'restoration'];

/** Create the three tables (idempotent) and seed niches on first run. */
function infra_cities_init(): PDO
{
    static $done = false;
    $db = infra_state_db();
    if ($done) return $db;

    $db->exec('CREATE TABLE IF NOT EXISTS cities (
        id         TEXT PRIMARY KEY,
        rank       INTEGER,
        city       TEXT DEFAULT "",
        state      TEXT DEFAULT "",
        ss         TEXT DEFAULT "",
        population INTEGER DEFAULT 0,
        lat        TEXT DEFAULT "",
        lng        TEXT DEFAULT "",
        area_codes TEXT DEFAULT "",
        ac_source  TEXT DEFAULT ""
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS cities_rank ON cities (rank)');

    $db->exec('CREATE TABLE IF NOT EXISTS niches (
        slug  TEXT PRIMARY KEY,
        label TEXT DEFAULT "",
        sort  INTEGER DEFAULT 0
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS city_niche (
        niche      TEXT NOT NULL,
        city_id    TEXT NOT NULL,
        selected   TEXT DEFAULT "",
        ahrefs     TEXT DEFAULT "",
        score      TEXT DEFAULT "",
        score_src  TEXT DEFAULT "",
        area_code  TEXT DEFAULT "",
        phone      TEXT DEFAULT "",
        domain     TEXT DEFAULT "",
        note       TEXT DEFAULT "",
        created_at TEXT DEFAULT "",
        updated_at TEXT DEFAULT "",
        PRIMARY KEY (niche, city_id)
    )');
    // Additive migration, same idea as the domains table: a column added later
    // lands on an existing install without a bespoke migration step.
    $have = $db->query('PRAGMA table_info(city_niche)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (INFRA_CN_COLS as $col) {
        if (!in_array($col, $have, true)) {
            $db->exec('ALTER TABLE city_niche ADD COLUMN ' . $col . ' TEXT DEFAULT ""');
            // Rows that predate `selected` were only ever created by selecting.
            if ($col === 'selected') $db->exec('UPDATE city_niche SET selected = "yes"');
        }
    }
    // One domain serves one city. Enforced by the database, not just by the form —
    // a partial unique index so the many un-assigned rows do not collide with
    // each other on "".
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS city_niche_domain
               ON city_niche (domain) WHERE domain <> ""');

    if (!$db->query('SELECT COUNT(*) FROM niches')->fetchColumn()) {
        $i = 0;
        foreach (INFRA_NICHE_SEED as $slug) {
            infra_niche_save($slug, ucfirst($slug), $i += 10);
        }
    }
    $done = true;
    return $db;
}

/* ------------------------------------------------------------------ niches */

function infra_niche_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/** @return array slug => ['slug'=>,'label'=>,'sort'=>] in display order */
function infra_niches(): array
{
    $rows = infra_cities_init()->query('SELECT * FROM niches ORDER BY sort, slug')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['slug']] = $r;
    return $out;
}

function infra_niche_save(string $slug, string $label = '', ?int $sort = null): string
{
    $slug = infra_niche_slug($slug);
    if ($slug === '') return '';
    $db = infra_state_db();
    if ($sort === null) {
        $sort = (int) $db->query('SELECT COALESCE(MAX(sort),0) + 10 FROM niches')->fetchColumn();
    }
    $st = $db->prepare('INSERT INTO niches (slug,label,sort) VALUES (?,?,?)
                        ON CONFLICT(slug) DO UPDATE SET label=excluded.label, sort=excluded.sort');
    $st->execute([$slug, trim($label) !== '' ? trim($label) : ucfirst($slug), $sort]);
    return $slug;
}

/** Deleting a niche drops its selections; the cities themselves are untouched. */
function infra_niche_delete(string $slug): int
{
    $db = infra_cities_init();
    $n = (int) $db->query('SELECT COUNT(*) FROM city_niche WHERE niche = ' . $db->quote($slug))->fetchColumn();
    $db->prepare('DELETE FROM city_niche WHERE niche = ?')->execute([$slug]);
    $db->prepare('DELETE FROM niches WHERE slug = ?')->execute([$slug]);
    return $n;
}

/* ------------------------------------------------------------------ cities */

function infra_cities_csv_path(): string
{
    return dirname(__DIR__) . '/data/us_cities.csv';
}

/**
 * Load data/us_cities.csv into the cities table.
 *
 * Rows are keyed by slug (`plano-tx`), NOT by rank — rank is a fact that moves
 * with every Census release, and a selection must not follow it onto a different
 * city. Same name twice in one state gets the rank appended.
 *
 * @return array{loaded:int,updated:int,error:string}
 */
function infra_cities_seed(): array
{
    $db = infra_cities_init();
    $path = infra_cities_csv_path();
    if (!is_readable($path)) {
        return ['loaded' => 0, 'updated' => 0, 'error' => 'Not readable: ' . $path];
    }
    $fh = fopen($path, 'r');
    if (!$fh) return ['loaded' => 0, 'updated' => 0, 'error' => 'Could not open ' . $path];

    $head = fgetcsv($fh);
    if (!$head || $head[0] !== 'rank') {
        fclose($fh);
        return ['loaded' => 0, 'updated' => 0, 'error' => 'Unexpected header — rebuild with data/build_cities.py'];
    }
    $before = (int) $db->query('SELECT COUNT(*) FROM cities')->fetchColumn();

    $st = $db->prepare('REPLACE INTO cities (id,rank,city,state,ss,population,lat,lng,area_codes,ac_source)
                        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $seen = [];
    $db->beginTransaction();
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < 9) continue;
        [$rank, $city, $state, $ss, $pop, $lat, $lng, $codes, $src] = $r;
        $id = infra_niche_slug($city) . '-' . strtolower($ss ?: 'us');
        if (isset($seen[$id])) $id .= '-' . (int) $rank;
        $seen[$id] = true;
        $st->execute([$id, (int) $rank, $city, $state, $ss, (int) $pop, $lat, $lng, $codes, $src]);
    }
    $db->commit();
    fclose($fh);

    $after = (int) $db->query('SELECT COUNT(*) FROM cities')->fetchColumn();
    return ['loaded' => $after, 'updated' => count($seen) - ($after - $before), 'error' => ''];
}

function infra_cities_count(): int
{
    return (int) infra_cities_init()->query('SELECT COUNT(*) FROM cities')->fetchColumn();
}

function infra_city_get(string $id): ?array
{
    $st = infra_cities_init()->prepare('SELECT * FROM cities WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Area codes for a city as a list, best suggestion first. */
function infra_city_area_codes(array $city): array
{
    $s = trim($city['area_codes'] ?? '');
    return $s === '' ? [] : preg_split('/\s+/', $s);
}

/**
 * Browse the city pool.
 *
 * @param array $f  q, state, min_pop, max_rank, limit, offset
 * @return array{rows:array,total:int}
 */
function infra_cities_browse(array $f = []): array
{
    $db = infra_cities_init();
    $w = [];
    $p = [];
    if (trim($f['q'] ?? '') !== '') {
        $w[] = '(LOWER(city) LIKE ? OR LOWER(state) LIKE ? OR ss = ?)';
        $q = '%' . strtolower(trim($f['q'])) . '%';
        $p[] = $q; $p[] = $q; $p[] = strtoupper(trim($f['q']));
    }
    if (trim($f['state'] ?? '') !== '') { $w[] = 'ss = ?';          $p[] = strtoupper(trim($f['state'])); }
    if ((int) ($f['min_pop']  ?? 0))    { $w[] = 'population >= ?'; $p[] = (int) $f['min_pop']; }
    if ((int) ($f['max_rank'] ?? 0))    { $w[] = 'rank <= ?';       $p[] = (int) $f['max_rank']; }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

    $st = $db->prepare('SELECT COUNT(*) FROM cities' . $where);
    $st->execute($p);
    $total = (int) $st->fetchColumn();

    $limit  = max(1, min(500, (int) ($f['limit'] ?? 100)));
    $offset = max(0, (int) ($f['offset'] ?? 0));
    $st = $db->prepare('SELECT * FROM cities' . $where . ' ORDER BY rank LIMIT ' . $limit . ' OFFSET ' . $offset);
    $st->execute($p);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
}

function infra_states_list(): array
{
    return infra_cities_init()
        ->query('SELECT DISTINCT ss FROM cities WHERE ss <> "" ORDER BY ss')
        ->fetchAll(PDO::FETCH_COLUMN, 0);
}

/* ------------------------------------------------------- selections (plan) */

/** @return array city_id => row, for one niche */
function infra_cn_all(string $niche): array
{
    $st = infra_cities_init()->prepare('SELECT * FROM city_niche WHERE niche = ?');
    $st->execute([$niche]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['city_id']] = $r;
    return $out;
}

/** Selected cities for a niche, joined to their city facts, ranked. */
function infra_cn_selected(string $niche): array
{
    $st = infra_cities_init()->prepare(
        'SELECT c.*, n.ahrefs, n.score, n.score_src, n.area_code, n.phone, n.domain, n.note, n.updated_at
           FROM city_niche n JOIN cities c ON c.id = n.city_id
          WHERE n.niche = ? AND n.selected = "yes" ORDER BY c.rank');
    $st->execute([$niche]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Record what we know about a city without picking it. This is the informational
 * step that comes BEFORE selecting — a number jotted against a city you are still
 * thinking about must not add it to the niche.
 */
function infra_cn_note_metric(string $niche, string $cityId, array $in): string
{
    $db = infra_cities_init();
    if (!infra_city_get($cityId)) return 'unknown city';
    $db->prepare('INSERT OR IGNORE INTO city_niche (niche,city_id,selected,created_at,updated_at) VALUES (?,?,"",?,?)')
       ->execute([$niche, $cityId, infra_now(), infra_now()]);
    return infra_cn_update($niche, $cityId, $in);
}

/** Every domain already spoken for, across all niches: domain => "niche/city". */
function infra_cn_domains_taken(): array
{
    $rows = infra_cities_init()
        ->query('SELECT n.domain, n.niche, c.city, c.ss FROM city_niche n
                   LEFT JOIN cities c ON c.id = n.city_id
                  WHERE n.domain <> ""')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[strtolower($r['domain'])] = $r['niche'] . ' / ' . ($r['city'] ?: '?') . ', ' . ($r['ss'] ?: '?');
    }
    return $out;
}

/** Add a city to a niche. Anything already recorded against it is kept. */
function infra_cn_select(string $niche, string $cityId): bool
{
    $db = infra_cities_init();
    if (!infra_city_get($cityId)) return false;
    $st = $db->prepare('SELECT selected FROM city_niche WHERE niche = ? AND city_id = ?');
    $st->execute([$niche, $cityId]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if ($cur && $cur['selected'] === 'yes') return false;

    if ($cur) {
        $db->prepare('UPDATE city_niche SET selected = "yes", updated_at = ? WHERE niche = ? AND city_id = ?')
           ->execute([infra_now(), $niche, $cityId]);
    } else {
        $db->prepare('INSERT INTO city_niche (niche,city_id,selected,created_at,updated_at) VALUES (?,?,"yes",?,?)')
           ->execute([$niche, $cityId, infra_now(), infra_now()]);
    }
    return true;
}

/**
 * Drop a city from the plan but keep what we learned about it. The domain link
 * is released — a domain must not stay bound to a city nobody is building — and
 * the Ahrefs figure stays, because it is research, not a decision.
 */
function infra_cn_unselect(string $niche, string $cityId): void
{
    infra_cities_init()->prepare(
        'UPDATE city_niche SET selected = "", domain = "", updated_at = ? WHERE niche = ? AND city_id = ?')
        ->execute([infra_now(), $niche, $cityId]);
}

/**
 * Update one selection. Only supplied fields move.
 * @return string '' on success, otherwise why it was refused
 */
function infra_cn_update(string $niche, string $cityId, array $in): string
{
    $db = infra_cities_init();
    $st = $db->prepare('SELECT * FROM city_niche WHERE niche = ? AND city_id = ?');
    $st->execute([$niche, $cityId]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) return 'nothing recorded for this city';

    $set = [];
    $p   = [];
    foreach (['ahrefs', 'score', 'area_code', 'phone', 'domain', 'note'] as $col) {
        if (!array_key_exists($col, $in)) continue;
        $v = trim((string) $in[$col]);

        if ($col === 'score') {
            if ($v !== '') {
                if (!is_numeric($v) || $v < 1 || $v > 10) return 'score must be 1-10';
                $v = (string) (int) round((float) $v);
            }
            // A hand-typed score is marked as such, the same way a hand-set
            // availability verdict is — so a guess never reads as a measurement.
            if ($v !== ($cur['score'] ?? '')) {
                $set[] = 'score_src = ?';
                $p[]   = $v === '' ? '' : 'hand';
            }
        }
        if ($col === 'domain' && $v !== '') {
            $v = strtolower($v);
            if (!infra_state_get_domain($v)) return 'unknown domain: ' . $v;
            $taken = $db->prepare('SELECT niche, city_id FROM city_niche
                                    WHERE domain = ? AND NOT (niche = ? AND city_id = ?)');
            $taken->execute([$v, $niche, $cityId]);
            if ($row = $taken->fetch(PDO::FETCH_ASSOC)) {
                return $v . ' is already on ' . $row['niche'] . ' / ' . $row['city_id'];
            }
        }
        $set[] = $col . ' = ?';
        $p[]   = $v;
    }
    if (!$set) return '';

    $set[] = 'updated_at = ?';
    $p[]   = infra_now();
    $p[]   = $niche;
    $p[]   = $cityId;
    $db->prepare('UPDATE city_niche SET ' . implode(', ', $set) . ' WHERE niche = ? AND city_id = ?')->execute($p);
    return '';
}

/** Counts per niche for the tab strip: selected, and how many have a domain. */
function infra_cn_counts(): array
{
    $rows = infra_cities_init()->query(
        'SELECT niche, COUNT(*) n, SUM(CASE WHEN domain <> "" THEN 1 ELSE 0 END) d
           FROM city_niche WHERE selected = "yes" GROUP BY niche')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['niche']] = ['selected' => (int) $r['n'], 'linked' => (int) $r['d']];
    return $out;
}
