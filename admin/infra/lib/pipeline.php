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
        // later statuses record. 'awaiting-ns' means the automatic registrar switch
        // did NOT go through (no registrar module, or nothing on record) — that is a
        // FAILURE to surface, not the same as 'releasing' (switch sent, propagating),
        // or the button to retry it never comes back.
        'golive' => $status === 'awaiting-ns'
                        ? ['state' => INFRA_STEP_FAIL, 'note' => 'automatic nameserver switch failed — set nameservers manually at the registrar, or fix the registrar field and retry']
                        : ['state' => (in_array($status, ['releasing'], true) || $live) ? INFRA_STEP_OK : INFRA_STEP_TODO,
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
function infra_pipeline_refresh(string $step, string $batch = '', string $only = ''): array
{
    require_once __DIR__ . '/cache.php';
    require_once __DIR__ . '/fleet.php';
    require_once __DIR__ . '/hestia_fleet.php';
    require_once __DIR__ . '/uptime.php';

    $out = ['step' => $step, 'checked' => 0, 'ok' => 0, 'todo' => 0, 'fail' => 0];
    if (!in_array($step, infra_pipeline_step_keys(), true)) return $out;

    $rows = infra_pipeline_rows($batch);
    // $only re-checks a single row. It is the SAME code path as the column sweep on
    // purpose: an action that verified itself by its own rules would be free to
    // disagree with the column that draws it.
    if ($only !== '') {
        $only = strtolower(trim($only));
        $rows = array_values(array_filter($rows, fn($r) => $r['domain'] === $only));
    }
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
                        // A green zone cell otherwise looks identical whether the origin
                        // holds a real cert (SSL full — CF↔origin encrypted) or fell back
                        // to flexible (CF↔origin plain HTTP, because no CF account has an
                        // origin_ca_key on file yet) — surface which one it actually is
                        // rather than let "resolves fine" stand in for "fully secure".
                        if ($acct) {
                            $ssl = cf_get_ssl_mode($acct, (string) $z['zone_id']);
                            if ($ssl['ok'] && $ssl['mode'] !== 'full' && $ssl['mode'] !== 'strict') {
                                $note .= ' · SSL: ' . $ssl['mode'] . ' (CF↔origin unencrypted — no origin cert)';
                            } elseif ($ssl['ok']) {
                                $note .= ' · SSL: ' . $ssl['mode'];
                            }
                        }
                    }
                }
                break;

            case 'golive':
                // Pure state: has it been scheduled, or already released? Nothing to ask
                // anyone. Overdue is a FAILURE — a date that passed with nothing having
                // happened is the one state that looks fine and is not. 'awaiting-ns' is
                // ALSO a failure to surface: it means infra_golive_release() tried and the
                // automatic registrar switch did not go through (no registrar module, or
                // the registrar field was blank) — lumping it in with 'releasing' hid a
                // stuck domain behind a green check and removed the only retry button.
                $status = strtolower(trim((string) ($rec['status'] ?? '')));
                $when   = trim((string) ($rec['go_live_at'] ?? ''));
                if ($status === 'awaiting-ns') {
                    $state = INFRA_STEP_FAIL;
                    $note  = 'automatic nameserver switch failed — set nameservers manually at the registrar, or fix the registrar field and retry';
                } elseif (in_array($status, ['releasing', 'live'], true)) {
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

        // countAttempt is FALSE here: an attempt is a try at DOING the step, not at
        // looking at it. Counting checks made `attempts` climb every time a column was
        // swept, which destroys the one thing it is for — telling a flaky call apart
        // from a box that has failed the same way four times.
        infra_pipeline_set($dom, $step, $state, $note, false);
        $out['checked']++;
        $out[$state === INFRA_STEP_OK ? 'ok' : ($state === INFRA_STEP_FAIL ? 'fail' : 'todo')]++;
    }

    infra_cache_force(false);
    return $out;
}

/* ──────────────────────────────── doing it ──────────────────────────────── */

/**
 * Which steps a button can actually perform, and what the button says.
 *
 * Four of the eight are things the console does; the other four are not, and saying so
 * is better than a button that fails. `built` needs a master and a params row, which is
 * the multisite builder's job, not a per-domain click here. `dns` has no separate act —
 * switching the nameservers IS `golive`. `live` is an observation; there is nothing to
 * perform, only something to see.
 *
 * @return array<string,string> step => button label
 */
function infra_pipeline_actions(): array
{
    return [
        'assign' => 'Assign a box',
        'host'   => 'Create the host area',
        'zone'   => 'Stage the Cloudflare zone',
        'upload' => 'Upload the built site',
        'golive' => 'Switch the nameservers',
    ];
}

/** How long a cell may sit in `running` before it is treated as a crashed run. */
const INFRA_STEP_STALE = 900;   // 15 minutes

/**
 * Run $fn() while holding an exclusive file lock on ONE domain+step, so its
 * check-then-mark-running sequence can't be split across two near-simultaneous
 * requests — a double click, or two open tabs on the same Go Live button. Without
 * this, infra_pipeline_do()'s guard was a plain read-then-write with a real gap
 * between them: two requests could both read "not running" and both dispatch the
 * underlying action, including two concurrent registrar nameserver-switch calls
 * for the same domain. The multisite launch actions hit this exact bug class and
 * fixed it the same way — see ms_with_launch_lock() in includes/multisite/batch.php.
 */
function infra_pipeline_lock(string $domain, string $step, callable $fn)
{
    $dir = dirname(__DIR__) . '/state/locks';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $safe = fn(string $s) => preg_replace('/[^a-z0-9]+/', '_', strtolower($s));
    $path = $dir . '/' . $safe($domain) . '__' . $safe($step) . '.lock';
    $fh = @fopen($path, 'c');
    if (!$fh) {
        // Still fails open — refusing every go-live/provision click over a broken
        // lock directory would take the whole console down for what is usually a
        // permissions slip, not a busy lock. But it used to fail SILENTLY: a
        // root-owned locks/ directory (created by a root-run test, not www-data)
        // disabled this exact protection in production for days with the fix
        // looking shipped, and nothing anywhere said so. Now it logs AND leaves a
        // file the console itself can show on the Go Live card, the same way
        // golive_tick.json surfaces the cron's health — a lock nobody can take is
        // exactly the kind of "still running, but not really" fact this whole
        // subsystem's convention is to surface, not assume.
        $err = error_get_last();
        error_log("infra_pipeline_lock: could not open {$path} for {$domain}/{$step}"
            . ($err ? ' — ' . $err['message'] : '') . ' — proceeding WITHOUT the lock');
        @file_put_contents($dir . '/../lock_failure.json', json_encode([
            'at' => time(), 'domain' => $domain, 'step' => $step, 'path' => $path,
        ], JSON_PRETTY_PRINT));
        return $fn();
    }
    // A working lock clears any earlier failure marker — otherwise a permissions
    // fix (like the one this file's own docblock describes) leaves a stale red
    // warning on the console forever after the real problem is gone.
    @unlink($dir . '/../lock_failure.json');
    try {
        flock($fh, LOCK_EX);
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Perform ONE step for ONE domain, then re-check it.
 *
 * The contract, in order, and none of it is optional:
 *   1. every `do` is idempotent — running it on a green cell is a no-op, never a double
 *   2. the cell goes `running` first, so a second click cannot start the same work twice
 *   3. whatever the action claims, the CELL IS WRITTEN BY THE CHECK afterwards
 *
 * (3) is the one that matters. This console has watched FTP exit 0 having written
 * nothing and Hestia confirm a folder while nginx served its default page with a 200.
 * An action that marks its own homework is how a grid comes to show eight green ticks
 * over a site that does not exist.
 *
 * @return array{ok:bool, msg:string, state:string}
 */
function infra_pipeline_do(string $step, string $domain, string $batch = '', array $opts = []): array
{
    $domain  = strtolower(trim($domain));
    $actions = infra_pipeline_actions();
    if (!isset($actions[$step])) {
        return ['ok' => false, 'msg' => 'nothing to run for that step', 'state' => ''];
    }

    $rec = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'msg' => 'not in fleet state', 'state' => ''];

    // Already done, already running, and the mark-running that follows all have to
    // happen as one atomic step — see infra_pipeline_lock()'s docblock for what goes
    // wrong when they don't. $claim is non-null only when the caller should stop.
    $claim = infra_pipeline_lock($domain, $step, function () use ($domain, $step) {
        // Already done? Say so and do nothing. Idempotency starts here rather than
        // relying on every underlying call to be a no-op.
        $cur = infra_pipeline_stored([$domain])[$domain][$step] ?? null;
        if (($cur['state'] ?? '') === INFRA_STEP_OK) {
            return ['ok' => true, 'msg' => 'already done — nothing to do', 'state' => INFRA_STEP_OK];
        }
        // Somebody else is on it. A stale marker (a crashed run) is allowed through,
        // because a cell stuck at `running` forever is worse than one that ran twice.
        if (($cur['state'] ?? '') === INFRA_STEP_RUNNING && (time() - (int) ($cur['at'] ?? 0)) < INFRA_STEP_STALE) {
            return ['ok' => false, 'msg' => 'already running (started ' . date('H:i', (int) $cur['at']) . ')',
                    'state' => INFRA_STEP_RUNNING];
        }

        // Prerequisites. Running a step whose inputs are missing produces a confusing
        // failure at a lower layer; refusing here names the actual blocker.
        foreach (infra_pipeline_steps() as $s) {
            if ($s['key'] !== $step) continue;
            foreach ($s['requires'] as $req) {
                $rq = infra_pipeline_stored([$domain])[$domain][$req] ?? null;
                if (($rq['state'] ?? '') !== INFRA_STEP_OK) {
                    $lbl = '';
                    foreach (infra_pipeline_steps() as $t) if ($t['key'] === $req) $lbl = $t['label'];
                    return ['ok' => false, 'state' => '',
                            'msg' => 'blocked: ' . $lbl . ' is not done yet' . (($rq === null) ? ' (and has never been checked)' : '')];
                }
            }
        }

        infra_pipeline_set($domain, $step, INFRA_STEP_RUNNING, 'started ' . date('H:i:s'), true);
        return null;
    });
    if ($claim !== null) return $claim;

    require_once __DIR__ . '/hestia_fleet.php';
    require_once __DIR__ . '/provision.php';
    require_once __DIR__ . '/golive.php';

    $msg = '';
    try {
        switch ($step) {

            case 'assign':
                require_once __DIR__ . '/cf_alloc.php';

                // THE ZONE DECIDES THE BOX, when there already is one.
                //
                // A domain whose zone exists must go on the box its account is bound to,
                // or the containment only holds for domains that happened to be created
                // in the right order. Round-robin here would scatter the 31 domains
                // registered at Cloudflare — which share one nameserver pair permanently,
                // and cannot be moved to another account — across twenty different IPs.
                // That is the maximum-chaining case this whole scheme exists to prevent:
                // one pair linking 31 domains, each also linked to whatever else sits on
                // its box. Cloudflare cannot move a zone, so the box is the only end of
                // this that can still be chosen.
                $zoneAcct = trim((string) ($rec['cf_account_id'] ?? ''));
                if ($zoneAcct !== '') {
                    $acct = null;
                    foreach (infra_cf_accounts() as $a) {
                        if ((string) ($a['id'] ?? '') === $zoneAcct) { $acct = $a; break; }
                    }
                    // Every branch below REFUSES rather than falling through to the
                    // round-robin. A domain that already has a zone has already had its
                    // box decided for it; picking a different one would place the site
                    // and its nameservers in two different groups, which is the failure
                    // this rule exists to prevent — and it would do it silently.
                    if (!$acct) {
                        $msg = 'its zone is in account ' . $zoneAcct . ', which the console no longer has — '
                             . 'add it back before assigning a box';
                        break;
                    }
                    $bound = trim((string) ($acct['server_id'] ?? ''));
                    if ($bound === '') {
                        $msg = 'its zone is in ' . ($acct['label'] ?? $zoneAcct)
                             . ', which is not bound to a box yet — bind it on the Cloudflare tab so this '
                             . 'domain lands on the same box as the rest of that account';
                        break;
                    }
                    $box = infra_hestia_server($bound);
                    if (!$box) {
                        $msg = 'its zone is bound to ' . $bound . ', which is not in the box registry';
                        break;
                    }
                    infra_state_upsert_domain(['domain' => $domain, 'server_id' => $bound]);
                    $msg = 'assigned to ' . ($box['label'] ?? $bound) . ' — the box its existing zone belongs to';
                    break;
                }

                // No zone yet, so the box is a free choice: round-robin across configured
                // boxes on the same persistent counter the bulk runner uses, so the two
                // do not fill the fleet unevenly by taking turns from different starts.
                $boxes = array_values(array_filter(infra_hestia_servers(), 'hestia_server_configured'));
                if (!$boxes) { $msg = 'no configured box to assign to'; break; }
                $box = $boxes[infra_state_counter_next('pipeline_server') % count($boxes)];
                infra_state_upsert_domain(['domain' => $domain, 'server_id' => (string) $box['id']]);
                $msg = 'assigned to ' . ($box['label'] ?? $box['id']);
                break;

            case 'host':
                $box = infra_hestia_server((string) ($rec['server_id'] ?? ''));
                if (!$box) { $msg = 'no box assigned'; break; }
                // Idempotent: infra_provision_one() skips a vhost that already exists.
                //
                // The restart is deferred when a column run is doing this, and only then.
                // nginx does not notice a new vhost until it restarts — until it does it
                // serves Hestia's default page with a 200 OK, which looks like a working
                // site. But restarting per domain means 37 restarts for a 37-row column,
                // 37 interruptions to every site already on those boxes, for one restart's
                // worth of effect. infra_pipeline_run() does it once per box at the end.
                $r = infra_provision_one($domain, $box, null,
                        ['site' => true, 'cf' => false, 'restart' => empty($opts['defer_restart'])]);
                $msg = implode(' · ', $r['lines']);
                break;

            case 'zone':
                require_once __DIR__ . '/cf_alloc.php';
                $sid = (string) ($rec['server_id'] ?? '');
                $box = infra_hestia_server($sid);
                if (!$box) { $msg = 'no box assigned — the zone needs an IP to point at'; break; }

                // A zone already recorded against an account stays with it. Cloudflare
                // cannot move a zone between accounts, so re-picking would create a
                // second zone rather than relocating the first.
                $acct = null;
                foreach (infra_cf_accounts() as $a) {
                    if (($a['id'] ?? '') !== '' && ($a['id'] ?? '') === ($rec['cf_account_id'] ?? '')) $acct = $a;
                }
                if (!$acct) {
                    // THE BOX DECIDES THE ACCOUNT. This used to be a round-robin across
                    // every account, unrelated to the box — which put a domain's
                    // nameserver pair and its IP on two different groups of domains,
                    // and made those groups chain together. See lib/cf_alloc.php.
                    $pick = infra_cf_account_for_server($sid);
                    if (!$pick['ok']) { $msg = $pick['why']; break; }   // refuse, never spill
                    $acct = $pick['account'];
                }
                $r = infra_provision_one($domain, $box, $acct, ['site' => false, 'cf' => true]);
                $msg = implode(' · ', $r['lines']);
                break;

            case 'upload':
                $r   = infra_pipeline_upload($domain, $rec);
                $msg = $r['msg'];
                break;

            case 'golive':
                // Goes through the same gate as the scheduler and the nightly run. No
                // override here: the override lives on the row's own button, where the
                // person choosing it can see the Upload cell they are overruling.
                $r   = infra_golive_release($domain);
                $msg = $r['message'];
                break;
        }
    } catch (Throwable $e) {
        // A thrown error must not leave the cell stuck at `running` — that reads as
        // "in progress" forever and hides the fault completely.
        infra_pipeline_set($domain, $step, INFRA_STEP_FAIL, 'error: ' . $e->getMessage());
        return ['ok' => false, 'msg' => $e->getMessage(), 'state' => INFRA_STEP_FAIL];
    }

    // THE CHECK HAS THE LAST WORD. Whatever the action said, this is what goes in.
    infra_cache_force(true);
    $after = infra_pipeline_refresh($step, '', $domain);
    infra_cache_force(false);

    $state = $after['ok'] > 0 ? INFRA_STEP_OK : ($after['fail'] > 0 ? INFRA_STEP_FAIL : INFRA_STEP_TODO);
    return ['ok' => $state === INFRA_STEP_OK, 'state' => $state,
            'msg' => ($msg !== '' ? $msg . ' — ' : '') . 'checked: ' . $state];
}

/**
 * Upload one domain's built site over FTP into its host area.
 *
 * Everything here is already written elsewhere and is deliberately not rewritten: the
 * built output is found the same way the Built column finds it, and the transfer is
 * deploy_site() — the same function the batch uploader uses, including its remote-path
 * DETECTION, which matters because Hestia's FTP login lands IN the docroot and a
 * hard-coded /public_html would transfer every file into a folder nginx never reads.
 *
 * deploy_site() reports through progress_log(), which is an SSE emitter by default —
 * so its sink is redirected into an array here instead of being streamed at a page
 * that is not listening.
 *
 * @return array{ok:bool, msg:string}
 */
function infra_pipeline_upload(string $domain, array $rec): array
{
    $box = infra_hestia_server((string) ($rec['server_id'] ?? ''));
    if (!$box)                                   return ['ok' => false, 'msg' => 'no box assigned'];
    if (trim((string) ($rec['ftp_user'] ?? '')) === '') return ['ok' => false, 'msg' => 'no FTP login — create the host area first'];

    $built = infra_pipeline_built_index()[infra_pipeline_slug($domain)] ?? null;
    if (!$built || !$built['has_index']) return ['ok' => false, 'msg' => 'nothing built for this domain yet'];

    $base = dirname(__DIR__, 3);
    require_once $base . '/includes/progress.php';
    require_once $base . '/includes/multisite/deploy.php';

    $dir = dirname(__DIR__) . '/state/manifests';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $lines = [];
    $prev  = $GLOBALS['_progress_sink'] ?? null;
    $GLOBALS['_progress_sink'] = function (array $p) use (&$lines) {
        if (!empty($p['msg'])) $lines[] = (string) $p['msg'];
    };
    try {
        $r = deploy_site([
            'ftp_host'     => (string) ($box['host'] ?? ''),
            'ftp_port'     => 21,
            'ftp_user'     => (string) $rec['ftp_user'],
            'ftp_pass'     => (string) $rec['ftp_pass'],
            // Left blank ON PURPOSE so deploy_site detects it after login. See its own
            // comment: guessing is silently wrong in both directions.
            'ftp_path'     => '',
            'ftp_passive'  => 'yes',
            'ftp_protocol' => 'ftp',
        ], rtrim($built['dir'], '/') . '/', $dir . '/' . infra_pipeline_slug($domain) . '.json');
    } finally {
        $GLOBALS['_progress_sink'] = $prev;
    }

    $ok = ($r['status'] ?? '') === 'done' && (int) ($r['failed'] ?? 0) === 0;
    return ['ok' => $ok, 'msg' => 'uploaded ' . (int) ($r['uploaded'] ?? 0) . ' file(s)'
                                . ((int) ($r['failed'] ?? 0) > 0 ? ', ' . (int) $r['failed'] . ' failed' : '')
                                . (($r['msg'] ?? '') !== '' ? ' — ' . $r['msg'] : '')];
}

/**
 * Run one step for every row that still needs it.
 *
 * Rows are independent: one failing must never stop the other forty-nine, so each is
 * caught on its own and the run carries on. Greens are skipped, which is what makes
 * pressing this twice safe and what makes "resume" mean nothing more than pressing it
 * again.
 *
 * $onEach, when given, is called after every row this run actually touches —
 * fn(string $domain, array $result). It exists so a CLI wrapper can print one line per
 * domain as it goes (see multisite/golive_run.php): without it, a caller only learns
 * anything once the WHOLE batch has finished, which is what let a slow registrar call
 * or a proxy timeout kill an in-request bulk run with no record of how far it got.
 *
 * @return array{step:string, ran:int, ok:int, failed:int, skipped:int, blocked:int}
 */
function infra_pipeline_run(string $step, string $batch = '', ?callable $onEach = null): array
{
    $out = ['step' => $step, 'ran' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0, 'restarted' => 0];
    $touched = [];

    foreach (infra_pipeline_rows($batch) as $r) {
        $state = $r['cells'][$step]['state'] ?? '';
        if ($state === INFRA_STEP_OK) { $out['skipped']++; continue; }
        // Defer the web restart: one per BOX at the end, not one per domain. See the
        // 'host' case — nginx serves the default page with a 200 until it restarts, so
        // the restart is mandatory, but doing it 37 times is 37 interruptions for the
        // same result.
        $d = infra_pipeline_do($step, $r['domain'], $batch, ['defer_restart' => true]);
        $out['ran']++;
        if ($d['ok'])                                   $out['ok']++;
        elseif (str_starts_with($d['msg'], 'blocked:'))  $out['blocked']++;
        else                                            $out['failed']++;
        if ($onEach) $onEach($r['domain'], $d);

        if ($step === 'host' && $d['ok']) {
            $sid = trim((string) (infra_state_get_domain($r['domain'])['server_id'] ?? ''));
            if ($sid !== '') $touched[$sid] = true;
        }
    }

    // Now the restarts — once per box that gained a vhost. Without this the sites just
    // created answer with Hestia's placeholder and nothing says anything is wrong.
    if ($touched) {
        require_once __DIR__ . '/hestia.php';
        foreach (array_keys($touched) as $sid) {
            $srv = infra_hestia_server($sid);
            if (!$srv) continue;
            $w = hestia_restart_web($srv);
            if (!empty($w['ok'])) $out['restarted']++;
        }
        // The Host column is re-checked after a restart, because "the vhost exists" and
        // "nginx is serving it" are different facts and only the second one matters.
        infra_cache_force(true);
        infra_pipeline_refresh('host', $batch);
        infra_cache_force(false);
    }
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
    //
    // But a zone alone is not always evidence of that either: buying a domain AT
    // Cloudflare auto-creates its zone as part of the registration receipt, before
    // anything has been staged — the exact same "no infrastructure exists yet, BY
    // DESIGN" fact infra_is_acquiring() already exists to name (fleet.php's drift
    // check and the acquisition-only D.Buy filter both defer to it for the same
    // reason). Without this, every Cloudflare-registrar domain shows up here as
    // "in flight" forever, since its zone can never be deleted independently of the
    // registration itself — Cloudflare's own API refuses that call outright.
    $picked = array_filter($all, function ($r) use ($batch) {
        if ($batch !== '') return (string) ($r['batch'] ?? '') === $batch;
        if (infra_is_acquiring($r)) return false;
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
