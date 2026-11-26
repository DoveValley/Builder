<?php
/**
 * admin/infra/lib/pluscities_db.php — self-contained SQLite store for the
 * PlusCities content-engine rewrite (docs/PLUSCITIES_FEATURE_SPEC.md).
 *
 * Deliberately its OWN file, never admin/infra/state/fleet.db — per
 * docs/lpc-vs-site-factory-boundary.md §2, LPC/PlusCities sharing the fleet's
 * own database was flagged as the one real cross-business coupling worth
 * fixing. This file starts clean instead of repeating that mistake.
 *
 * Holds the higher-volume, queried-by-key, frequently-written relational
 * entities from spec Section 1 (fact_cards, datasets, pages, blocks,
 * revisions, events). Agencies/clients/users and the niche library stay as
 * JSON-per-entity files (see includes/pluscities_niche_library.php) — low
 * volume, human-authored, no real query pattern beyond "load by id".
 *
 * Same shape as admin/infra/lib/state.php::infra_state_db() on purpose: lazy
 * static PDO, WAL mode, a busy timeout so a concurrent write never throws
 * "database is locked" under normal load.
 */

function pluscities_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;

    $dir = dirname(PLUSCITIES_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $db = new PDO('sqlite:' . PLUSCITIES_DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');

    $db->exec('CREATE TABLE IF NOT EXISTS fact_cards (
        id          TEXT PRIMARY KEY,
        scope       TEXT NOT NULL,   -- city | service | niche
        scope_key   TEXT NOT NULL,
        topic       TEXT NOT NULL,
        claim       TEXT DEFAULT "",
        value_json  TEXT DEFAULT "",
        source_url  TEXT DEFAULT "",
        source_title TEXT DEFAULT "",
        quote       TEXT DEFAULT "",
        checked_at  TEXT DEFAULT "",
        expires_at  TEXT DEFAULT "",
        status      TEXT DEFAULT "active"   -- active | stale | rejected
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_fact_cards_lookup
        ON fact_cards(scope, scope_key, topic, status)');

    // One row per (city, provider) — flat cached dataset values, no citation:
    // the provider itself is the source. See spec Section 3.
    $db->exec('CREATE TABLE IF NOT EXISTS datasets (
        city_id     TEXT NOT NULL,
        provider    TEXT NOT NULL,
        fetched_at  TEXT DEFAULT "",
        values_json TEXT DEFAULT "",
        PRIMARY KEY (city_id, provider)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS pages (
        page_id         TEXT PRIMARY KEY,
        client_id       TEXT NOT NULL,
        city_id         TEXT NOT NULL,
        service_key     TEXT NOT NULL,
        slug            TEXT DEFAULT "",
        status          TEXT DEFAULT "draft",   -- draft | approved | live | unpublished — highest stage ever reached
        approval_stage  TEXT DEFAULT "none",    -- none | pending_platform_review | pending_agency_review | approved | rejected
        live_revision_id INTEGER DEFAULT NULL,
        draft_hash      TEXT DEFAULT "",
        validation_json TEXT DEFAULT "",
        index_status    TEXT DEFAULT ""
    )');

    // Additive migration, same convention as blocks.client_grounded below —
    // per-page SEO title/meta description overrides, added 2026-11-26
    // (panel-coverage walkthrough Session 4). Blank means "use the
    // auto-generated template" (admin/pluscities_sync.php) — these were
    // 100% hardcoded with zero per-page override before this, so every
    // client's every page shared the exact same templated meta description
    // sentence shape.
    $pagesCols = $db->query('PRAGMA table_info(pages)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('seo_title', $pagesCols, true)) {
        $db->exec('ALTER TABLE pages ADD COLUMN seo_title TEXT DEFAULT ""');
    }
    if (!in_array('seo_description', $pagesCols, true)) {
        $db->exec('ALTER TABLE pages ADD COLUMN seo_description TEXT DEFAULT ""');
    }

    $db->exec('CREATE TABLE IF NOT EXISTS blocks (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id        TEXT NOT NULL,
        block_type     TEXT NOT NULL,
        position       INTEGER DEFAULT 0,
        pool           TEXT DEFAULT "",          -- spine | city | service
        classification TEXT DEFAULT "",          -- editable | automatic | client-supplied
        content_json   TEXT DEFAULT "",
        fact_ids       TEXT DEFAULT "[]",        -- JSON array
        dataset_refs   TEXT DEFAULT "[]",        -- JSON array
        locked         INTEGER DEFAULT 0,
        origin         TEXT DEFAULT "",          -- generated | edited | data
        client_grounded INTEGER DEFAULT 0        -- 1 if written using client-profile facts (spec Section 4) —
                                                  -- distinguishes "legitimately has zero fact_ids" (Hero/Overview)
                                                  -- from "actually ungrounded" for the Unsupported Claim check
    )');
    // Additive migration — same convention as admin/infra/lib/state.php's
    // infra_state_db(): a column added after a table already exists gets
    // backfilled onto it rather than requiring a destructive recreate.
    $blocksCols = $db->query('PRAGMA table_info(blocks)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('client_grounded', $blocksCols, true)) {
        $db->exec('ALTER TABLE blocks ADD COLUMN client_grounded INTEGER DEFAULT 0');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_blocks_page ON blocks(page_id)');

    // Immutable — a revision row is never UPDATEd once written, only inserted.
    $db->exec('CREATE TABLE IF NOT EXISTS revisions (
        page_id       TEXT NOT NULL,
        number        INTEGER NOT NULL,
        snapshot_json TEXT DEFAULT "",
        hash          TEXT DEFAULT "",
        author        TEXT DEFAULT "",
        created_at    TEXT DEFAULT "",
        PRIMARY KEY (page_id, number)
    )');

    // The audit log — every override, publish, rollback. See spec Section 7/8.
    $db->exec('CREATE TABLE IF NOT EXISTS events (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        actor     TEXT DEFAULT "",
        action    TEXT DEFAULT "",
        target    TEXT DEFAULT "",
        detail    TEXT DEFAULT "",
        timestamp TEXT DEFAULT ""
    )');

    // One override mechanism (spec Section 7/9, design rule #9) — keyed to the
    // exact draft_hash it was granted against, so any further edit silently
    // stops it applying rather than needing to be explicitly revoked. Agency
    // Admin only, reason required, enforced at the call site
    // (pluscities_override_validator_check()), not here.
    $db->exec('CREATE TABLE IF NOT EXISTS overrides (
        page_id    TEXT NOT NULL,
        check_name TEXT NOT NULL,
        draft_hash TEXT NOT NULL,
        reason     TEXT DEFAULT "",
        actor      TEXT DEFAULT "",
        created_at TEXT DEFAULT "",
        PRIMARY KEY (page_id, check_name, draft_hash)
    )');

    return $db;
}

/** Appends one row to the audit log. Every override/publish/rollback calls this. */
function pluscities_log_event(string $actor, string $action, string $target, string $detail = ''): void
{
    pluscities_db()
        ->prepare('INSERT INTO events (actor, action, target, detail, timestamp) VALUES (?, ?, ?, ?, ?)')
        ->execute([$actor, $action, $target, $detail, gmdate('c')]);
}
