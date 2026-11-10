<?php
/**
 * Strict positional box/Cloudflare-account assignment — an alternative to Create
 * Host's default randomized round-robin (multisite/create_hosts.php).
 *
 * Saved per BATCH in sites/{master}/batches/{batch}/box_assignment.json via
 * ms_batch_file_write()/ms_batch_file_read() (includes/multisite/batch.php), same
 * scope and mechanism as section_rotation.json.
 *
 * The formulas here are the single source of truth for "row position -> box number"
 * and "row position -> which of that box's bound Cloudflare accounts" — used both by
 * the Target List's "Auto-fill" button (admin/multisite_api.php's box_autofill
 * action, which WRITES box/cf_account onto every row) and documented here so the two
 * never drift. Create Host itself does NOT recompute these: in strict mode it just
 * reads each row's own stored `box` column, the same way it already reads `registrar`
 * for Buy Domains — pre-assign once, trust the column after that.
 */

/** Default: strict positional mode ON for any batch that has never saved a choice. */
function ms_box_assignment_defaults(): array
{
    return ['strict_mode' => true];
}

/** Normalize to a real boolean, filling in the default when unset/invalid. */
function ms_box_assignment_settings(array $raw): array
{
    $d = ms_box_assignment_defaults();
    return [
        'strict_mode' => array_key_exists('strict_mode', $raw)
            ? filter_var($raw['strict_mode'], FILTER_VALIDATE_BOOLEAN)
            : $d['strict_mode'],
    ];
}

/**
 * Which box (1-20) a row at this 1-indexed position gets, under strict positional
 * assignment: box 1-20, then 1-20 again, for as many rows as the batch has.
 */
function ms_strict_box_number_for_position(int $position): int
{
    $position = max(1, $position);
    return (($position - 1) % 20) + 1;
}

/**
 * Which of that box's bound Cloudflare accounts a row at this position should
 * pre-set, as the SAME 0-indexed number the account's own label uses (box N's
 * ".0" account, ".1", ".2", ".3") — the `cf_account` column stores exactly what's
 * printed on the Cloudflare tab, not a slot number. Convert to
 * infra_cf_account_at_slot()'s 1-indexed `slot` param (+1) only at the point of
 * actually calling it, in multisite/create_hosts.php.
 *
 * Only the first 4 rounds (positions 1-80 of each 20-box cycle) have a pre-built
 * account to assign, since only 4 Cloudflare accounts are bound per box today.
 * Past that, $overflow decides: null (the default — nothing typed into the
 * Auto-fill form's overflow field) leaves it genuinely undetermined, same
 * reasoning as a row with no registrar assigned and no override being skipped
 * and reported rather than silently placed somewhere. A human can instead name
 * one of the 4 real accounts (0-3) to reuse for every row past position 80 —
 * that account then simply carries a larger share of this round's zones; it
 * does not put two boxes' domains in one account, so it never breaks isolation.
 */
function ms_strict_cf_account_for_position(int $position, ?int $overflow = null): ?int
{
    $position = max(1, $position);
    $round = intdiv($position - 1, 20);
    return $round <= 3 ? $round : $overflow;
}
