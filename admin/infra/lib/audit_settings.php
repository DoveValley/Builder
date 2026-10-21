<?php
/**
 * infra/lib/audit_settings.php — the judgement calls behind the fleet audit boxes.
 *
 * ── WHY ITS OWN FILE AND NOT fleet_settings.php ──────────────────────────────────
 * Same pattern deliberately (defaults → clean → read → save, clamped on every read,
 * so a hand-edited or half-written file cannot produce nonsense) but its own storage,
 * so the audit feature stays removable whole. fleet_settings.json holds things the
 * FACTORY needs to operate — when a domain goes live, how many zones an account takes.
 * These are reporting thresholds. Mixing them would mean deleting the audits later
 * required surgery on the file that decides go-live times.
 *
 * ── WHAT BELONGS HERE, AND WHAT DOES NOT ─────────────────────────────────────────
 * Everything here is POLICY — a reasonable person could choose differently, and the
 * right value changes as the fleet matures. What is deliberately NOT here:
 *
 *   · the Googlebot IP ranges and the verify-by-IP rule — 30 of 78 claimed Googlebot
 *     hits were fake; that is not a preference
 *   · the -1 ≠ 0 conventions
 *   · rank depth 100 — depth 20 would have called a site at #72 "not ranking"
 *   · the crawl cron cadence against the ~3.5-day log window — a hard constraint
 *
 * A wrong value for any of those is a silently wrong answer, not a different opinion.
 *
 * ⚠ CHANGING A THRESHOLD CHANGES WHAT A NUMBER MEANS. Move stale_crawl_days from 7 to
 * 14 and the crawl box goes green without one thing improving. So every panel shows
 * the thresholds in force next to its verdict — the same reason a rank row stores the
 * location and depth it was measured at.
 */
require_once __DIR__ . '/store.php';

function infra_audit_settings_defaults(): array
{
    return [
        // Under this many days since go-live, "not indexed"/"not ranking" is just new.
        // 21 because the measured curve on 2026-10-21 showed ~21-day sites at 26-81%
        // indexed while 7-day sites had a single page each.
        'too_new_days'          => 21,
        // How long Google's silence is worth flagging on the crawl box.
        'stale_crawl_days'      => 7,
        // Google's site: count swings (62, 10, NULL for one query ninety seconds
        // apart), so a one-sweep fall is recorded but not alarmed on.
        'index_drop_sweeps'     => 2,
        // Which keyword tiers the rank sweep pays for. Drives the bill directly:
        // home+high-1 = 567 keywords = $6.18; adding high-2 roughly doubles it.
        'rank_tiers'            => 'high-1',
        // DataForSEO throttles; more is not faster past a point. See the backoff note
        // in rank_audit.php — 2 was not better than 4 in testing.
        'probe_concurrency'     => 4,
        // How often the UNATTENDED rank sweep fires (rank_tick.php, cron-driven).
        // Weekly by default; the button on the panel still runs one on demand any time.
        'rank_cadence_weeks'    => 1,
        // The ON/OFF switch for the unattended sweep. Pausing this does NOT touch
        // /etc/cron.d/infra-rank — the cron keeps ticking daily, it just finds
        // nothing due and spends nothing. See rank_audit_due().
        'rank_cadence_enabled'  => true,
        // Measured 2026-10-21 ($70.3400 → $70.2206 over 11 probes). Shown in the
        // confirm dialog, so it being wrong means being lied to about the spend.
        'cost_per_probe'        => 0.0109,
        // Lines of access log pulled per domain. Bailey's whole file was 3,320 lines
        // spanning 3.5 days; a busier site holds less, so bigger is safer.
        'crawl_tail_lines'      => 5000,
    ];
}

function infra_audit_settings_clean(array $raw): array
{
    $d = infra_audit_settings_defaults();
    $int = function ($v, int $lo, int $hi, int $def) {
        return is_numeric($v) ? max($lo, min($hi, (int) $v)) : $def;
    };
    $tier = strtolower(trim((string) ($raw['rank_tiers'] ?? '')));
    return [
        'too_new_days'      => $int($raw['too_new_days']      ?? null, 0,   180,   $d['too_new_days']),
        'stale_crawl_days'  => $int($raw['stale_crawl_days']  ?? null, 1,    90,   $d['stale_crawl_days']),
        // 1 would alarm on a single noisy probe, which is the thing it exists to stop.
        'index_drop_sweeps' => $int($raw['index_drop_sweeps'] ?? null, 2,    10,   $d['index_drop_sweeps']),
        'rank_tiers'        => in_array($tier, ['high-1', 'high-2', 'high-3'], true) ? $tier : $d['rank_tiers'],
        // Hard ceiling of 8: the shared HTTP helper will not usefully go beyond it and
        // DataForSEO starts failing probes, which this estate then has to retry.
        'probe_concurrency' => $int($raw['probe_concurrency'] ?? null, 1,     8,   $d['probe_concurrency']),
        // 1-12 weeks: below weekly is paying DataForSEO for noise (see
        // RANK_MIN_SWEEP_GAP_HOURS); past a quarter the panel is effectively unattended.
        'rank_cadence_weeks' => $int($raw['rank_cadence_weeks'] ?? null, 1,    12,   $d['rank_cadence_weeks']),
        'rank_cadence_enabled' => array_key_exists('rank_cadence_enabled', $raw)
                                ? filter_var($raw['rank_cadence_enabled'], FILTER_VALIDATE_BOOLEAN)
                                : $d['rank_cadence_enabled'],
        'cost_per_probe'    => is_numeric($raw['cost_per_probe'] ?? null)
                                ? max(0.0, min(1.0, round((float) $raw['cost_per_probe'], 4)))
                                : $d['cost_per_probe'],
        'crawl_tail_lines'  => $int($raw['crawl_tail_lines']  ?? null, 500, 50000, $d['crawl_tail_lines']),
    ];
}

/** Always clamped, so no caller has to defend itself against the stored file. */
function infra_audit_settings(): array
{
    return infra_audit_settings_clean(infra_load_json(infra_config_path('audit_settings.json'), []));
}

/** One setting, clamped. */
function infra_audit_setting(string $key)
{
    $s = infra_audit_settings();
    return $s[$key] ?? null;
}

/** Merge $changes over the current settings, clamp, save. @return array the saved settings */
function infra_audit_settings_save(array $changes): array
{
    $merged = array_merge(infra_audit_settings(),
        array_filter($changes, fn($v) => $v !== '' && $v !== null));
    $s = infra_audit_settings_clean($merged);
    infra_save_json(infra_config_path('audit_settings.json'), $s);
    return $s;
}

/**
 * Which keyword tiers a rank sweep covers, as the list rank_audit.php filters on.
 *
 * The homepage term carries an EMPTY tier in keyword_map.json, so it is always
 * included — a rank report that silently omitted the main keyword would be worse than
 * no report. Choosing "high-2" means high-1 AND high-2, not high-2 alone: the setting
 * reads as a depth, which is how a person thinks about it.
 */
function infra_audit_rank_tier_list(): array
{
    $want = (string) infra_audit_setting('rank_tiers');
    $tiers = [''];
    foreach (['high-1', 'high-2', 'high-3'] as $t) {
        $tiers[] = $t;
        if ($t === $want) break;
    }
    return $tiers;
}
