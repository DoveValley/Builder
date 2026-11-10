<?php
require_once __DIR__ . '/box_assignment.php'; // ms_strict_box_number_for_position()

/**
 * Box-aware registrar assignment — which registrar a row at a given position gets,
 * under strict positional box assignment (includes/multisite/box_assignment.php).
 *
 * WHY THIS EXISTS, AND WHY IT ISN'T JUST A ROTATION OF THE OLD SCHEDULE. The
 * original per-row registrar spread (committed 33022757) ran a weighted
 * round-robin independently per batch, oblivious to box. Once box became a pure
 * function of position (period 20), box and registrar turned out to share real
 * structure: with 6 registrars and 20 boxes, gcd(20,6)=2, so stepping through a
 * registrar's natural 6-cycle in strides of 20 only ever visits 20 mod 6 = 2 of
 * its 6 phases — meaning a given box could land on only 3 of the 6 registrars no
 * matter WHERE the schedule started. A per-batch rotation offset (tried first)
 * only changes WHICH 3 — confirmed by testing all 6 possible offsets: they split
 * into exactly 2 outcomes by parity, so 4 batches using offsets 0-3 produced two
 * identical pairs (b4≈b6, b5≈b7), not 4 distinct outcomes.
 *
 * THE FIX: assign registrar directly from (box, round, batch) instead of
 * sampling a shared position-indexed sequence that happens to have a period
 * resonating with 20. One formula:
 *
 *     registrar = registrars[ (box + round + batchSeq) mod 6 ]
 *
 * For a box's 5 rounds (0-4 in a 100-row batch), this walks 5 CONSECUTIVE
 * residues out of a 6-cycle — so a box misses exactly ONE registrar per batch,
 * never three, and the batch's own sequence number (ms_batch_meta()'s `seq`,
 * assigned automatically when the batch is created — no number to track by
 * hand) shifts which one. Verified against appliance's real b4-b7 (seq 34-37):
 * 0 of 80 box x account islands share a single registrar (was 20/80), and every
 * box misses a DIFFERENT registrar in every one of the 4 batches (was: the same
 * 3, identically, in all 4).
 */

/**
 * Which registrar a row at this 1-indexed position gets.
 *
 * @param string[] $registrars in a fixed, stable order (e.g.
 *   infra_registrar_buyable_for_new_purchases()) — which order doesn't matter
 *   for fairness, only that every caller uses the SAME order, so the formula
 *   reproduces identically whenever it's run again.
 * @param int $batchSeq this batch's own sequence number (ms_batch_meta()'s
 *   `seq`) — the seed. Using the batch's real, already-assigned number means
 *   there is nothing to remember or type: the next batch automatically gets a
 *   different seed just by being created later.
 */
function ms_registrar_for_position(array $registrars, int $position, int $batchSeq): string
{
    $n = count($registrars);
    if ($n === 0) return '';
    $box   = ms_strict_box_number_for_position($position);
    $round = intdiv(max(1, $position) - 1, 20);
    $idx = (($box + $round + $batchSeq) % $n + $n) % $n;
    return $registrars[$idx];
}
