<?php
/**
 * infra/lib/claim.php — the one bridge between D.Buy (fleet.db) and a Batch's own
 * target list (params.csv).
 *
 * "Claim for Batch" is a GATE, not a copy: a domain only ever lands in a batch's
 * params.csv after passing three checks, in order — tracked in D.Buy, actually
 * owned, and not already claimed by a DIFFERENT batch. No ad-hoc/untracked domain
 * gets in this way, on purpose (the old, looser path — Create host silently
 * adopting whatever domain name showed up in a hand-typed target list — still
 * works for a batch built the old way, but this is the tight, D.Buy-first door).
 *
 * fleet.db's `batch` column is shared with the rest of the console (Create host
 * writes it too, as a side effect of provisioning) — this just writes it
 * deliberately, checked, and earlier: the moment a domain is claimed, not the
 * moment its host happens to get created.
 */
require_once __DIR__ . '/state.php';
require_once BASE_DIR . '/includes/multisite/batch.php';
require_once BASE_DIR . '/includes/multisite/params.php';

/** Every batch across every master, for a "claim into which one" picker. */
function infra_claimable_batches(): array
{
    return ms_all_batches();
}

/**
 * Claim one domain for one batch: tags fleet.db's `batch` column and appends a row
 * to that batch's params.csv (domain only — business/city/state stay blank, filled
 * in by hand on the batch page same as any hand-typed target-list row).
 *
 * @return array{ok:bool,reason:string}
 */
function infra_claim_for_batch(string $domain, string $masterId, string $batchId): array
{
    $domain = strtolower(trim($domain));
    if (!ms_batch_exists($masterId, $batchId)) return ['ok' => false, 'reason' => 'no such batch'];

    $rec = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'reason' => 'not tracked in D.Buy'];
    if (($rec['owned'] ?? '') !== 'yes') return ['ok' => false, 'reason' => 'not owned yet'];

    $target   = $masterId . '/' . $batchId;
    $existing = trim((string) ($rec['batch'] ?? ''));
    if ($existing !== '' && $existing !== $target) {
        return ['ok' => false, 'reason' => "already claimed by {$existing}"];
    }

    $csvPath = ms_batch_dir($masterId, $batchId) . '/params.csv';
    $parsed  = is_file($csvPath) ? ms_parse_csv($csvPath) : ['header' => [], 'rows' => []];
    $header  = $parsed['header'] ?: MS_KNOWN_COLS;
    $rows    = $parsed['rows'];

    foreach ($rows as $r) {
        if (strtolower(trim((string) ($r['domain'] ?? ''))) === $domain) {
            // Already a row in this batch's own target list — the tag is all that
            // was missing (e.g. a domain typed into the CSV by hand before this
            // existed). Nothing to append, just bring fleet.db into agreement.
            infra_state_upsert_domain(['domain' => $domain, 'batch' => $target]);
            return ['ok' => true, 'reason' => "already in {$target}'s target list"];
        }
    }

    $rows[] = ['domain' => $domain];
    if (!ms_write_csv($csvPath, $header, $rows)) {
        return ['ok' => false, 'reason' => 'could not write params.csv'];
    }

    infra_state_upsert_domain(['domain' => $domain, 'batch' => $target]);
    return ['ok' => true, 'reason' => 'claimed'];
}

/**
 * Remove one domain from its batch's params.csv row and clear fleet.db's `batch`
 * tag — the reverse of infra_claim_for_batch(), same bridge, so the Danger Zone's
 * teardown action doesn't leave a domain "claimed" by a batch that no longer has
 * any record it was ever tracking it. Without this, a domain re-claimed into the
 * SAME batch after a teardown would silently reuse its old, stale business-data row
 * instead of starting clean.
 *
 * Never fails on "nothing to remove" — a domain torn down before ever being
 * claimed, or whose batch tag already points nowhere real, is not an error case;
 * teardown calls this unconditionally on every domain.
 *
 * @return array{ok:bool,reason:string}
 */
function infra_unclaim_from_batch(string $domain): array
{
    $domain = strtolower(trim($domain));
    $rec    = infra_state_get_domain($domain);
    $target = trim((string) ($rec['batch'] ?? ''));
    if ($target === '') return ['ok' => true, 'reason' => 'was not claimed by any batch'];

    [$masterId, $batchId] = array_pad(explode('/', $target, 2), 2, '');
    if ($masterId === '' || $batchId === '' || !ms_batch_exists($masterId, $batchId)) {
        infra_state_upsert_domain(['domain' => $domain, 'batch' => '']);
        return ['ok' => true, 'reason' => "batch tag '{$target}' cleared (that batch no longer exists)"];
    }

    $csvPath = ms_batch_dir($masterId, $batchId) . '/params.csv';
    if (!is_file($csvPath)) {
        infra_state_upsert_domain(['domain' => $domain, 'batch' => '']);
        return ['ok' => true, 'reason' => "batch tag cleared ({$target} has no target list)"];
    }

    $parsed = ms_parse_csv($csvPath);
    $before = count($parsed['rows']);
    $rows   = array_values(array_filter(
        $parsed['rows'],
        fn($r) => strtolower(trim((string) ($r['domain'] ?? ''))) !== $domain
    ));

    if (count($rows) === $before) {
        // Tagged in fleet.db but the row itself is already gone from the CSV
        // (e.g. edited out by hand) — still clear the now-orphaned tag.
        infra_state_upsert_domain(['domain' => $domain, 'batch' => '']);
        return ['ok' => true, 'reason' => "not in {$target}'s target list — stale tag cleared"];
    }

    if (!ms_write_csv($csvPath, $parsed['header'] ?: MS_KNOWN_COLS, $rows)) {
        return ['ok' => false, 'reason' => 'could not write params.csv'];
    }

    infra_state_upsert_domain(['domain' => $domain, 'batch' => '']);
    return ['ok' => true, 'reason' => "removed from {$target}'s target list"];
}
