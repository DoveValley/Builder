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
