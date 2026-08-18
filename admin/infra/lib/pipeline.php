<?php
/**
 * infra/lib/pipeline.php — the steps a domain goes through on its way to live,
 * and where each one currently stands.
 *
 * WHY THIS EXISTS. Bulk Provision streams its progress into a <pre> that vanishes the
 * moment you navigate away. After a fifty-domain run there is no per-domain record of
 * what happened: the fleet table knows a box was assigned and a zone exists, the batch
 * run JSON knows a site was generated and uploaded, and nothing joins them. A run that
 * dies on domain 37 leaves 36 done, 13 untouched and no durable way to tell which.
 *
 * THE SHAPE. One row per domain, one column per step, and the stored answer for each
 * cell IS the checkpoint. There is no run cursor to lose — "resume" means re-running
 * the cells that are not green, and that is derivable from the table at any moment.
 *
 * WHY A NARROW TABLE, not one column per step. Adding a ninth step later is a row, not
 * an ALTER TABLE plus edits in four files. It also gives every cell its own note,
 * timestamp and attempt count for free, which a wide row cannot without nine more
 * columns each.
 *
 * THE STEP ARRAY IS THE PIPELINE. The grid's columns, the per-column Refresh and (from
 * stage 4) the click-to-act buttons all render from infra_pipeline_steps(). A step
 * added there appears everywhere at once; that is the point, and it is the same
 * arrangement ms_run_steps() already uses for the multisite build phases.
 *
 * WHAT IS NOT HERE YET (by stage, deliberately):
 *   stage 2 — `check`: a callable per step that goes and looks, and writes the answer.
 *   stage 3 — the go-live column becomes editable: date, button, upload gate, cron.
 *   stage 4 — `do`: a callable per step that performs it, idempotent, with `check`
 *             having the last word afterwards.
 * Stage 1 (this file, today) defines the steps and READS state. It writes nothing.
 */

require_once __DIR__ . '/state.php';

/**
 * Cell states. "Never checked" is deliberately NOT one of them — it is the ABSENCE of
 * a row in domain_step, which is a different fact from any of these and must look
 * different on screen. A console that renders "not asked yet" as a failure invents
 * problems out of missing information; this one has made that mistake before.
 */
const INFRA_STEP_OK      = 'ok';        // went and looked; it is done
const INFRA_STEP_TODO    = 'todo';      // went and looked; not done yet, and that is fine
const INFRA_STEP_FAIL    = 'fail';      // tried; it broke. `note` says how.
const INFRA_STEP_RUNNING = 'running';   // in flight — stage 4 uses this to stop double-clicks

/**
 * The steps, in the order they actually happen.
 *
 * `requires` is what must be green before this step can run. It is what makes resume
 * automatic: the next runnable step for any row is derived from what is green right
 * now, so nothing has to remember where a broken run stopped.
 *
 * Two things about the order, both learned from how the code really behaves:
 *
 *  - The Cloudflare zone is created EARLY, alongside the host — bulk provisioning
 *    stages both together. Only the nameserver switch happens at the end. Putting the
 *    zone late would draw 31 domains as barely started when they are nearly done.
 *  - `golive` (schedule / press the button) is separate from `dns` (the nameservers
 *    are actually switched) and from `live` (it answers, with a certificate that
 *    matches). Those fail independently, and a site serving fine behind a bad
 *    certificate is a browser warning for every visitor — it must never read green.
 *
 * @return array<int,array{key:string,label:string,does:string,requires:string[]}>
 */
function infra_pipeline_steps(): array
{
    return [
        ['key' => 'assign', 'label' => 'Box',
         'does' => 'A server is chosen for this domain.',
         'requires' => []],

        ['key' => 'host', 'label' => 'Host area',
         'does' => 'The vhost, its folder and an FTP login exist on that box.',
         'requires' => ['assign']],

        ['key' => 'built', 'label' => 'Built',
         'does' => 'This domain\'s static HTML has been generated on the factory.',
         'requires' => []],

        ['key' => 'upload', 'label' => 'Upload',
         'does' => 'The folder holds a real site, not the two placeholder files.',
         'requires' => ['host', 'built']],

        ['key' => 'zone', 'label' => 'CF zone',
         'does' => 'A Cloudflare zone exists and its record points at the box, proxied.',
         'requires' => ['assign']],

        ['key' => 'golive', 'label' => 'Go live',
         'does' => 'A release date is set, or the switch is pressed now.',
         'requires' => ['upload', 'zone']],

        ['key' => 'dns', 'label' => 'DNS',
         'does' => 'The nameservers are switched at the registrar and the zone is active.',
         'requires' => ['golive']],

        ['key' => 'live', 'label' => 'Live',
         'does' => 'The site answers on its own hostname with a certificate that matches.',
         'requires' => ['dns']],
    ];
}

/** Step keys, in order. */
function infra_pipeline_step_keys(): array
{
    return array_column(infra_pipeline_steps(), 'key');
}

/* ───────────────────────────── stored answers ───────────────────────────── */

/**
 * Every stored cell for a set of domains, as $out[$domain][$step].
 * Domains with no rows simply do not appear — absence is the "never checked" state.
 *
 * One query for the whole batch rather than one per row: fifty domains × eight steps
 * is four hundred cells, and four hundred queries to draw one table is how a page
 * that renders stored state ends up as slow as one that goes to the network.
 *
 * @return array<string,array<string,array{state:string,note:string,attempts:int,at:int}>>
 */
function infra_pipeline_stored(array $domains = []): array
{
    $db = infra_state_db();
    if ($domains) {
        $in   = implode(',', array_fill(0, count($domains), '?'));
        $stmt = $db->prepare('SELECT * FROM domain_step WHERE domain IN (' . $in . ')');
        $stmt->execute(array_map(fn($d) => strtolower(trim($d)), $domains));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $db->query('SELECT * FROM domain_step')->fetchAll(PDO::FETCH_ASSOC);
    }

    $out = [];
    foreach ($rows as $r) {
        $out[$r['domain']][$r['step']] = [
            'state'    => (string) $r['state'],
            'note'     => (string) $r['note'],
            'attempts' => (int) $r['attempts'],
            'at'       => (int) $r['checked_at'],
        ];
    }
    return $out;
}

/**
 * Record what a check or an action found. Stage 2 onwards calls this; stage 1 does not.
 *
 * `state` is always what CHECK reported, never what an action claimed. Exit code 0 is
 * not evidence — this console has watched FTP report success while writing nothing, and
 * Hestia confirm a folder while nginx served its default page with a 200. Green here
 * has to mean somebody went and looked.
 */
function infra_pipeline_set(string $domain, string $step, string $state, string $note = '', bool $countAttempt = false): void
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || !in_array($step, infra_pipeline_step_keys(), true)) return;

    $db  = infra_state_db();
    $cur = $db->prepare('SELECT attempts FROM domain_step WHERE domain = ? AND step = ?');
    $cur->execute([$domain, $step]);
    $attempts = (int) ($cur->fetchColumn() ?: 0) + ($countAttempt ? 1 : 0);

    $db->prepare('REPLACE INTO domain_step (domain, step, state, note, attempts, checked_at)
                  VALUES (?,?,?,?,?,?)')
       ->execute([$domain, $step, $state, $note, $attempts, time()]);
}

/* ─────────────────────── what the fleet table already implies ─────────────────────── */

/**
 * A best guess at a cell from what the fleet table already knows, used only where
 * nothing has ever been CHECKED.
 *
 * Deliberately not written into domain_step. That table is the record of things
 * somebody went and verified, and seeding it with inferences would make eight
 * unverified cells per domain indistinguishable from eight checked ones the first
 * time anybody looked. The grid renders these faded and says they are unverified;
 * a real check overwrites them by simply existing.
 *
 * `status` is the fleet state machine's own word for the domain (begin / staged /
 * queued / releasing / live), computed elsewhere from Cloudflare and the box.
 *
 * @return array<string,array{state:string,note:string}> keyed by step
 */
function infra_pipeline_derived(array $rec): array
{
    $has    = fn(string $c) => trim((string) ($rec[$c] ?? '')) !== '';
    $status = strtolower(trim((string) ($rec['status'] ?? '')));
    $live   = $status === 'live';

    $g = fn(bool $done) => ['state' => $done ? INFRA_STEP_OK : INFRA_STEP_TODO, 'note' => ''];

    return [
        // A server_id is written when the domain is assigned to a box.
        'assign' => $g($has('server_id')),
        // An FTP login only exists because a host area was created to hold it — it is
        // the closest thing the fleet table has to a receipt for that step.
        'host'   => $g($has('ftp_user')),
        // Nothing in the fleet table records a local build, and guessing from "the box
        // has files" would conflate building with uploading. Left unknown on purpose.
        'built'  => ['state' => '', 'note' => ''],
        // Ditto: the content check is stored per BOX, not per domain, so it cannot
        // answer "did THIS domain's folder receive files".
        'upload' => ['state' => '', 'note' => ''],
        'zone'   => $g($has('cf_zone_id')),
        // A date on its own is a plan, not an event; the release itself is what these
        // later statuses record.
        'golive' => ['state' => in_array($status, ['releasing', 'awaiting-ns'], true) || $live
                        ? INFRA_STEP_OK : INFRA_STEP_TODO,
                     'note'  => $has('go_live_at') ? 'scheduled ' . $rec['go_live_at'] : ''],
        // `nameservers` holds the pair the ZONE EXPECTS, not proof the registrar was
        // changed to it — so it cannot stand in for this step. Only the state machine
        // reaching 'live' (Cloudflare reports the zone active) evidences the switch.
        'dns'    => $g($live),
        'live'   => $g($live),
    ];
}

/**
 * One domain's row: the stored answer per step where there is one, the inference where
 * there is not, plus what to do next.
 *
 * @return array{domain:string, rec:array, cells:array<string,array>, next:string, worst:string}
 */
function infra_pipeline_row(array $rec, array $stored = []): array
{
    $domain  = (string) ($rec['domain'] ?? '');
    $derived = infra_pipeline_derived($rec);
    $cells   = [];

    foreach (infra_pipeline_steps() as $s) {
        $k = $s['key'];
        if (isset($stored[$k])) {
            $cells[$k] = $stored[$k] + ['derived' => false];
        } else {
            $d = $derived[$k] ?? ['state' => '', 'note' => ''];
            $cells[$k] = ['state' => $d['state'], 'note' => $d['note'],
                          'attempts' => 0, 'at' => 0, 'derived' => true];
        }
    }

    // The next thing worth doing on this row: the first step that is not done and whose
    // prerequisites all are. A row blocked behind an earlier failure reports that
    // failure rather than a step it cannot start.
    $next = '';
    foreach (infra_pipeline_steps() as $s) {
        $st = $cells[$s['key']]['state'];
        if ($st === INFRA_STEP_OK) continue;
        $ready = true;
        foreach ($s['requires'] as $req) {
            if (($cells[$req]['state'] ?? '') !== INFRA_STEP_OK) { $ready = false; break; }
        }
        if ($ready) { $next = $s['key']; break; }
    }

    // The row's headline: a failure anywhere outranks everything, because it is the
    // only state that needs a person.
    $worst = 'ok';
    foreach ($cells as $c) {
        if ($c['state'] === INFRA_STEP_FAIL) { $worst = INFRA_STEP_FAIL; break; }
        if ($c['state'] !== INFRA_STEP_OK)   $worst = 'partial';
    }

    return ['domain' => $domain, 'rec' => $rec, 'cells' => $cells,
            'next' => $next, 'worst' => $worst];
}

/* ─────────────────────────── going and looking ─────────────────────────── */

/**
 * Domain → the folder holding its generated site, for every batch on the factory.
 *
 * Globbed once for the whole grid rather than tested per row: sixty-five stat calls
 * to draw one column is the filesystem version of the mistake this console already
 * made over the network.
 *
 * The slug rule (dots and dashes to underscores) is ms_batch_output_dir()'s, in
 * includes/multisite/batch.php. It is restated here rather than required, because
 * admin/infra/lib/* is deliberately self-contained — if that rule ever changes, this
 * line has to change with it.
 *
 * @return array<string,array{dir:string,at:int,has_index:bool}> keyed by slug
 */
function infra_pipeline_built_index(): array
{
    $out = [];
    foreach (glob(dirname(__DIR__, 3) . '/sites/*/batches/*/output/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        $at   = (int) @filemtime($dir);
        // Newest wins when the same domain has been built in two batches.
        if (isset($out[$slug]) && $out[$slug]['at'] >= $at) continue;
        // index.html is the cheap proof a build finished; counting every file under
        // sixty-five trees costs thousands of stats and answers the same question.
        $out[$slug] = ['dir' => $dir, 'at' => $at, 'has_index' => is_file($dir . '/index.html')];
    }
    return $out;
}

/** A domain as ms_batch_output_dir() names its folder. */
function infra_pipeline_slug(string $domain): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($domain))), '_');
}

/**
 * Go and look, for ONE step, across a whole batch — then store what was found.
 *
 * Batched by the shape the underlying API actually has, which is why this is per
 * column and not per cell: the host and upload answers arrive one box at a time, the
 * zone and DNS answers one Cloudflare account at a time, and only `live` is genuinely
 * one request per domain. Sixty-five rows therefore cost a handful of calls for most
 * columns — and nothing at all for the three that are pure state.
 *
 * Every cell written here is what the CHECK saw. Nothing writes 'ok' because an action
 * reported success.
 *
 * @return array{step:string,checked:int,ok:int,todo:int,fail:int}
 */
function infra_pipeline_refresh(string $step, string $batch = ''): array
{
    require_once __DIR__ . '/cache.php';
    require_once __DIR__ . '/fleet.php';
    require_once __DIR__ . '/hestia_fleet.php';
    require_once __DIR__ . '/uptime.php';

    $out = ['step' => $step, 'checked' => 0, 'ok' => 0, 'todo' => 0, 'fail' => 0];
    if (!in_array($step, infra_pipeline_step_keys(), true)) return $out;

    $rows = infra_pipeline_rows($batch);
    if (!$rows) return $out;

    // A refresh means "go and look", so the shared indexes must not answer from the
    // store. This is the flag the fleet Refresh and the go-live cron already use.
    infra_cache_force(true);

    // Indexes that answer for every row at once. Built lazily so a column that does
    // not need one never pays for it.
    $hostIdx = null; $zoneIdx = null; $builtIdx = null; $content = null;

    foreach ($rows as $r) {
        $dom = $r['domain'];
        $rec = $r['rec'];
        $state = INFRA_STEP_TODO;
        $note  = '';

        switch ($step) {
            case 'assign':
                // PURE STATE, no network. Choosing a box is a decision recorded here,
                // not something to go and discover. This first swept all twenty boxes
                // to catch "assigned to one box, actually on another" — 70 seconds to
                // answer a question the row already knew, and drift is D.Buy's Drift
                // column anyway. What the boxes actually report is the next step's job.
                $sid = trim((string) ($rec['server_id'] ?? ''));
                if ($sid !== '') {
                    $srv   = infra_hestia_server($sid);
                    $state = INFRA_STEP_OK;
                    $note  = $srv ? (string) ($srv['label'] ?? $sid)
                                  : 'assigned to ' . $sid . ', which is no longer in the registry';
                    if (!$srv) $state = INFRA_STEP_FAIL;
                } else {
                    $note = 'no box chosen yet';
                }
                break;

            case 'host':
                // The vhost is on the box, read from the box.
                $hostIdx ??= infra_host_domain_index();
                if (isset($hostIdx[$dom])) {
                    $state = INFRA_STEP_OK;
                    $note  = (string) ($hostIdx[$dom]['www_root'] ?? '');
                } elseif (trim((string) ($rec['ftp_user'] ?? '')) !== '') {
                    // An FTP login with no vhost behind it is a half-made host area,
                    // not an absence — say so rather than reading as "not started".
                    $state = INFRA_STEP_FAIL;
                    $note  = 'an FTP login is recorded but the box lists no vhost for it';
                }
                break;

            case 'built':
                $builtIdx ??= infra_pipeline_built_index();
                $b = $builtIdx[infra_pipeline_slug($dom)] ?? null;
                if ($b && $b['has_index']) {
                    $state = INFRA_STEP_OK;
                    $note  = 'built ' . date('j M H:i', $b['at']);
                } elseif ($b) {
                    $state = INFRA_STEP_FAIL;
                    $note  = 'output folder exists but has no index.html — the build did not finish';
                }
                break;

            case 'upload':
                // One content run per BOX covers every domain on it. The run stores its
                // per-domain detail (see infra_hestia_content_run), so this column and
                // the Files tile on the Servers card are the same measurement.
                $sid = trim((string) ($rec['server_id'] ?? ''));
                if ($sid === '') { $note = 'no box yet'; break; }
                if (!isset($content[$sid])) {
                    $srv = infra_hestia_server($sid);
                    $content[$sid] = $srv ? infra_hestia_content_run($srv) : ['sites' => []];
                }
                $c = $content[$sid]['sites'][$dom] ?? null;
                if ($c === null) {
                    $note = 'no folder on the box for this domain';
                } elseif ($c['placeholder_only']) {
                    $note = 'folder holds only the placeholder — nothing uploaded';
                } else {
                    $state = INFRA_STEP_OK;
                    $note  = $c['files'] . ' files, ' . round($c['bytes'] / 1024) . ' KB';
                }
                break;

            case 'zone':
                // A ZONE ON ITS OWN SENDS NOBODY ANYWHERE. This check first only asked
                // whether Cloudflare listed the domain, and reported all 31 zoned
                // domains as done — while the Live sweep found every one of them
                // answering "could not resolve host", because not one had a record in
                // it. Existing and pointing at the box are different facts, and the
                // column claims the second.
                $zoneIdx ??= infra_cf_zone_index();
                $z = $zoneIdx[$dom] ?? null;
                if (!$z) {
                    if (trim((string) ($rec['cf_zone_id'] ?? '')) !== '') {
                        // A zone id on record that Cloudflare no longer lists is a fact
                        // about the record, not a missing step.
                        $state = INFRA_STEP_FAIL;
                        $note  = 'a zone id is stored but Cloudflare does not list this domain';
                    }
                    break;
                }
                $acct = null;
                foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === $z['account_id']) $acct = $a;
                $recs = $acct ? infra_zone_contents_run($acct, (string) $z['zone_id']) : ['a' => [], 'n' => 0];
                $apex = null;
                foreach ($recs['a'] as $A) if (strtolower($A['name']) === $dom) { $apex = $A; break; }

                if (!$apex) {
                    $note = 'zone ' . ($z['status'] ?? '?') . ' in ' . ($z['account_label'] ?? '')
                          . ' but no A record for the domain — it resolves nowhere';
                } elseif (!$apex['proxied']) {
                    // Unproxied means the origin IP is public and none of the caching
                    // the whole architecture assumes is happening.
                    $state = INFRA_STEP_FAIL;
                    $note  = 'A record → ' . $apex['ip'] . ' but NOT proxied — origin exposed, no CDN';
                } else {
                    $want = trim((string) ($rec['default_ip'] ?? ''));
                    $srv  = infra_hestia_server(trim((string) ($rec['server_id'] ?? '')));
                    if ($srv) $want = trim((string) ($srv['default_ip'] ?? $srv['host'] ?? ''));
                    if ($want !== '' && $apex['ip'] !== $want) {
                        $state = INFRA_STEP_FAIL;
                        $note  = 'A record → ' . $apex['ip'] . ', but its box is ' . $want;
                    } else {
                        $state = INFRA_STEP_OK;
                        $note  = ($z['account_label'] ?? '') . ' · proxied → ' . $apex['ip'];
                    }
                }
                break;

            case 'golive':
                // Pure state: has it been scheduled, or already released? Nothing to ask
                // anyone. Overdue is a FAILURE — a date that passed with nothing having
                // happened is the one state that looks fine and is not.
                $status = strtolower(trim((string) ($rec['status'] ?? '')));
                $when   = trim((string) ($rec['go_live_at'] ?? ''));
                if (in_array($status, ['releasing', 'awaiting-ns', 'live'], true)) {
                    $state = INFRA_STEP_OK;
                    $note  = $when !== '' ? 'released ' . $when : 'released';
                } elseif ($when !== '' && $when < infra_today()) {
                    $state = INFRA_STEP_FAIL;
                    $note  = 'scheduled ' . $when . ' — that date has passed and it has not been released';
                } elseif ($when !== '') {
                    $note = 'scheduled ' . $when;
                } else {
                    $note = 'not scheduled';
                }
                break;

            case 'dns':
                // Cloudflare calling the zone active IS the evidence the nameservers
                // were switched: it only flips once it is answering for the domain.
                $zoneIdx ??= infra_cf_zone_index();
                $z = $zoneIdx[$dom] ?? null;
                $zs = strtolower((string) ($z['status'] ?? ''));
                if ($zs === 'active') {
                    $state = INFRA_STEP_OK;
                    // Says the delegation is done and nothing more. Whether anything is
                    // IN the zone is the CF zone column's question — 31 domains here are
                    // active and resolve nowhere.
                    $note  = 'zone active — the nameservers point at Cloudflare';
                } elseif ($z) {
                    $note = 'zone ' . $zs . ' — nameservers not switched yet';
                    if ($z['name_servers']) $note .= ' (expects ' . implode(' + ', array_slice($z['name_servers'], 0, 2)) . ')';
                } else {
                    $note = 'no zone yet';
                }
                break;

            case 'live':
                // The only genuinely per-domain request in the whole grid.
                $c = infra_site_check_run($dom);
                if (!empty($c['up']) && !empty($c['cert_ok'])) {
                    $state = INFRA_STEP_OK;
                    $note  = 'HTTP ' . $c['code'] . ' · ' . $c['ms'] . 'ms';
                } elseif (!empty($c['up'])) {
                    // Answering behind a bad certificate is a browser warning for every
                    // visitor. It is not "up".
                    $state = INFRA_STEP_FAIL;
                    $note  = infra_site_verdict($c);
                } else {
                    $note = infra_site_verdict($c) . ($c['error'] ? ' — ' . $c['error'] : '');
                }
                break;
        }

        infra_pipeline_set($dom, $step, $state, $note, true);
        $out['checked']++;
        $out[$state === INFRA_STEP_OK ? 'ok' : ($state === INFRA_STEP_FAIL ? 'fail' : 'todo')]++;
    }

    infra_cache_force(false);
    return $out;
}

/**
 * When each column was last swept, as step => newest checked_at.
 * Age belongs on screen: a green tick with no date is a claim about an unknown moment.
 *
 * @return array<string,int>
 */
function infra_pipeline_column_ages(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        foreach ($r['cells'] as $k => $c) {
            if (!empty($c['derived']) || empty($c['at'])) continue;
            $out[$k] = max($out[$k] ?? 0, (int) $c['at']);
        }
    }
    return $out;
}

/**
 * The batches that exist — every distinct non-empty `batch` tag, with its size.
 * A tag, not an object: fifty domains carry the same string, and re-tagging them
 * tomorrow breaks nothing because there is nothing else to keep in step.
 *
 * @return array<string,int> batch => domain count
 */
function infra_pipeline_batches(): array
{
    $rows = infra_state_db()->query(
        'SELECT batch, COUNT(*) c FROM domains WHERE batch <> "" GROUP BY batch ORDER BY batch'
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) $out[(string) $r['batch']] = (int) $r['c'];
    return $out;
}

/**
 * The rows to draw. A named batch, or — before anything has been tagged — every domain
 * already in flight, so the grid has something true to show on the day it is built
 * rather than an empty table and no way to tell whether it works.
 *
 * @return array<int,array> pipeline rows
 */
function infra_pipeline_rows(string $batch = ''): array
{
    $all = infra_state_all_domains();

    // "In flight" is a box OR a zone, not a box alone. On the day this was written the
    // two sets turned out to be DISJOINT — 34 domains had a box and no zone, 31 had a
    // zone and no box, and not one had both. Filtering on server_id alone would have
    // drawn a grid that silently omitted half the work in progress.
    $picked = array_filter($all, function ($r) use ($batch) {
        if ($batch !== '') return (string) ($r['batch'] ?? '') === $batch;
        return trim((string) ($r['server_id'] ?? '')) !== ''
            || trim((string) ($r['cf_zone_id'] ?? '')) !== '';
    });

    $stored = infra_pipeline_stored(array_keys($picked));

    $rows = [];
    foreach ($picked as $dom => $rec) {
        $rows[] = infra_pipeline_row($rec, $stored[$dom] ?? []);
    }
    return $rows;
}

/**
 * One line above the grid: how far the batch has got, and the one thing to fix.
 *
 * Four hundred cells is not something anybody reads; "12 stuck at upload" is. The
 * bottleneck counts the FIRST not-done step of each row, so a row is only ever
 * blamed on the thing actually blocking it.
 *
 * @return array{total:int, live:int, failed:int, stuck:array<string,int>}
 */
function infra_pipeline_summary(array $rows): array
{
    $out = ['total' => count($rows), 'live' => 0, 'failed' => 0, 'stuck' => []];

    foreach ($rows as $r) {
        if (($r['cells']['live']['state'] ?? '') === INFRA_STEP_OK) { $out['live']++; continue; }
        if ($r['worst'] === INFRA_STEP_FAIL) $out['failed']++;
        if ($r['next'] !== '') {
            $out['stuck'][$r['next']] = ($out['stuck'][$r['next']] ?? 0) + 1;
        }
    }
    arsort($out['stuck']);
    return $out;
}
