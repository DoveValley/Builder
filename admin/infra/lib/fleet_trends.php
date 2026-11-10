<?php
/**
 * Fleet trend reports — weekly rollup + a plain-English log + a dependency-free
 * inline SVG sparkline, shared by the four fleet-audit cards (calls/crawl/rank/
 * index).
 *
 * DELIBERATELY SEPARATE from calls_audit.php / crawl_audit.php / rank_audit.php /
 * index_audit.php: this file only READS their existing tables via their own
 * db-connection function, never writes to them, and never calls into their
 * business logic. A bug here can't touch what those modules already do, and
 * every function below takes plain arrays/scalars and returns plain arrays — no
 * superglobals, no echo — so any of them can be exercised standalone from the
 * CLI (`php -r 'require ...; var_dump(fleet_trend_calls(...));'`) without
 * touching a browser or session.
 *
 * One file on purpose, not four — one place to look, per Scott's "keep it simple
 * and modular, don't weave it into the application deep" (2026-11-10). If a
 * metric's rollup ever needs its own file, split it out then; it doesn't yet.
 *
 * No persisted rollup table yet — today's real volume (a few thousand rows across
 * all four tables) queries fast live. If that stops being true, a cache table
 * slots in behind fleet_trend_calls()/fleet_trend_crawl()/etc. without changing
 * what they return, so nothing above this file would need to change.
 */

require_once __DIR__ . '/state.php';

// ── Generic helpers (no knowledge of calls/crawl/rank/index) ──────────────────

/** Monday 00:00 UTC of the week containing $ts, as a 'Y-m-d' key. ISO weeks (Mon
 *  start), not Sunday-start, so a week never splits a Sat/Sun pair across two
 *  buckets. */
function fleet_trend_week_key(int $ts): string
{
    $dow = (int) gmdate('N', $ts); // 1 (Mon) .. 7 (Sun)
    return gmdate('Y-m-d', $ts - ($dow - 1) * 86400);
}

/** The most recent $weeks week-keys, oldest first, ending at the current week. */
function fleet_trend_week_range(int $weeks): array
{
    $thisWeek = fleet_trend_week_key(time());
    $out = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $out[] = gmdate('Y-m-d', strtotime($thisWeek) - $i * 7 * 86400);
    }
    return $out;
}

/**
 * Builds the final per-week series from a raw week=>value map, filling in every
 * requested week (even ones with no rows) so a gap is visible instead of silently
 * skipped.
 *
 * $measuredWeeks, when given, marks which weeks the underlying check actually RAN
 * at all (rank/index sweeps are irregular, not daily) — a week absent from this
 * list is tagged 'measured' => false so the caller can render "no sweep this
 * week" instead of a false flat zero. Pass null for daily/event data (calls,
 * crawl) where every week in range is always "measured" by definition.
 *
 * The CURRENT week (the last entry) is also flagged 'partial' => true whenever
 * today isn't that week's final day — the week is still accumulating, so a raw
 * week-over-week delta against it would compare a full week to a few hours of
 * one and read as a drop that isn't real. See fleet_trend_log_lines().
 *
 * @return array<array{week:string, value:float, measured:bool, partial:bool}>
 */
function fleet_trend_series(array $valueByWeek, int $weeks, ?array $measuredWeeks = null): array
{
    $range = fleet_trend_week_range($weeks);
    $currentWeek = end($range);
    $weekEndTs = strtotime($currentWeek) + 7 * 86400; // next Monday 00:00 UTC
    $out = [];
    foreach ($range as $wk) {
        $measured = $measuredWeeks === null ? true : in_array($wk, $measuredWeeks, true);
        $partial = ($wk === $currentWeek) && (time() < $weekEndTs);
        $out[] = ['week' => $wk, 'value' => (float) ($valueByWeek[$wk] ?? 0), 'measured' => $measured, 'partial' => $partial];
    }
    return $out;
}

/**
 * Walks backward from just before index $i to the nearest week that was both
 * measured and complete (not partial) — NOT necessarily $i-1, since sweep-based
 * metrics (rank/index) can have an unmeasured gap week sitting between two real
 * sweeps. Comparing against $i-1 blindly would silently drop the delta the
 * moment a gap week happens to land there, which is exactly the kind of
 * "credence to progress" detail this module exists to get right.
 */
function fleet_trend_prev_measured(array $series, int $i): ?array
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if ($series[$j]['measured'] && empty($series[$j]['partial'])) return $series[$j];
    }
    return null;
}

/**
 * Plain-English weekly log, newest first — the "credence to progress or
 * non-progress" text Scott asked for, generated from the exact same series the
 * chart plots, so the two can never disagree.
 *
 * $label(float $value, array $week): string formats one week's own number (e.g.
 * "47 calls (12 engaged)"); this function supplies the week-over-week delta
 * phrasing on top of it.
 */
function fleet_trend_log_lines(array $series, callable $label): array
{
    $lines = [];
    for ($i = count($series) - 1; $i >= 0; $i--) {
        $wk = $series[$i];
        if (!$wk['measured']) {
            $lines[] = ['week' => $wk['week'], 'text' => 'No check ran this week — no data, not a real zero.'];
            continue;
        }
        $text = $label($wk['value'], $wk);
        if ($wk['partial']) {
            // A week still accumulating can't be honestly compared to a full
            // week before it — "down 78%" would just mean "it's Tuesday,"
            // not a real decline. State the partial number and stop there.
            $text .= ' so far this week (week in progress)';
            $lines[] = ['week' => $wk['week'], 'text' => $text];
            continue;
        }
        // "the last measurement" rather than "the week before": $prev can be more
        // than one calendar week back when a gap week sits between them (see
        // fleet_trend_prev_measured()), so "the week before" would misstate which
        // week it's actually being compared against.
        $prev = fleet_trend_prev_measured($series, $i);
        if ($prev !== null) {
            $delta = $wk['value'] - $prev['value'];
            if (abs($delta) < 0.0001) {
                $text .= ', flat vs. the last measurement';
            } else {
                $pct = $prev['value'] > 0 ? round(abs($delta) / $prev['value'] * 100) : null;
                $text .= ', ' . ($delta > 0 ? 'up' : 'down') . ' from ' . fleet_trend_num($prev['value'])
                       . ' at the last measurement' . ($pct !== null ? ' (' . ($delta > 0 ? '+' : '-') . $pct . '%)' : '');
            }
        }
        $lines[] = ['week' => $wk['week'], 'text' => $text];
    }
    return $lines;
}

function fleet_trend_num(float $v): string
{
    return $v == (int) $v ? (string) (int) $v : rtrim(rtrim(number_format($v, 1), '0'), '.');
}

/**
 * A dependency-free inline SVG bar chart — no JS, no CDN, no build step, matches
 * this codebase's "no build step" rule. Bars over a line chart on purpose: with
 * only a handful of weekly points, discrete bars are easier to eyeball than a
 * path, and an unmeasured week renders as a visibly dashed/hollow bar instead of
 * a misleading flat line through zero.
 *
 * A 'partial' week (the current, still-accumulating week — see
 * fleet_trend_series()) renders at reduced opacity, distinct from both a normal
 * complete bar and a dashed/hollow "no check ran" gap, so an eye scanning the
 * chart doesn't read "it dropped" into a week that just isn't over yet.
 *
 * @param array<array{week:string, value:float, measured:bool, partial?:bool}> $series
 */
function fleet_trend_svg(array $series, array $opts = []): string
{
    $w = (int) ($opts['width'] ?? 420);
    $h = (int) ($opts['height'] ?? 90);
    $color = (string) ($opts['color'] ?? '#2563eb');
    $pad = 18; // room for the value label above the tallest bar
    $n = count($series);
    if ($n === 0) return '';
    $max = max(1.0, max(array_column($series, 'value')));
    $barW = ($w - 8) / $n;
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . ($h + $pad) . '" width="' . $w . '" height="' . ($h + $pad)
         . '" role="img" aria-label="Weekly trend chart">';
    foreach ($series as $i => $wk) {
        $x = 4 + $i * $barW;
        $bh = $wk['measured'] ? max(1, ($wk['value'] / $max) * $h) : 2;
        $y = $h - $bh;
        $partial = !empty($wk['partial']);
        $barFill = $wk['measured'] ? $color : 'none';
        $opacity = $partial ? ' opacity="0.45"' : '';
        $barStroke = $wk['measured'] ? 'none' : '#cbd5e1';
        $dash = $wk['measured'] ? '' : ' stroke-dasharray="2,2"';
        $titleSuffix = !$wk['measured'] ? 'no check ran' : ($partial ? fleet_trend_num($wk['value']) . ' so far (week in progress)' : fleet_trend_num($wk['value']));
        $svg .= '<rect x="' . round($x + 2, 1) . '" y="' . round($y, 1) . '" width="' . round($barW - 4, 1)
              . '" height="' . round($bh, 1) . '" fill="' . $barFill . '" stroke="' . $barStroke . '"' . $dash . $opacity
              . '><title>' . htmlspecialchars($wk['week']) . ': ' . $titleSuffix . '</title></rect>';
        if ($wk['measured'] && $wk['value'] > 0) {
            $svg .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . round($y - 3, 1)
                  . '" font-size="9" fill="#475569" text-anchor="middle">' . fleet_trend_num($wk['value']) . '</text>';
        }
    }
    // Baseline.
    $svg .= '<line x1="0" y1="' . $h . '" x2="' . $w . '" y2="' . $h . '" stroke="#e2e8f0" stroke-width="1"/>';
    $svg .= '</svg>';
    return $svg;
}

// ── Calls ───────────────────────────────────────────────────────────────────

/**
 * Weekly call volume + engaged count, scoped the same way as the rest of the
 * Fleet calls check ($scope = domain=>true map, or null for everything).
 *
 * Deliberately queries ctm_call directly rather than reusing
 * calls_audit_call_rows() — that function caps its 'rows' at
 * CALLS_AUDIT_CALL_ROWS_LIMIT for display purposes, which would silently
 * under-count a weekly total once the fleet has more than that many calls in
 * the window. An aggregate needs every row, not a capped preview list.
 *
 * @return array{calls:array, engaged:array, log:array<array{week:string,text:string}>}
 */
function fleet_trend_calls(?array $scope, int $weeks = 8): array
{
    require_once __DIR__ . '/calls_audit.php';
    $db = calls_audit_db();
    $engagedAt = (int) infra_audit_setting('calls_engaged_seconds');
    $sinceTs = strtotime(fleet_trend_week_range($weeks)[0]);
    $st = $db->prepare('SELECT domain, talk_time, unix_time FROM ctm_call
                         WHERE direction = "inbound" AND unix_time >= :since');
    $st->execute([':since' => $sinceTs]);
    $callsByWeek = []; $engagedByWeek = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($scope !== null && !isset($scope[$r['domain']])) continue;
        $wk = fleet_trend_week_key((int) $r['unix_time']);
        $callsByWeek[$wk] = ($callsByWeek[$wk] ?? 0) + 1;
        if ((int) $r['talk_time'] >= $engagedAt) $engagedByWeek[$wk] = ($engagedByWeek[$wk] ?? 0) + 1;
    }
    $calls = fleet_trend_series($callsByWeek, $weeks);
    $engaged = fleet_trend_series($engagedByWeek, $weeks);
    $log = fleet_trend_log_lines($calls, function (float $v, array $wk) use ($engaged) {
        $e = (int) ($engaged[array_search($wk['week'], array_column($engaged, 'week'))]['value'] ?? 0);
        return fleet_trend_num($v) . ' call' . ($v == 1 ? '' : 's') . ' (' . $e . ' engaged)';
    });
    return ['calls' => $calls, 'engaged' => $engaged, 'log' => $log];
}

// ── Crawl ───────────────────────────────────────────────────────────────────

/** Weekly verified-Googlebot hit volume, same $scope convention as above. */
function fleet_trend_crawl(?array $scope, int $weeks = 8): array
{
    require_once __DIR__ . '/crawl_audit.php';
    $db = crawl_audit_db();
    $sinceDay = fleet_trend_week_range($weeks)[0];
    $st = $db->prepare('SELECT day, domain, gbot_hits FROM crawl_daily WHERE day >= :since');
    $st->execute([':since' => $sinceDay]);
    $hitsByWeek = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($scope !== null && !isset($scope[$r['domain']])) continue;
        $wk = fleet_trend_week_key(strtotime($r['day']));
        $hitsByWeek[$wk] = ($hitsByWeek[$wk] ?? 0) + (int) $r['gbot_hits'];
    }
    $hits = fleet_trend_series($hitsByWeek, $weeks);
    $log = fleet_trend_log_lines($hits, fn($v) => fleet_trend_num($v) . ' verified Googlebot hit' . ($v == 1 ? '' : 's'));
    return ['hits' => $hits, 'log' => $log];
}

// ── Rank ────────────────────────────────────────────────────────────────────

/**
 * Weekly average rank position (lower is better) across whatever sweeps landed
 * that week — only counts keywords actually ranked in the top RANK_DEPTH
 * (position > 0), since -1/0 aren't a position to average. $measuredWeeks marks
 * which weeks had ANY sweep at all, since sweeps run irregularly, not weekly.
 */
function fleet_trend_rank(?array $scope, int $weeks = 8): array
{
    require_once __DIR__ . '/rank_audit.php';
    $db = rank_audit_db();
    $sinceTs = fleet_trend_week_range($weeks)[0];
    $st = $db->prepare('SELECT sweep_at, domain, position FROM rank_sweep WHERE sweep_at >= :since AND position > 0');
    $st->execute([':since' => $sinceTs]);
    $sumByWeek = []; $countByWeek = []; $measuredWeeks = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($scope !== null && !isset($scope[$r['domain']])) continue;
        $wk = fleet_trend_week_key(strtotime($r['sweep_at']));
        $sumByWeek[$wk] = ($sumByWeek[$wk] ?? 0) + (int) $r['position'];
        $countByWeek[$wk] = ($countByWeek[$wk] ?? 0) + 1;
        $measuredWeeks[$wk] = true;
    }
    $avgByWeek = [];
    foreach ($sumByWeek as $wk => $sum) $avgByWeek[$wk] = round($sum / $countByWeek[$wk], 1);
    $avg = fleet_trend_series($avgByWeek, $weeks, array_keys($measuredWeeks));
    // Lower average position is BETTER — delta phrasing in the generic log helper
    // assumes "up = good", so this metric gets its own inverted log text rather
    // than reusing fleet_trend_log_lines' default direction wording.
    $log = [];
    for ($i = count($avg) - 1; $i >= 0; $i--) {
        $wk = $avg[$i];
        if (!$wk['measured']) { $log[] = ['week' => $wk['week'], 'text' => 'No rank sweep ran this week.']; continue; }
        $text = 'Avg. position ' . fleet_trend_num($wk['value']) . ' across ' . $countByWeek[$wk['week']] . ' ranked keyword(s)';
        // fleet_trend_prev_measured(), not $avg[$i-1] — a sweep-less gap week can
        // sit between two real sweeps, and "the last sweep" must mean the last
        // one that actually happened, not whatever week slot comes right before.
        $prev = fleet_trend_prev_measured($avg, $i);
        if ($prev !== null) {
            $delta = $wk['value'] - $prev['value'];
            if (abs($delta) < 0.05) $text .= ', flat vs. the last sweep';
            else $text .= ', ' . ($delta < 0 ? 'improved' : 'slipped') . ' from ' . fleet_trend_num($prev['value']) . ' at the last sweep';
        }
        $log[] = ['week' => $wk['week'], 'text' => $text];
    }
    return ['avg_position' => $avg, 'log' => $log];
}

// ── Index ───────────────────────────────────────────────────────────────────

/** Weekly total pages-indexed across whatever sweeps landed that week. Same
 *  irregular-sweep gap handling as rank above. */
function fleet_trend_index(?array $scope, int $weeks = 8): array
{
    require_once __DIR__ . '/index_audit.php';
    $db = index_audit_db();
    $sinceTs = fleet_trend_week_range($weeks)[0];
    $st = $db->prepare('SELECT sweep_at, domain, indexed FROM index_sweep WHERE sweep_at >= :since AND indexed >= 0');
    $st->execute([':since' => $sinceTs]);
    $sumByWeek = []; $measuredWeeks = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($scope !== null && !isset($scope[$r['domain']])) continue;
        $wk = fleet_trend_week_key(strtotime($r['sweep_at']));
        $sumByWeek[$wk] = ($sumByWeek[$wk] ?? 0) + (int) $r['indexed'];
        $measuredWeeks[$wk] = true;
    }
    $indexed = fleet_trend_series($sumByWeek, $weeks, array_keys($measuredWeeks));
    $log = fleet_trend_log_lines($indexed, fn($v) => fleet_trend_num($v) . ' page' . ($v == 1 ? '' : 's') . ' indexed, fleet-wide');
    return ['indexed' => $indexed, 'log' => $log];
}
