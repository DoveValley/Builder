<?php
/**
 * infra/lib/fleet_view.php — read-only status table for a list of domains.
 *
 * One row per domain: its box, its Cloudflare account, and the stored pipeline answer for
 * each major step (ok / todo / fail / running, or '' when nobody has looked yet) — the
 * same cells the Bulk grid and the Batch go-live card read. Plus the two facts a page
 * needs to offer scheduling: go-live date/time and `ready` (the release gate passes and
 * the status can still be scheduled). No writes, no external calls.
 */
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/hestia_fleet.php';

/** "CF #1b - someone@mail" → "CF #1b": the name, without the contact detail. */
function infra_fleet_view_short(string $label): string
{
    $cut = strpos($label, ' - ');
    return trim($cut !== false ? substr($label, 0, $cut) : $label);
}

/**
 * @param string[] $domains in the order the page wants them shown
 * @return array<int,array>
 */
function infra_fleet_view_rows(array $domains): array
{
    $doms = [];
    foreach ($domains as $d) { $d = strtolower(trim((string) $d)); if ($d !== '') $doms[$d] = true; }
    if (!$doms) return [];
    $recs   = infra_state_all_domains();
    $stored = infra_pipeline_stored(array_keys($doms));

    $boxes = [];
    foreach (infra_hestia_servers() as $s) $boxes[(string) ($s['id'] ?? '')] = (string) ($s['label'] ?? $s['host'] ?? '');
    $accts = [];
    foreach (infra_cf_accounts() as $a) $accts[(string) ($a['id'] ?? '')] = infra_fleet_view_short((string) ($a['label'] ?? $a['id'] ?? ''));

    $rows = [];
    foreach (array_keys($doms) as $d) {
        $rec    = $recs[$d] ?? [];
        $cells  = $stored[$d] ?? [];
        $st     = fn(string $k) => (string) ($cells[$k]['state'] ?? '');
        $status = (string) ($rec['status'] ?? '');
        $sid    = (string) ($rec['server_id'] ?? '');
        $aid    = (string) ($rec['cf_account_id'] ?? '');
        $liveState = $status === 'live' ? INFRA_STEP_OK : $st('live');
        // Per-step note + last-checked time, straight from the same stored cells the
        // dots already summarize into one word — for a per-row "what actually
        // happened" popup, not a new column (the dots stay as the at-a-glance view).
        $detail = [];
        foreach (['host', 'built', 'upload', 'zone', 'live'] as $k) {
            $c = $cells[$k] ?? [];
            $detail[$k] = ['state' => $k === 'live' ? $liveState : $st($k),
                           'note'  => (string) ($c['note'] ?? ''), 'at' => (int) ($c['at'] ?? 0)];
        }
        $rows[] = [
            'domain'       => $d,
            'status'       => $status,
            'batch'        => (string) ($rec['batch'] ?? ''),
            'box'          => $sid !== '' ? ($boxes[$sid] ?? $sid) : '',
            'cf_account'   => $aid !== '' ? ($accts[$aid] ?? $aid) : '',
            'steps'        => ['host' => $st('host'), 'built' => $st('built'), 'upload' => $st('upload'), 'zone' => $st('zone'),
                               'live' => $liveState],
            'detail'       => $detail,
            'go_live_at'   => (string) ($rec['go_live_at'] ?? ''),
            'go_live_time' => (string) ($rec['go_live_time'] ?? ''),
            // Same rule the release gate and infra_golive_schedule() apply.
            'ready'        => $st('upload') === INFRA_STEP_OK && in_array($status, ['staged', 'queued'], true),
        ];
    }
    return $rows;
}
