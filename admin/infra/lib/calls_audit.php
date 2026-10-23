<?php
/**
 * infra/lib/calls_audit.php — real inbound calls to live sites, via CallTrackingMetrics.
 *
 * ── SELF-CONTAINED ───────────────────────────────────────────────────────────────
 * Owns its own tables in state/fleet.db, creates them lazily. Borrows only
 * includes/calltrackingmetrics.php's client (one auth/HTTP implementation for that
 * API) and admin/infra/lib/audit_settings.php for the policy knobs.
 *
 * ── GROUND TRUTH, BUT NOT FOR FREE ───────────────────────────────────────────────
 * Every other fleet audit infers interest from a proxy (Googlebot visits, a SERP
 * position, an index count). A call is a real human who dialed a tracking number this
 * fleet bought and labelled. The join is already solved: CTM's `tracking_label` field
 * is exactly what ctm_build_label() writes at purchase time — e.g.
 * "WAT-B26-002-CA-Glendale-ellisonrestoration.com" — so resolving a call to a domain
 * is a string split, not a lookup table that can drift out of sync.
 *
 * ── THE CONFIGURED ACCOUNT(S) ARE NOT FLEET-EXCLUSIVE ────────────────────────────
 * A real pull against account 151459 (the one Scott confirmed 2026-10-21 — "just
 * 151459 for now") turned up call history back to 2021 for unrelated businesses
 * ("Gorilla Power", "slableakservice.com") that pre-date this site factory. CTM has
 * no notion of "which labels belong to this estate" — every call's tracking_label is
 * resolved against the real `domains` table, and anything that doesn't match a known
 * fleet domain is counted (so a sync log is honest about what it saw) but never
 * stored as fleet data. ctm_account_ids is deliberately plural-shaped from day one —
 * Scott expects more accounts later, and that should be a settings edit, not a code
 * change (see calls_audit_sync_all()).
 *
 * ── A CALL IS NOT A LEAD ──────────────────────────────────────────────────────────
 * Measured 2026-10-21: 14 of the last 15 calls on the one configured account were
 * 0-3 seconds of ACTUAL TALK TIME — rang briefly, never connected. Reporting a bare
 * call count would turn ring-and-hangup noise into a success metric. Every call is
 * stored (duration AND talk_time, separately — CTM's own distinction), but the
 * headline splits "rang" from "engaged" (talk_time >= calls_engaged_seconds), same
 * reasoning as rank/index refusing to collapse "measured zero" into "not measured."
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/state.php';
require_once dirname(__DIR__, 3) . '/includes/calltrackingmetrics.php';
require_once __DIR__ . '/audit_settings.php';

function calls_audit_db(): PDO
{
    static $ready = false;
    $db = infra_state_db();
    if ($ready) return $db;
    $db->exec("CREATE TABLE IF NOT EXISTS ctm_call (
        call_id        INTEGER PRIMARY KEY,  -- CTM's own id; a call record never changes after creation
        account_id     TEXT NOT NULL,
        domain         TEXT NOT NULL,
        label          TEXT NOT NULL DEFAULT '',
        direction      TEXT NOT NULL DEFAULT '',
        duration       INTEGER NOT NULL DEFAULT 0,  -- seconds, rang-to-hangup
        talk_time      INTEGER NOT NULL DEFAULT 0,  -- seconds, ACTUAL conversation — the honest metric
        dial_status    TEXT NOT NULL DEFAULT '',
        caller_number  TEXT NOT NULL DEFAULT '',
        called_at      TEXT NOT NULL DEFAULT '',    -- CTM's own display string, kept for the UI
        unix_time      INTEGER NOT NULL DEFAULT 0,
        synced_at      TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS ctm_call_domain ON ctm_call (domain, unix_time)');
    $db->exec("CREATE TABLE IF NOT EXISTS ctm_call_cursor (
        account_id     TEXT PRIMARY KEY,
        last_synced_at TEXT NOT NULL  -- the end_date used by the last successful sync, so the next
                                      -- one resumes from there rather than re-walking all history
    )");
    $ready = true;
    return $db;
}

/** Every domain this estate has ever claimed — the only valid join target. Memoized per request. */
function calls_audit_fleet_domains(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array_fill_keys(
        array_column(infra_state_db()->query('SELECT domain FROM domains')->fetchAll(PDO::FETCH_ASSOC), 'domain'),
        true
    );
    return $memo;
}

/**
 * A tracking_label resolved to one of OUR domains, or null.
 *
 * Two shapes seen in real CTM data: the structured fleet label (6 hyphen-separated
 * fields, domain last — explode(..., 6) so a domain that itself contained a hyphen
 * would still come through whole) and a bare domain string (some non-batch numbers
 * are labelled with just the domain). Either way, the real `domains` table is the
 * only thing that decides "ours" — a label merely LOOKING like our format is not
 * enough, since nothing stops a coincidence or a typo.
 */
function calls_audit_resolve_domain(string $label, array $fleetDomains): ?string
{
    $label = trim($label);
    if ($label === '') return null;
    $candidate = $label;
    $parts = explode('-', $label, 6);
    if (count($parts) === 6 && preg_match('/^B\d+$/', $parts[1])) {
        $candidate = $parts[5];
    }
    $candidate = strtolower(trim($candidate));
    return isset($fleetDomains[$candidate]) ? $candidate : null;
}

/**
 * Pull every call for one account across [$startDate, $endDate], store the ones that
 * resolve to a fleet domain, tally (not store) the rest, and advance the cursor.
 *
 * INSERT OR IGNORE on call_id: CTM call records are immutable once created, and the
 * date window deliberately overlaps the previous sync by a couple of days (handled
 * by the caller) to cover any late-indexed calls — re-seeing an old call here is
 * expected, not an error.
 */
function calls_audit_sync_account(string $accountId, string $startDate, string $endDate, ?callable $log = null): array
{
    $say = $log ?? function (string $s) {};
    $fleetDomains = calls_audit_fleet_domains();
    $db = calls_audit_db();
    $ins = $db->prepare('INSERT OR IGNORE INTO ctm_call
        (call_id, account_id, domain, label, direction, duration, talk_time, dial_status,
         caller_number, called_at, unix_time, synced_at)
        VALUES (:id,:acct,:dom,:lbl,:dir,:dur,:talk,:status,:caller,:called,:unix,:synced)');

    $syncedAt = gmdate('c');
    $pulled = 0; $ours = 0; $other = 0; $page = 1; $totalPages = 1;
    do {
        $r = ctm_list_calls($accountId, $startDate, $endDate, $page);
        if (!$r['ok']) {
            $say("account {$accountId}: FAILED on page {$page} — {$r['error']}");
            return ['account_id' => $accountId, 'ok' => false, 'error' => $r['error'],
                     'pulled' => $pulled, 'ours' => $ours, 'other' => $other];
        }
        $totalPages = max(1, $r['total_pages']);
        foreach ($r['calls'] as $c) {
            $pulled++;
            $label = (string) ($c['tracking_label'] ?? '');
            $domain = calls_audit_resolve_domain($label, $fleetDomains);
            if ($domain === null) { $other++; continue; }
            $ours++;
            $ins->execute([
                ':id' => (int) ($c['id'] ?? 0), ':acct' => $accountId, ':dom' => $domain, ':lbl' => $label,
                ':dir' => (string) ($c['direction'] ?? ''), ':dur' => (int) ($c['duration'] ?? 0),
                ':talk' => (int) ($c['talk_time'] ?? 0), ':status' => (string) ($c['dial_status'] ?? ''),
                ':caller' => (string) ($c['caller_number'] ?? ''), ':called' => (string) ($c['called_at'] ?? ''),
                ':unix' => (int) ($c['unix_time'] ?? 0), ':synced' => $syncedAt,
            ]);
        }
        $say("account {$accountId}: page {$page}/{$totalPages} — {$pulled} seen so far, {$ours} ours");
        $page++;
    } while ($page <= $totalPages);

    $db->prepare('INSERT INTO ctm_call_cursor (account_id, last_synced_at) VALUES (:a,:d)
        ON CONFLICT(account_id) DO UPDATE SET last_synced_at = excluded.last_synced_at')
        ->execute([':a' => $accountId, ':d' => $endDate]);

    return ['account_id' => $accountId, 'ok' => true, 'pulled' => $pulled, 'ours' => $ours, 'other' => $other,
             'from' => $startDate, 'to' => $endDate];
}

/**
 * Sync every configured account. Each account's own cursor decides its start date —
 * a newly-added second account backfills calls_backfill_days on its first run without
 * forcing an already-synced account to re-walk from scratch too.
 *
 * 2-day overlap on every run (not just the first) because CTM's `indexed_at` showed
 * calls appearing in the API a little after their real `unix_time` — the same
 * reasoning crawl_audit.php's cursor already uses.
 */
function calls_audit_run(?callable $log = null): array
{
    $say = $log ?? function (string $s) {};
    $accounts = infra_audit_ctm_account_ids();
    if (!$accounts) { $say('No CTM account IDs configured.'); return ['accounts' => []]; }
    if (!ctm_configured()) { $say('CTM_ACCESS_KEY/CTM_SECRET_KEY are not configured in config.php.'); return ['accounts' => []]; }

    $db = calls_audit_db();
    $cursors = [];
    foreach ($db->query('SELECT account_id, last_synced_at FROM ctm_call_cursor')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cursors[$r['account_id']] = $r['last_synced_at'];
    }
    $backfillDays = (int) infra_audit_setting('calls_backfill_days');
    $today = gmdate('Y-m-d');

    $results = [];
    foreach ($accounts as $acct) {
        $last = $cursors[$acct] ?? null;
        $start = $last !== null
            ? gmdate('Y-m-d', strtotime($last) - 2 * 86400)
            : gmdate('Y-m-d', time() - $backfillDays * 86400);
        $say(($last === null ? "account {$acct}: first sync, backfilling {$backfillDays}d from {$start}"
                             : "account {$acct}: resuming from {$start} (cursor {$last})"));
        $results[] = calls_audit_sync_account($acct, $start, $today, $say);
    }

    // Written here, not only in the CLI wrapper, so the web "Sync now" button (which
    // runs this same function via a background job, not the CLI script directly)
    // leaves the same footprint — same reasoning as golive_tick.json: a run that found
    // nothing new must still prove it actually ran.
    @file_put_contents(dirname(__DIR__) . '/state/calls_tick.json', json_encode([
        'at' => gmdate('c'), 'accounts' => $results,
    ], JSON_PRETTY_PRINT));

    return ['accounts' => $results];
}

/**
 * Rolled up over the last $days — per domain and fleet-wide, split rang vs engaged.
 *
 * ⚠ "Engaged" is a judgement call (calls_engaged_seconds), not a CTM-reported fact —
 * same honesty as rank/index's own thresholds: the card shows the bar in force.
 *
 * @param ?array $scope domain(lower) => true, from infra_audit_scope() — null means
 *        every synced domain, unchanged from before this parameter existed.
 */
function calls_audit_latest(int $days = 30, ?array $scope = null): array
{
    $empty = ['days' => $days, 'calls' => 0, 'engaged' => 0, 'rang' => 0,
              'engaged_seconds' => (int) infra_audit_setting('calls_engaged_seconds'), 'domains' => []];
    $db = calls_audit_db();
    $engagedAt = (int) infra_audit_setting('calls_engaged_seconds');

    // called_at is CTM's own freeform display string ("2026-10-21 04:42 PM +00:00"),
    // not an ISO sort key — filter on unix_time instead, which IS comparable.
    $sinceTs = time() - $days * 86400;
    $st = $db->query('SELECT domain, talk_time, called_at, unix_time, caller_number
                      FROM ctm_call WHERE direction = "inbound" ORDER BY unix_time DESC');
    $byDomain = [];
    $out = $empty;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int) $r['unix_time'] < $sinceTs) continue;
        $d = $r['domain'];
        if ($scope !== null && !isset($scope[$d])) continue;
        $out['calls']++;
        $engaged = (int) $r['talk_time'] >= $engagedAt;
        if ($engaged) $out['engaged']++; else $out['rang']++;
        $byDomain[$d] ??= ['domain' => $d, 'calls' => 0, 'engaged' => 0, 'last_call_at' => $r['called_at']];
        $byDomain[$d]['calls']++;
        if ($engaged) $byDomain[$d]['engaged']++;
    }
    usort($byDomain, fn($a, $b) => $b['calls'] <=> $a['calls']);
    $out['domains'] = array_values($byDomain);
    return $out;
}

/** The per-domain table body, fetched on demand like every other audit's <details>. */
function calls_audit_render_rows(array $domains): string
{
    ob_start(); ?>
        <?php foreach ($domains as $d): ?>
            <tr>
                <td><a href="https://<?= h($d['domain']) ?>/" target="_blank" rel="noopener"><?= h($d['domain']) ?></a></td>
                <td><?= (int) $d['calls'] ?></td>
                <td class="<?= $d['engaged'] ? 'fa-good' : '' ?>"><?= (int) $d['engaged'] ?></td>
                <td><?= h($d['last_call_at']) ?></td>
            </tr>
        <?php endforeach;
    return ob_get_clean();
}
