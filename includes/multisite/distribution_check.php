<?php
/**
 * Distribution check — registrar/state concentration per box, meant to run on a
 * batch's current target list AFTER Auto-fill but BEFORE buying, while row order
 * can still be adjusted. Purely read-only: computes a report, changes nothing.
 *
 * WHY THIS EXISTS. Strict positional box assignment (includes/multisite/
 * box_assignment.php) gives a batch perfectly even box and Cloudflare-account
 * totals — but box is now a pure function of row POSITION (period 20), and
 * registrar/state were assigned by an earlier, independent scheduling pass. If
 * that pass's own rhythm happens to share structure with period 20 (the exact
 * GCD(20,6)=2 trap the registrar spread was built to avoid for a flat modulo
 * assignment), the result is a box that only ever sees a handful of registrars,
 * or a state that clusters onto a handful of boxes — found exactly this way on
 * appliance's next-400 batches (box 1/7/13/19 permanently NameSilo/Porkbun/Gandi
 * only, never the other three; California landing disproportionately on boxes
 * 1/11/15/17 in every one of 4 batches). Neither breaks the box<->Cloudflare-
 * account 1:1 isolation itself, but both are real, avoidable secondary signals
 * worth catching before money is spent on a fixed registrar.
 */

/**
 * For one column ('registrar' or 'state'), how concentrated each value is on each
 * box relative to an even spread, given THIS BATCH's own totals — not a global
 * assumption, since a batch's own registrar/state mix is what actually matters
 * here. Only counts rows that already have a box assigned (blank box = not
 * auto-filled yet, excluded rather than guessed at).
 *
 * @return array<int,array{box:int,value:string,actual:int,expected:float,ratio:float}>
 *   sorted worst (highest ratio) first.
 */
function ms_box_concentration(array $rows, string $column): array
{
    $byBox  = [];   // box => value => count
    $totals = [];   // value => count
    $boxes  = [];
    foreach ($rows as $r) {
        $box = (int) ($r['box'] ?? 0);
        if ($box < 1) continue;
        $boxes[$box] = true;
        $val = trim((string) ($r[$column] ?? ''));
        if ($val === '') continue;
        $byBox[$box][$val] = ($byBox[$box][$val] ?? 0) + 1;
        $totals[$val] = ($totals[$val] ?? 0) + 1;
    }
    $numBoxes = count($boxes) ?: 1;

    $out = [];
    foreach ($byBox as $box => $vals) {
        foreach ($vals as $val => $actual) {
            $expected = ($totals[$val] ?? 0) / $numBoxes;
            $ratio = $expected > 0 ? $actual / $expected : 0;
            $out[] = ['box' => $box, 'value' => $val, 'actual' => $actual,
                       'expected' => round($expected, 2), 'ratio' => round($ratio, 2)];
        }
    }
    usort($out, fn($a, $b) => $b['ratio'] <=> $a['ratio']);
    return $out;
}

/**
 * Which values a box NEVER sees at all, despite that value having a real presence
 * elsewhere in the batch — a sharper, complementary signal to ms_box_concentration()
 * above. A concentration ratio only flags a box carrying MORE than its even share;
 * it can't see a box carrying exactly ZERO of something, which is the more precise
 * version of the real finding this file exists for (box 1 never sees 3 of the 6
 * registrars at all — not "a bit few", categorically absent, every batch).
 *
 * $minTotalToFlag ignores values too rare in the batch overall to mean anything —
 * a registrar used once in 100 rows is trivially "missing" from 19 boxes without
 * that being a real pattern.
 *
 * @return array<int,array{box:int,value:string,batch_total:int}> sorted by how
 *   large the missing value's batch-wide presence is (most surprising first).
 */
function ms_box_missing_values(array $rows, string $column, int $minTotalToFlag = 8): array
{
    $byBox  = [];   // box => value => true (presence only)
    $totals = [];   // value => count
    $boxes  = [];
    foreach ($rows as $r) {
        $box = (int) ($r['box'] ?? 0);
        if ($box < 1) continue;
        $boxes[$box] = true;
        $val = trim((string) ($r[$column] ?? ''));
        if ($val === '') continue;
        $byBox[$box][$val] = true;
        $totals[$val] = ($totals[$val] ?? 0) + 1;
    }

    $out = [];
    foreach (array_keys($boxes) as $box) {
        foreach ($totals as $val => $total) {
            if ($total < $minTotalToFlag) continue;
            if (empty($byBox[$box][$val])) {
                $out[] = ['box' => $box, 'value' => $val, 'batch_total' => $total];
            }
        }
    }
    usort($out, fn($a, $b) => $b['batch_total'] <=> $a['batch_total'] ?: $a['box'] <=> $b['box']);
    return $out;
}

/**
 * Suggest row swaps that would clear each state hotspot — the minimal-disruption
 * fix. A state can't be reassigned the way registrar can (it's tied to the row's
 * real city/business identity), so the only lever is WHICH ROW sits at which
 * position — swap two rows' full content and each keeps its own box/round under
 * strict positional assignment. box/cf_account/registrar are never touched by
 * this function; they stay exactly what the position-based formulas already say,
 * since position itself doesn't move, only which row's data occupies it.
 *
 * For each hotspot, moves just enough of the over-represented state's rows off
 * the hot box to bring it back near its even share — trading each one with a
 * row on a DIFFERENT box that doesn't already carry that state (so the swap
 * doesn't just relocate the hotspot rather than clearing it). A row already
 * used in one suggested swap is never reused in another.
 *
 * @return array<int,array{from_pos:int,from_domain:string,to_pos:int,
 *   to_domain:string,state:string,hot_box:int}> empty if no safe swap exists
 *   for a given hotspot (reported as unresolved, not forced).
 */
function ms_suggest_state_swaps(array $rows, array $stateHotspots): array
{
    $byPos = [];
    foreach ($rows as $i => $r) $byPos[$i + 1] = $r;

    $stateByBox = [];   // box => state => count, to judge a safe donor
    foreach ($byPos as $pos => $r) {
        $box = (int) ($r['box'] ?? 0);
        $st  = trim((string) ($r['state'] ?? ''));
        if ($box < 1 || $st === '') continue;
        $stateByBox[$box][$st] = ($stateByBox[$box][$st] ?? 0) + 1;
    }

    $used = [];   // position => true, once committed to a suggested swap
    $out  = [];

    foreach ($stateHotspots as $h) {
        $hotBox = (int) $h['box'];
        $state  = (string) $h['value'];
        $excess = max(1, (int) round($h['actual'] - $h['expected']));

        $candidates = [];
        foreach ($byPos as $pos => $r) {
            if (isset($used[$pos])) continue;
            if ((int) ($r['box'] ?? 0) === $hotBox && trim((string) ($r['state'] ?? '')) === $state) {
                $candidates[] = $pos;
            }
        }

        $moved = 0;
        foreach ($candidates as $fromPos) {
            if ($moved >= $excess) break;

            // Best donor: a different box, not already carrying this state (or
            // carrying the least of it), whose own row isn't itself one of the
            // candidates above (that would just trade two hot rows in place).
            $bestDonor = null; $bestScore = null;
            foreach ($byPos as $pos => $r) {
                if (isset($used[$pos]) || $pos === $fromPos) continue;
                $donorBox = (int) ($r['box'] ?? 0);
                if ($donorBox === $hotBox) continue;
                $donorState = trim((string) ($r['state'] ?? ''));
                if ($donorState === $state) continue;
                $score = $stateByBox[$donorBox][$state] ?? 0;
                if ($bestScore === null || $score < $bestScore) { $bestScore = $score; $bestDonor = $pos; }
            }
            if ($bestDonor === null) continue;   // no safe donor — left unresolved, not forced

            $out[] = [
                'from_pos'    => $fromPos,
                'from_domain' => (string) ($byPos[$fromPos]['domain'] ?? ''),
                'to_pos'      => $bestDonor,
                'to_domain'   => (string) ($byPos[$bestDonor]['domain'] ?? ''),
                'state'       => $state,
                'hot_box'     => $hotBox,
            ];
            $used[$fromPos] = true;
            $used[$bestDonor] = true;
            $moved++;
        }
    }
    return $out;
}

/**
 * The full report: box/Cloudflare-account totals (sanity — should already be
 * perfectly even if Auto-fill was used and nothing's been hand-edited since),
 * plus registrar/state hotspots — a box carrying notably more of one registrar
 * or state than its even share would predict.
 *
 * $flagRatio: how far above expected counts as worth flagging (1.5 = 50% over).
 * $flagMinCount: ignore small-sample noise — a box with 1 domain of a rare state
 * is technically "infinite ratio" above its ~0.05 expected share and meaningless.
 *
 * @return array{box_counts:array<int,int>, cf_counts:array<string,int>,
 *               registrar_hotspots:array, state_hotspots:array}
 */
function ms_box_distribution_report(array $rows, float $flagRatio = 1.5, int $flagMinCount = 3): array
{
    $boxCounts = [];
    $cfCounts  = [];
    foreach ($rows as $r) {
        $box = (int) ($r['box'] ?? 0);
        if ($box < 1) continue;
        $boxCounts[$box] = ($boxCounts[$box] ?? 0) + 1;
        $cf = trim((string) ($r['cf_account'] ?? ''));
        if ($cf !== '') {
            $key = $box . '.' . $cf;
            $cfCounts[$key] = ($cfCounts[$key] ?? 0) + 1;
        }
    }

    $hot = fn(string $col) => array_values(array_filter(
        ms_box_concentration($rows, $col),
        fn($h) => $h['actual'] >= $flagMinCount && $h['ratio'] >= $flagRatio
    ));

    return [
        'box_counts'         => $boxCounts,
        'cf_counts'          => $cfCounts,
        'registrar_hotspots' => $hot('registrar'),
        'state_hotspots'     => $hot('state'),
        // Registrar only, not state: a box missing one state entirely is normal
        // (most boxes won't carry every one of 40+ states) — missing a registrar
        // entirely, out of a pool of just 6, is the sharper and more meaningful
        // absence.
        'registrar_missing'  => ms_box_missing_values($rows, 'registrar'),
    ];
}
