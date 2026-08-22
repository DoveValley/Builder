<?php
/**
 * infra/lib/golive.php — Phase-3 go-live orchestration.
 * Lifecycle: staged → queued (scheduled) → releasing/awaiting-ns → live.
 * "Going live" = switching the domain's nameservers at the registrar to the
 * Cloudflare pair; Cloudflare then flips the zone to active, which we detect.
 */
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/registrar.php';
require_once __DIR__ . '/fleet.php';   // infra_cf_zone_index() (cached)

/**
 * Refresh live status from Cloudflare (zone active ⇒ live). Uses the cached CF
 * zone index (one sweep across accounts) instead of a call per domain, so it
 * scales to thousands of domains. Callers that need truly-live data
 * (cron, the Refresh button) set infra_cache_force() first.
 * @return int number newly marked live
 */
function infra_golive_refresh_live(): int
{
    require_once __DIR__ . '/uptime.php';
    require_once __DIR__ . '/pipeline.php';

    $idx = infra_cf_zone_index();
    $n = 0;
    foreach (infra_state_all_domains() as $dom => $rec) {
        $status = (string) ($rec['status'] ?? '');
        if ($status === 'live') continue;

        // ONLY DOMAINS WE ACTUALLY RELEASED are candidates. This used to consider every
        // domain in the fleet, which mislabelled 31 of them in a single run: a domain
        // registered AT Cloudflare has an active zone from the moment it is bought, so
        // "zone active" fired for names that had never been staged, hosted or pointed
        // anywhere. Nothing that has not reached staging can be live.
        if (!in_array($status, ['staged', 'queued', 'releasing', 'awaiting-ns'], true)) continue;

        $z = $idx[$dom] ?? null;
        if (!$z || ($z['status'] ?? '') !== 'active') continue;

        // ...and an active zone is still not evidence. It means Cloudflare answers for
        // the NAME; it says nothing about whether there is a record in it or a site
        // behind that. All 31 of the mislabelled ones answered "could not resolve host"
        // at the same moment they were being written down as live. So ask the site.
        $chk = infra_site_check_run($dom);
        if (empty($chk['up']) || empty($chk['cert_ok'])) {
            // Answering behind a broken certificate is not live either — that is a
            // browser warning for every visitor. Record what was seen and move on.
            infra_pipeline_set($dom, 'live', INFRA_STEP_TODO, infra_site_verdict($chk));
            continue;
        }

        infra_state_upsert_domain(['domain' => $dom, 'status' => 'live']);
        infra_pipeline_set($dom, 'live', INFRA_STEP_OK, 'HTTP ' . $chk['code'] . ' · ' . $chk['ms'] . 'ms');
        $n++;
    }
    return $n;
}

/**
 * Schedule not-yet-live domains into daily batches.
 *
 * @param array $opts batch — only domains carrying this tag (default: the whole fleet)
 *                    gate  — skip anything that would be refused at release time, and
 *                            say how many were skipped rather than scheduling a date
 *                            that is going to bounce
 * @return array{scheduled:int, skipped:int, first:string, last:string}
 */
function infra_golive_schedule(int $perDay, string $startDate, array $opts = []): array
{
    $perDay = max(1, $perDay);
    $batch  = (string) ($opts['batch'] ?? '');
    $gate   = !empty($opts['gate']);

    // Only domains that are actually ready to go live — not live, and not stuck in
    // 'partial'/'register-failed'. (Re-scheduling 'queued' is fine.)
    $rows = array_filter(infra_state_all_domains(), function ($r) use ($batch) {
        if (!in_array($r['status'] ?? '', ['staged', 'queued'], true)) return false;
        return $batch === '' || (string) ($r['batch'] ?? '') === $batch;
    });
    ksort($rows);

    $start = strtotime($startDate ?: infra_today());
    if ($start === false) $start = time();

    $i = 0; $skipped = 0; $first = ''; $last = '';
    foreach ($rows as $dom => $r) {
        // Scheduling something the gate will refuse just moves the failure to a date
        // when nobody is watching. Skip it now and report the number.
        if ($gate && !infra_golive_gate($dom)['ok']) { $skipped++; continue; }

        $date = date('Y-m-d', $start + intdiv($i, $perDay) * 86400);
        infra_state_upsert_domain(['domain' => $dom, 'go_live_at' => $date, 'status' => 'queued']);
        if ($first === '') $first = $date;
        $last = $date;
        $i++;
    }
    return ['scheduled' => $i, 'skipped' => $skipped, 'first' => $first, 'last' => $last];
}

/** Domains scheduled on/before $date and not yet live. @return array domain=>record */
function infra_golive_due(?string $date = null): array
{
    $date = $date ?: gmdate('Y-m-d');
    $due = [];
    foreach (infra_state_all_domains() as $dom => $r) {
        if (($r['status'] ?? '') === 'live') continue;
        $gla = $r['go_live_at'] ?? '';
        if ($gla !== '' && $gla <= $date) $due[$dom] = $r;
    }
    return $due;
}

/**
 * May this domain be released? Reads the go-live grid's Upload cell.
 *
 * Three answers, not two: uploaded (go), checked and empty (stop), and never checked
 * (stop, but for a different reason and with a different cure). Collapsing the last
 * two into "no" would send someone hunting for a fault when all that is missing is a
 * click on Refresh.
 *
 * @return array{ok:bool, why:string}
 */
function infra_golive_gate(string $domain): array
{
    require_once __DIR__ . '/pipeline.php';
    $cells = infra_pipeline_stored([$domain])[strtolower(trim($domain))] ?? [];
    $up    = $cells['upload'] ?? null;

    if ($up === null) {
        return ['ok' => false, 'why' => 'nobody has checked whether a site was uploaded to this domain — '
                                      . 'press Refresh on the Upload column first, or override deliberately'];
    }
    if (($up['state'] ?? '') !== INFRA_STEP_OK) {
        return ['ok' => false, 'why' => 'nothing is uploaded yet' . (($up['note'] ?? '') !== '' ? ' (' . $up['note'] . ')' : '')
                                      . ' — pointing DNS at it now means Google crawls an empty site'];
    }
    return ['ok' => true, 'why' => ''];
}

/**
 * Release one domain: switch nameservers at the registrar (or surface the manual
 * step), and mark its status. Live is confirmed later via infra_golive_refresh_live().
 * @return array{ok:bool, manual:bool, message:string, ns:array}
 */
function infra_golive_release(string $domain, bool $force = false): array
{
    $rec = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'manual' => false, 'message' => 'not in fleet state', 'ns' => []];

    // ── THE GATE ──────────────────────────────────────────────────────────────
    // Never let a rank-and-rent domain resolve while it is empty. Until now this
    // function checked only that the domain was tracked and had a nameserver pair,
    // and would happily point DNS at a folder holding nothing but Hestia's
    // placeholder — the first impression "empty site" that is expensive to undo.
    //
    // The go-live grid is the only thing that knows whether a site was actually
    // uploaded, so the check lives against its checkpoint. A domain nobody has
    // CHECKED is refused too: not knowing is not the same as knowing it is fine,
    // and the cure is one click on the Upload column's Refresh.
    //
    // $force exists because a deliberate override is a real need — but it has to be
    // deliberate, not the default path.
    if (!$force) {
        $gate = infra_golive_gate($domain);
        if (!$gate['ok']) {
            return ['ok' => false, 'manual' => false, 'gated' => true, 'message' => $gate['why'], 'ns' => []];
        }
    }

    $ns = array_values(array_filter(array_map('trim', explode(',', $rec['nameservers'] ?? ''))));
    if (!$ns) return ['ok' => false, 'manual' => false, 'message' => 'no Cloudflare nameservers on record — stage it first', 'ns' => []];

    $sw = infra_registrar_set_ns($domain, $ns, $rec['registrar'] ?? '');
    infra_state_upsert_domain([
        'domain'     => $domain,
        'status'     => $sw['ok'] ? 'releasing' : 'awaiting-ns',
        'go_live_at' => $rec['go_live_at'] ?: gmdate('Y-m-d'),
    ]);
    return ['ok' => $sw['ok'], 'manual' => !empty($sw['manual']), 'message' => $sw['message'], 'ns' => $ns];
}

/**
 * Take a live (or staged) domain offline WITHOUT reverting its nameservers.
 *
 * Deliberately not "undo golive" by reverting NS at the registrar — that would take
 * the domain's nameservers out of Cloudflare, and reverting them back later has to
 * propagate across the whole internet again (minutes to hours). Instead this removes
 * the A record from the Cloudflare zone: Cloudflare is already authoritative for the
 * domain once NS are switched, so the hostname stops resolving in seconds, and going
 * back live is just re-running the zone step, which recreates the exact same record.
 *
 * This un-does the `zone` step's cell specifically (its check requires a matching A
 * record — see infra_pipeline_refresh('zone')), not a new pipeline step: there is
 * nothing else to track, since `golive`/`dns` describe the nameserver switch, which
 * this does not touch.
 *
 * @return array{ok:bool, message:string}
 */
function infra_golive_take_offline(string $domain): array
{
    require_once __DIR__ . '/pipeline.php';

    $domain = strtolower(trim($domain));
    $rec    = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'message' => 'not in fleet state'];

    infra_cache_force(true);
    $z = infra_cf_zone_index()[$domain] ?? null;
    if (!$z) { infra_cache_force(false); return ['ok' => false, 'message' => 'no Cloudflare zone on record for this domain']; }

    $acct = null;
    foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === $z['account_id']) $acct = $a;
    if (!$acct) { infra_cache_force(false); return ['ok' => false, 'message' => 'zone is in an account the console no longer has']; }

    $r = cf_delete_dns_record($acct, (string) $z['zone_id'], 'A', $domain);

    // THE CHECK HAS THE LAST WORD, same rule as every pipeline action: the zone (and
    // live) cells are re-derived from what Cloudflare/the site actually report now,
    // not from what this call claims.
    infra_pipeline_refresh('zone', '', $domain);
    infra_pipeline_refresh('live', '', $domain);
    infra_cache_force(false);

    return $r;
}
