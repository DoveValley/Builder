// Shared "are we still reaching the server" tracker for detached-job polling
// (batch runs, research runs, CTM bulk jobs, area-code jobs, regen/queued builds).
// Each independent poll loop keeps its own tracker and calls .miss() from that
// loop's own error handler, .reset() on every successful fetch. Centralizes the
// one piece of logic that a fleet-wide audit (2026-11-02) found duplicated
// correctly in 2 of 8 polling loops across admin/batch.php and admin/rollout.php,
// and missing or silently swallowed in the other 6 — in two of those six
// (rollout.php's watchJob/watchResearch) a single network hiccup threw out of an
// un-caught async function and killed the polling loop permanently, with the
// progress UI left stuck mid-run and no error ever shown.
function msPollMissTracker(onLostContact, threshold) {
    threshold = threshold || 5;
    var misses = 0;
    return {
        miss: function () {
            misses++;
            if (misses >= threshold) onLostContact();
        },
        reset: function () {
            misses = 0;
        },
    };
}
