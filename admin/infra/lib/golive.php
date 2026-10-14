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
require_once __DIR__ . '/golive_freshen.php';

/**
 * Refresh live status from Cloudflare (zone active ⇒ live). Uses the cached CF
 * zone index (one sweep across accounts) instead of a call per domain, so it
 * scales to thousands of domains. Callers that need truly-live data
 * (cron, the Refresh button) set infra_cache_force() first.
 *
 * @param string[] $only Check just these domains instead of the whole fleet — for a
 *   caller that just released a handful of domains and wants to know if THOSE went
 *   live, not a full sweep. Omitted/empty means the original "check everything"
 *   behavior cron relies on; the zone index itself still covers every account either
 *   way (that part's already one sweep regardless of scope), only the per-domain
 *   loop — and its per-domain live HTTP check — is bounded by $only.
 * @return int number newly marked live
 */
function infra_golive_refresh_live(array $only = []): int
{
    require_once __DIR__ . '/uptime.php';
    require_once __DIR__ . '/pipeline.php';

    $idx = infra_cf_zone_index();
    $n = 0;
    $recs = [];
    if ($only) {
        foreach ($only as $d) { $r = infra_state_get_domain($d); if ($r) $recs[strtolower(trim($d))] = $r; }
    } else {
        $recs = infra_state_all_domains();
    }
    foreach ($recs as $dom => $rec) {
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

        // Right here, not at release: this is the moment the domain is actually
        // resolving and serving, which is what its files' dates should honestly
        // reflect. Release and "actually live" can be hours apart (NS propagation),
        // so freshening at release would sometimes touch files before anyone could
        // even reach them. Best-effort — a failure here does not undo "live", since
        // the site genuinely is live; it just means the footprint fix didn't land
        // and can be retried by hand.
        $fresh = infra_golive_freshen_domain($dom);
        $note = 'HTTP ' . $chk['code'] . ' · ' . $chk['ms'] . 'ms';
        if (!$fresh['skipped']) $note .= ' · freshen: ' . $fresh['message'];
        infra_pipeline_set($dom, 'live', INFRA_STEP_OK, $note);
        $n++;
    }
    return $n;
}

/**
 * Schedule not-yet-live domains into daily batches.
 *
 * @param array $opts batch    — only domains carrying this tag (default: the whole fleet)
 *                    gate     — skip anything that would be refused at release time, and
 *                               say how many were skipped rather than scheduling a date
 *                               that is going to bounce
 *                    domains  — schedule ONLY these (the Rollout page's ticked rows);
 *                               any that can't be scheduled come back in `why`
 *                    time     — 'HH:MM' (INFRA_TZ) written to go_live_time; omitted =
 *                               leave go_live_time as it is (the original behaviour)
 *                    continue — count days already holding this batch's OTHER scheduled
 *                               domains toward $perDay, so scheduling more later
 *                               carries on after the existing plan instead of
 *                               doubling up a day
 * With only batch/gate set this behaves exactly as it always did.
 * @return array{scheduled:int, skipped:int, first:string, last:string, why:array}
 */
function infra_golive_schedule(int $perDay, string $startDate, array $opts = []): array
{
    $perDay = max(1, $perDay);
    $batch  = (string) ($opts['batch'] ?? '');
    $gate   = !empty($opts['gate']);
    $only   = null;
    if (isset($opts['domains'])) {
        $only = [];
        foreach ((array) $opts['domains'] as $d) { $d = strtolower(trim((string) $d)); if ($d !== '') $only[$d] = true; }
    }
    $time = trim((string) ($opts['time'] ?? ''));
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '';

    // Spread a day's domains across the clock instead of firing them all at once:
    // the Nth domain scheduled for a given day goes live $interval minutes after the
    // (N-1)th. Every day restarts from the same $startTime. Blank start_time leaves
    // the old flat-$time-for-everyone behavior untouched.
    $startTime = trim((string) ($opts['start_time'] ?? ''));
    if ($startTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) $startTime = '';
    $interval = max(0, (int) ($opts['interval'] ?? 0));

    // Only domains that are actually ready to go live — not live, and not stuck in
    // 'partial'/'register-failed'. (Re-scheduling 'queued' is fine.)
    $all  = infra_state_all_domains();
    $why  = [];
    $rows = [];
    if ($only !== null) {
        // Preserve the CALLER's order instead of re-sorting alphabetically. For Rollout's
        // Simple view, $opts['domains'] arrives in the batch's own params.csv row order
        // (the same order create_hosts.php already treats as the intended go-live
        // sequence) — alphabetizing it here silently scrambled "day one, two, three" into
        // whatever order domain NAMES happened to sort in, with nothing on screen to
        // explain why row 9 landed on a later day than row 10.
        foreach (array_keys($only) as $dom) {
            $r = $all[$dom] ?? null;
            if ($r === null) { $why[] = [$dom, 'not in the fleet']; continue; }
            if ($batch !== '' && (string) ($r['batch'] ?? '') !== $batch) { $why[] = [$dom, 'not in this batch']; continue; }
            if (!in_array($r['status'] ?? '', ['staged', 'queued'], true)) { $why[] = [$dom, 'status is ' . (($r['status'] ?? '') ?: 'blank')]; continue; }
            $rows[$dom] = $r;
        }
    } else {
        // No explicit list (a hypothetical "schedule the whole batch" call with no
        // domains given) — nothing to preserve the order OF, so alphabetical stays the
        // deterministic fallback it always was.
        foreach ($all as $dom => $r) {
            if ($batch !== '' && (string) ($r['batch'] ?? '') !== $batch) continue;
            if (!in_array($r['status'] ?? '', ['staged', 'queued'], true)) continue;
            $rows[$dom] = $r;
        }
        ksort($rows);
    }

    $start = strtotime($startDate ?: infra_today());
    if ($start === false) $start = time();
    $day = date('Y-m-d', $start);

    $load = [];
    if (!empty($opts['continue'])) {
        foreach ($all as $dom => $r) {
            if (isset($rows[$dom])) continue;                       // being (re)scheduled now
            if ($batch !== '' && (string) ($r['batch'] ?? '') !== $batch) continue;
            $gla = (string) ($r['go_live_at'] ?? '');
            if (($r['status'] ?? '') === 'queued' && $gla !== '') $load[$gla] = ($load[$gla] ?? 0) + 1;
        }
    }

    $i = 0; $skipped = 0; $first = ''; $last = '';
    foreach ($rows as $dom => $r) {
        // Scheduling something the gate will refuse just moves the failure to a date
        // when nobody is watching. Skip it now and report the number.
        if ($gate && !infra_golive_gate($dom)['ok']) { $skipped++; $why[] = [$dom, 'not uploaded yet']; continue; }

        while (($load[$day] ?? 0) >= $perDay) $day = date('Y-m-d', strtotime($day . ' +1 day'));
        $up = ['domain' => $dom, 'go_live_at' => $day, 'status' => 'queued'];
        if ($startTime !== '') {
            $up['go_live_time'] = date('H:i', strtotime($startTime) + ($load[$day] ?? 0) * $interval * 60);
        } elseif ($time !== '') {
            $up['go_live_time'] = $time;
        }
        infra_state_upsert_domain($up);
        $load[$day] = ($load[$day] ?? 0) + 1;
        if ($first === '') $first = $day;
        $last = $day;
        $i++;
    }
    return ['scheduled' => $i, 'skipped' => $skipped, 'first' => $first, 'last' => $last, 'why' => $why];
}

/**
 * Take domains off the calendar: queued → staged, date and time cleared. Only 'queued' —
 * a domain already switching DNS or live is past the point a date can undo.
 * @param string $batch only touch domains carrying this tag ('' = any)
 * @return array{unscheduled:int, why:array}
 */
function infra_golive_unschedule(array $domains, string $batch = ''): array
{
    $out = ['unscheduled' => 0, 'why' => []];
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        $r = $d !== '' ? infra_state_get_domain($d) : null;
        if (!$r) { $out['why'][] = [$d, 'not in the fleet']; continue; }
        if ($batch !== '' && (string) ($r['batch'] ?? '') !== $batch) { $out['why'][] = [$d, 'not in this batch']; continue; }
        if (($r['status'] ?? '') !== 'queued') { $out['why'][] = [$d, 'not scheduled']; continue; }
        infra_state_upsert_domain(['domain' => $d, 'go_live_at' => '', 'go_live_time' => '', 'status' => 'staged']);
        $out['unscheduled']++;
    }
    return $out;
}

/**
 * Domains scheduled on/before $now's day (and, for the day they're scheduled on, at
 * or past $now's time-of-day) and not yet live.
 *
 * go_live_time is optional per domain — blank means "any time that day" (the
 * original, only behaviour before go_live_time existed), so a domain scheduled by
 * date alone is due the instant its day starts, same as always. A domain that also
 * carries a time is due only once that clock time has passed ON its scheduled day —
 * a future day with a time set is not due early just because the time looks past
 * relative to today, and a past day is due regardless of time (never got released,
 * still overdue).
 *
 * $now is a full 'Y-m-d H:i(:s)?' string in INFRA_TZ (infra_now()'s own format) — NOT
 * just a date — so a caller wanting the old date-only semantics can still get it by
 * passing e.g. "$date 23:59".
 *
 * @return array domain=>record
 */
function infra_golive_due(?string $now = null): array
{
    $now     = $now ?: infra_now();
    $nowDate = substr($now, 0, 10);
    $nowTime = substr($now, 11, 5); // 'H:i', empty string if $now had no time part
    $due = [];
    foreach (infra_state_all_domains() as $dom => $r) {
        if (($r['status'] ?? '') === 'live') continue;
        $gla = (string) ($r['go_live_at'] ?? '');
        if ($gla === '' || $gla > $nowDate) continue;
        if ($gla === $nowDate) {
            $glt = trim((string) ($r['go_live_time'] ?? ''));
            if ($glt !== '' && $nowTime !== '' && $glt > $nowTime) continue;
        }
        $due[$dom] = $r;
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

    // Recordkeeping only, not a rollback mechanism: capture whatever nameservers this
    // domain answers with RIGHT NOW, one call before we ever switch them away. Only on
    // the first switch — once pre_golive_ns is set, a later re-run of Go Live would see
    // Cloudflare's OWN nameservers here (since that's what DNS now resolves), and
    // writing that over the real original would destroy the one thing worth keeping.
    if (($rec['pre_golive_ns'] ?? '') === '') {
        $before = @dns_get_record($domain, DNS_NS);
        if ($before) {
            $preNs = array_values(array_unique(array_filter(array_map(
                fn($r) => strtolower(rtrim($r['target'] ?? '', '.')), $before
            ))));
            if ($preNs) {
                infra_state_upsert_domain(['domain' => $domain, 'pre_golive_ns' => implode(',', $preNs)]);
            }
        }
    }

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
