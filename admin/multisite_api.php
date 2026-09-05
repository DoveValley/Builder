<?php
// Multisite admin API (batch intake + runs). JSON responses.
// Wraps the params intake cores (includes/multisite/params.php). Works on the batch
// currently open (session), whose master is the active site. All POSTs require CSRF.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/params.php';
require_once __DIR__ . '/../includes/multisite/batch.php';
require_once __DIR__ . '/../includes/multisite/master_lint.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); echo json_encode(['error' => 'No active site selected.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$action   = $_REQUEST['action'] ?? '';
$masterId = ACTIVE_SITE_ID;

// ── Which batch ───────────────────────────────────────────────────────────────
// Everything except the master-level actions below operates on the open batch.
const MS_MASTER_ONLY_ACTIONS = ['sample_csv', 'lint_master'];

$batchId = ''; $batchDir = ''; $paramsPath = ''; $runsDir = '';
$active  = ms_active_batch();
if ($active) {
    $batchId    = $active['batch_id'];
    $batchDir   = ms_batch_dir($masterId, $batchId);
    $paramsPath = $batchDir . '/params.csv';
    $runsDir    = $batchDir . '/runs';
} elseif (!in_array($action, MS_MASTER_ONLY_ACTIONS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'No batch open. Open a batch from the Site Factory panel first.']);
    exit;
}

// PHP holds an exclusive lock on the session file for the whole of a request, so
// without this close, every action on this page queues behind whichever one is
// slowest (e.g. 'servers' sweeping the fleet) — see admin/infra/actions/fleet_refresh.php
// for the same fix on the Infra console. Nothing below writes to $_SESSION.
session_write_close();

/** Build browser-safe display rows — never sends ftp_pass to the client. */
function ms_rows_for_ui(array $v): array {
    $out = [];
    foreach ($v['rows'] as $r) {
        $d = $r['data'];
        $out[] = [
            'line'     => $r['line'],
            'domain'   => $r['domain'],
            'business' => $d['business'] ?? '',
            'city'     => trim(($d['city'] ?? '') . (($d['SS'] ?? '') !== '' ? ', ' . $d['SS'] : '')),
            'has_ftp'  => ($d['ftp_host'] ?? '') !== '' && ($d['ftp_user'] ?? '') !== '',
            'status'   => $r['errors'] ? 'error' : ($r['warnings'] ? 'warn' : 'ok'),
            'errors'   => $r['errors'],
            'warnings' => $r['warnings'],
        ];
    }
    return $out;
}

function ms_validation_payload(array $v): array {
    return [
        'summary'         => ['total' => count($v['rows']), 'ok' => $v['ok'], 'warn' => $v['warn'], 'error' => $v['error']],
        'unknown_columns' => $v['unknown_columns'],
        'rows'            => ms_rows_for_ui($v),
    ];
}

// ms_pid_alive() / ms_latest_run_file() / ms_read_run() / ms_active_run() live in
// includes/multisite/batch.php — the home panel reads runs too, so they are shared.

/** Launch run_campaign for one batch as a detached background process. Returns the run_id. */
function ms_launch_campaign(string $masterId, string $batchId, string $runsDir, string $flags): string {
    if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
    $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $out   = $runsDir . '/' . $runId . '.out';
    $cmd = 'setsid ' . escapeshellarg(ms_php_cli()) . ' ' . escapeshellarg(BASE_DIR . '/multisite/run_campaign.php')
         . ' ' . escapeshellarg($masterId) . ' --batch=' . escapeshellarg($batchId)
         . ' --run-id=' . escapeshellarg($runId) . ' --no-preflight' . $flags
         . ' > ' . escapeshellarg($out) . ' 2>&1 &';
    exec($cmd);
    return $runId;
}

/** Build run_campaign flags from a set of options. */
function ms_run_flags(array $o): string {
    $flags = ' --jobs=' . max(1, min(16, (int)($o['jobs'] ?? 1)));
    $rtr = max(0, min(5, (int)($o['retries'] ?? 0))); if ($rtr > 0) $flags .= ' --retries=' . $rtr;
    $lim = max(0, (int)($o['limit'] ?? 0));            if ($lim > 0) $flags .= ' --limit=' . $lim;
    if (!empty($o['no_ai']))     $flags .= ' --no-ai';
    if (!empty($o['force']))     $flags .= ' --force';
    // Generate keeps its builds instead of uploading them; step 5 sends them.
    if (!empty($o['no_deploy'])) $flags .= ' --no-deploy';
    // Optional steps turned off for this run. Whitelisted here as well as in
    // build_one.php so a hand-crafted POST cannot ask to skip the structural ones.
    if (!empty($o['skip'])) {
        // Two shapes are allowed: a whole step ("images"), or one piece inside a step
        // ("images.metadata"). The parent must still be a real step either way, so a
        // hand-crafted POST cannot ask to skip the structural ones or invent a key.
        $steps = ['landing', 'visual', 'ai', 'images', 'tags', 'structure'];
        $skip = [];
        foreach ((array) $o['skip'] as $k) {
            $k = (string) $k;
            $parent = explode('.', $k, 2)[0];
            if (!in_array($parent, $steps, true)) continue;
            if (!preg_match('/^[a-z]+(\.[a-z_]+)?$/', $k)) continue;
            $skip[] = $k;
        }
        $skip = array_values(array_unique($skip));
        if ($skip) $flags .= ' --skip=' . escapeshellarg(implode(',', $skip));
    }
    if (!empty($o['only']))  $flags .= ' --only=' . escapeshellarg(implode(',', (array)$o['only']));
    return $flags;
}

switch ($action) {

    // Mint a short-lived, single-use token for the FTP pre-flight SSE endpoint.
    // EventSource can't send a header or a POST body, so its auth has to travel in
    // the URL — putting the REAL session-wide csrf_token there (as this used to)
    // meant a leaked URL (server access logs, an intermediate proxy) exposed the
    // same secret every other admin POST trusts, not just this one read-only check.
    // This one is single-use, expires in 60s, and is good for nothing else.
    case 'preflight_token':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $pfToken = bin2hex(random_bytes(16));
        session_start();
        $_SESSION['ms_pf_token']     = $pfToken;
        $_SESSION['ms_pf_token_exp'] = time() + 60;
        session_write_close();
        echo json_encode(['token' => $pfToken]);
        break;

    // Download a ready-to-edit sample CSV (all columns, 5 example cities).
    case 'sample_csv':
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="multisite-sample.csv"');
        $cols = ['domain', 'business', 'phone', 'tel', 'email', 'address', 'city', 'state', 'SS', 'zip',
                 'lat', 'lng', 'rating', 'review_count', 'analytics_id', 'logo',
                 'ftp_host', 'ftp_port', 'ftp_user', 'ftp_pass', 'ftp_path', 'ftp_passive'];
        // Example rows — realistic format, obvious placeholders (replace before a real run).
        $sample = [
            ['pmtraining-dallas.com',    'Dallas PM Academy',      '214-555-0100', '+12145550100', 'info@pmtraining-dallas.com',    '100 Main St, Suite 400', 'Dallas',      'Texas',          'TX', '75201', '32.7767',  '-96.7970', '4.8', '126', 'G-XXXXXXXXX1', '', 'ftp.pmtraining-dallas.com',    '21', 'deploy@pmtraining-dallas.com',    'CHANGEME', '/public_html', '1'],
            ['pmtraining-austin.com',    'Austin PM Academy',      '512-555-0100', '+15125550100', 'info@pmtraining-austin.com',    '200 Congress Ave',       'Austin',      'Texas',          'TX', '78701', '30.2672',  '-97.7431', '4.9', '203', 'G-XXXXXXXXX2', '', 'sftp.pmtraining-austin.com',   '22', 'deploy@pmtraining-austin.com',    'CHANGEME', '/public_html', '1'],
            ['pmtraining-charlotte.com', 'Charlotte PM Academy',   '704-555-0100', '+17045550100', 'info@pmtraining-charlotte.com', '300 Tryon St',           'Charlotte',   'North Carolina', 'NC', '28202', '35.2271',  '-80.8431', '4.7', '88',  'G-XXXXXXXXX3', '', 'ftp.pmtraining-charlotte.com', '21', 'deploy@pmtraining-charlotte.com', 'CHANGEME', '/public_html', '1'],
            ['pmtraining-tampa.com',     'Tampa PM Academy',       '813-555-0100', '+18135550100', 'info@pmtraining-tampa.com',     '400 Ashley Dr',          'Tampa',       'Florida',        'FL', '33602', '27.9506',  '-82.4572', '4.8', '154', 'G-XXXXXXXXX4', '', 'ftp.pmtraining-tampa.com',     '21', 'deploy@pmtraining-tampa.com',     'CHANGEME', '/public_html', '1'],
            ['pmtraining-phoenix.com',   'Phoenix PM Academy',     '602-555-0100', '+16025550100', 'info@pmtraining-phoenix.com',   '500 Central Ave',        'Phoenix',     'Arizona',        'AZ', '85004', '33.4484',  '-112.0740','4.6', '71',  'G-XXXXXXXXX5', '', 'ftp.pmtraining-phoenix.com',   '21', 'deploy@pmtraining-phoenix.com',   'CHANGEME', '/public_html', '1'],
        ];
        // Optional landing_cities column — service landing pages this deploy also gets.
        // Format: "City, ST; City, ST". Blank = none (home + core pages only).
        $cols[] = 'landing_cities';
        $landingExamples = ['Plano, TX; Irving, TX; Frisco, TX', 'Round Rock, TX; Cedar Park, TX', '', '', ''];
        foreach ($sample as $i => &$row) { $row[] = $landingExamples[$i] ?? ''; }
        unset($row);
        // Optional gsc_verification — Google Search Console token per site (blank = no tag).
        // Paste each site's token from Search Console → the <meta google-site-verification> tag.
        $cols[] = 'gsc_verification';
        foreach ($sample as &$row) { $row[] = ''; }
        unset($row);
        // Optional theme_preset — visual palette per site (preset id or name from the master's
        // theme_presets.json). Blank = auto-spread by domain hash so every site still differs.
        $cols[] = 'theme_preset';
        $presetExamples = ['1', 'Bold', '', '', 'Classic'];
        foreach ($sample as $i => &$row) { $row[] = $presetExamples[$i] ?? ''; }
        unset($row);
        // Optional ftp_protocol — "ftp" (default) or "sftp" (upload over SSH, port 22).
        // Blank = ftp. When sftp, ftp_path is relative to the login user's home dir.
        $cols[] = 'ftp_protocol';
        $protoExamples = ['ftp', 'sftp', '', '', 'ftp'];
        foreach ($sample as $i => &$row) { $row[] = $protoExamples[$i] ?? ''; }
        unset($row);
        // Star the columns that must be filled on every row. Purely a hint for whoever
        // fills the sheet in — ms_parse_csv() strips the star on the way back, so the
        // sample can be edited and re-uploaded untouched.
        $starred = array_map(fn($c) => in_array($c, MS_REQUIRED_COLS, true) ? $c . '*' : $c, $cols);

        $out = fopen('php://output', 'w');
        fputcsv($out, $starred);
        foreach ($sample as $row) fputcsv($out, $row);
        fclose($out);
        exit;

    // Current stored params.csv state (tab load).
    /* The live fleet, plus this batch's plan for it.
     *
     * Reads the Infrastructure console's registry and per-box discovery directly —
     * NOT through admin/infra/bootstrap.php, which redirects when it cannot see a
     * session and would turn this JSON endpoint into a 302. The libraries under
     * admin/infra/lib are self-contained by design; this is what that buys.
     *
     * "sites" is what is ON the box right now, read from the box. The plan says
     * where sites are MEANT to go. They are reported separately because the case
     * worth seeing is when they disagree. */
    case 'servers':
        // Reads the Infrastructure console's fleet reader directly — NOT through
        // admin/infra/bootstrap.php, which redirects when it cannot see a session and
        // would turn this JSON endpoint into a 302. The libraries under
        // admin/infra/lib are self-contained by design; this is what that buys.
        require_once __DIR__ . '/infra/lib/hestia_fleet.php';
        // Plain page loads render the last stored answer only — never touch the
        // network (infra_hestia_fleet() is TTL-gated, not cache-only, so a page
        // load landing after any box's 180s cache expired was doing a live sweep
        // here on every batch.php open). The explicit "Re-read fleet" button sends
        // refresh=1 to force a real look, same as the Infra console's Refresh.
        $fleetRows = !empty($_GET['refresh']) ? infra_hestia_fleet(0) : infra_hestia_fleet_cached();
        $fleet = [];
        foreach ($fleetRows as $b) {
            $fleet[] = [
                'server_id' => $b['id'],   'label'   => $b['label'],
                'host'      => $b['host'], 'notes'   => $b['server']['notes'] ?? '',
                'up'        => $b['ok'],   'pending' => $b['pending'],
                'error'     => $b['error'],
                // What is ON the box, read from the box. The plan below says where
                // sites are MEANT to go; they are reported apart because the case
                // worth seeing is when they disagree.
                'sites'     => $b['deployed'],
            ];
        }
        echo json_encode([
            'fleet'   => $fleet,
            'plan'    => ms_batch_servers($masterId, $batchId),
            'targets' => ms_batch_target_count($masterId, $batchId),
        ]);
        break;

    /* Phase 2 — create the host area + FTP account for every row that lacks one.
     *
     * Detached like 'run' and 'research': provisioning fifty domains is minutes of
     * work, and a synchronous request would time out somewhere in the middle with
     * half the boxes done and nothing written back. */
    case 'create_hosts':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!is_file($paramsPath)) { echo json_encode(['error' => 'No target list stored — upload it first.']); break; }
        if (!ms_batch_servers($masterId, $batchId)) { echo json_encode(['error' => 'No deployment servers picked — choose them above first.']); break; }
        $hArgs = [$masterId, '--batch=' . $batchId];
        if (!empty($_POST['force'])) $hArgs[] = '--force';
        echo json_encode(ms_launch_job($batchDir . '/hosts', '__MS_HOSTS_DONE__', 'Host creation is already running.',
            BASE_DIR . '/multisite/create_hosts.php', $hArgs));
        break;

    /* Phase 5 — upload what has already been generated. Detached like the others. */
    case 'upload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!ms_batch_built($masterId, $batchId)) { echo json_encode(['error' => 'Nothing generated yet — run Generate sites first.']); break; }
        $uArgs = [$masterId, '--batch=' . $batchId];
        if (!empty($_POST['force'])) $uArgs[] = '--force';
        $ulim = max(0, (int) ($_POST['limit'] ?? 0)); if ($ulim > 0) $uArgs[] = '--limit=' . $ulim;
        $uOnly = trim((string) ($_POST['only'] ?? ''));
        if ($uOnly !== '') $uArgs[] = '--only=' . $uOnly;
        // Wipe is refused here too, not just in upload_sites.php's own check — a batch-
        // wide wipe should never be one unchecked box away, panel or CLI.
        if (!empty($_POST['wipe'])) {
            if ($uOnly === '') { echo json_encode(['error' => 'Wipe requires "Only this domain" — it never runs against the whole batch.']); break; }
            $uArgs[] = '--wipe';
        }
        echo json_encode(ms_launch_job($batchDir . '/uploads', '__MS_UPLOAD_DONE__', 'An upload is already running.',
            BASE_DIR . '/multisite/upload_sites.php', $uArgs));
        break;

    case 'upload_status':
        $run = ms_job_resolve_run($batchDir . '/uploads', (string) ($_REQUEST['run_id'] ?? ''));
        if (!$run) { echo json_encode(['none' => true]); break; }
        $j = ms_job_read_out($run['path'], '__MS_UPLOAD_DONE__');
        // Progress comes from parsing upload_sites.php's own printed lines — it has no
        // separate machine-readable channel, so the header count and the per-domain
        // ✓/✗ markers ARE the source of truth for "how far along is this".
        $total = 0;
        if (preg_match('/(\d+) ready to upload/', $j['raw'], $m)) $total = (int) $m[1];
        preg_match_all('/file\(s\)\s*…\s*(✓|✗)/u', $j['raw'], $mm);
        $marks  = $mm[1] ?? [];
        $ok     = count(array_filter($marks, fn($c) => $c === '✓'));
        $failed = count($marks) - $ok;
        // A row with no ✓/✗ mark at all once the run is actually done (marker
        // present) is exactly as much a failure as an explicit ✗ — without this,
        // 8 of 10 domains never uploaded because the process died could still show
        // a partial bar with "0 failed" and no error at all.
        if ($j['done'] && $total > count($marks)) $failed += ($total - count($marks));
        echo json_encode([
            'run_id'    => $run['run_id'],
            'done'      => $j['done'],
            'exit'      => $j['exit'],
            'total'     => $total,
            'processed' => count($marks),
            'ok'        => $ok,
            'failed'    => $failed,
            'log'       => $j['log'],
        ]);
        break;

    /* What is generated and waiting, so the upload panel can say so before you press. */
    case 'built':
        $rows = ms_parse_csv($paramsPath)['rows'] ?? [];
        $built = ms_batch_built($masterId, $batchId);
        $n = ['ready' => 0, 'no_build' => 0, 'no_creds' => 0];
        foreach ($rows as $r) {
            $d = strtolower(trim((string) ($r['domain'] ?? ''))); if ($d === '') continue;
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', $d), '_');
            if (!isset($built[$slug]))                          { $n['no_build']++; continue; }
            if (trim((string) ($r['ftp_host'] ?? '')) === ''
                || trim((string) ($r['ftp_user'] ?? '')) === '') { $n['no_creds']++; continue; }
            $n['ready']++;
        }
        echo json_encode($n + ['total' => count($rows)]);
        break;

    /* Phase 6 — go live. Wraps the Infra console's own per-domain pipeline
     * (admin/infra/lib/pipeline.php / golive.php) rather than re-implementing any
     * Cloudflare or registrar logic here — the "batch" tag written by create_hosts.php
     * is what lets infra_pipeline_rows() find exactly this batch's domains. These
     * actions call the pipeline library directly (not admin/infra/actions/pipeline_golive.php,
     * which is built to redirect back to the Bulk tab's own page) and ride this file's
     * own CSRF gate above, same as every other POST action here. */
    case 'golive_status':
        require_once __DIR__ . '/infra/lib/pipeline.php';
        $tag = $masterId . '/' . $batchId;
        $out = [];
        foreach (infra_pipeline_rows($tag) as $r) {
            $c = $r['cells'];
            $cell = fn(string $k) => ['state' => $c[$k]['state'] ?? '', 'note' => $c[$k]['note'] ?? '', 'at' => (int) ($c[$k]['at'] ?? 0)];
            $out[] = [
                'domain'    => $r['domain'],
                'zone'      => $cell('zone'),
                'golive'    => $cell('golive'),
                'dns'       => $cell('dns'),
                'live'      => $cell('live'),
                // Go Live's own gate reads the Upload cell — surfaced here so the button
                // can explain itself instead of just failing when pressed.
                'upload_ok' => ($c['upload']['state'] ?? '') === INFRA_STEP_OK,
            ];
        }
        echo json_encode(['rows' => $out]);
        break;

    // One step, one domain: 'zone' (stage/restore the Cloudflare zone) or 'golive'
    // (switch the nameservers — the real public cutover).
    case 'golive_do':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        require_once __DIR__ . '/infra/lib/pipeline.php';
        $gStep = (string) ($_POST['step'] ?? '');
        $gDom  = strtolower(trim((string) ($_POST['domain'] ?? '')));
        if (!in_array($gStep, ['zone', 'golive'], true)) { echo json_encode(['error' => 'Unknown step.']); break; }
        if ($gDom === '') { echo json_encode(['error' => 'Missing domain.']); break; }
        set_time_limit(0);
        echo json_encode(infra_pipeline_do($gStep, $gDom, $masterId . '/' . $batchId));
        break;

    // Spread this batch's not-yet-live domains into daily releases (N/day from a
    // start date) for the nightly cron to pick up — the one thing card 6 could not
    // do without leaving for the Bulk tab's pipeline grid, which has had this
    // since the grid was built. Same underlying call, same gate.
    case 'golive_schedule':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        require_once __DIR__ . '/infra/lib/golive.php';
        $gPerDay = max(1, (int) ($_POST['per_day'] ?? 10));
        $gStart  = trim((string) ($_POST['start_date'] ?? ''));
        $r = infra_golive_schedule($gPerDay, $gStart, ['batch' => $masterId . '/' . $batchId, 'gate' => true]);
        echo json_encode($r);
        break;

    // The same step for every row in this batch that still needs it — the "▶ run
    // all" header buttons. Greens are skipped; safe (and how you resume) to press twice.
    //
    // Detached like create_hosts/upload, not run in-request: this can mean a registrar
    // NS-switch call per domain, and a slow registrar or a proxy timeout used to be able
    // to kill the whole batch mid-run with no record of which domains it got through —
    // the one bulk action on this page that still had that gap.
    case 'golive_run':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $gStep = (string) ($_POST['step'] ?? '');
        if (!in_array($gStep, ['zone', 'golive'], true)) { echo json_encode(['error' => 'Unknown step.']); break; }
        echo json_encode(ms_launch_job($batchDir . '/golive', '__MS_GOLIVE_DONE__', 'A "run all" for this batch is already in progress.',
            BASE_DIR . '/multisite/golive_run.php', [$masterId, '--batch=' . $batchId, '--step=' . $gStep]));
        break;

    case 'golive_run_status':
        $run = ms_job_resolve_run($batchDir . '/golive', (string) ($_REQUEST['run_id'] ?? ''));
        if (!$run) { echo json_encode(['none' => true]); break; }
        $j = ms_job_read_out($run['path'], '__MS_GOLIVE_DONE__');
        // One line per domain, printed by golive_run.php as it goes: "  ✓ domain msg",
        // "  ✗ domain msg", or "  ⧗ domain msg" (blocked on an earlier step) — parsed
        // here so a partial or crashed run still shows exactly which domains it reached
        // and which of those failed, not just a final count that never arrives.
        preg_match_all('/^ {2}(✓|✗|⧗) (\S+)/mu', $j['raw'], $mm, PREG_SET_ORDER);
        $failedDomains = [];
        foreach ($mm as $m) if ($m[1] !== '✓') $failedDomains[] = $m[2];
        echo json_encode([
            'run_id'         => $run['run_id'],
            'done'           => $j['done'],
            'exit'           => $j['exit'],
            'processed'      => count($mm),
            'failed_domains' => $failedDomains,
            'log'            => $j['log'],
        ]);
        break;

    // "Take offline" — removes the domain's Cloudflare A record (fast, and does not
    // touch nameservers), so a live site can be pulled back without hours of DNS
    // propagation. Re-running the zone step above restores the same record.
    case 'golive_offline':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        require_once __DIR__ . '/infra/lib/golive.php';
        require_once __DIR__ . '/infra/lib/pipeline.php';
        $gDom = strtolower(trim((string) ($_POST['domain'] ?? '')));
        if ($gDom === '') { echo json_encode(['error' => 'Missing domain.']); break; }
        set_time_limit(0);
        // Locked against the 'zone' step — take-offline and Create zone both write
        // the same Cloudflare A record, and this was the one golive_* mutation left
        // unlocked when the others were closed (v58): a near-simultaneous click of
        // both on the same domain could interleave delete-then-create in either order.
        echo json_encode(infra_pipeline_lock($gDom, 'zone', fn() => infra_golive_take_offline($gDom)));
        break;

    // Re-check one cell without acting — the per-column ↻, mainly for 'live' since
    // nothing else writes that cell on its own. Still a real state-mutating write
    // (infra_pipeline_refresh persists the fresh check to domain_step), so this needs
    // the same POST-required guard every sibling golive_* action has above — without
    // it, this was the one action here that never reached the file's CSRF check
    // (which only fires on POST), reachable via a plain GET/CSRF-forgeable request.
    case 'golive_refresh':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        require_once __DIR__ . '/infra/lib/pipeline.php';
        $gStep = (string) ($_POST['step'] ?? 'live');
        $gDom  = strtolower(trim((string) ($_POST['domain'] ?? '')));
        if (!in_array($gStep, infra_pipeline_step_keys(), true)) { echo json_encode(['error' => 'Unknown step.']); break; }
        set_time_limit(0);
        echo json_encode(infra_pipeline_refresh($gStep, $masterId . '/' . $batchId, $gDom));
        break;

    case 'create_hosts_status':
        $run = ms_job_resolve_run($batchDir . '/hosts', (string) ($_REQUEST['run_id'] ?? ''));
        if (!$run) { echo json_encode(['none' => true]); break; }
        $j = ms_job_read_out($run['path'], '__MS_HOSTS_DONE__');
        echo json_encode(['run_id' => $run['run_id'], 'done' => $j['done'], 'exit' => $j['exit'], 'log' => $j['log']]);
        break;

    case 'save_servers':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $plan = json_decode((string) ($_POST['plan'] ?? '[]'), true);
        if (!is_array($plan)) { echo json_encode(['error' => 'Bad plan.']); break; }
        echo json_encode(ms_save_batch_servers($masterId, $batchId, $plan));
        break;

    case 'status':
        if (!is_file($paramsPath)) { echo json_encode(['stored' => false]); break; }
        $parsed = ms_parse_csv($paramsPath);
        if ($parsed['error']) { echo json_encode(['stored' => true, 'error' => $parsed['error']]); break; }
        $v = ms_validate_rows($parsed['rows'], $parsed['header']);
        echo json_encode(['stored' => true] + ms_validation_payload($v));
        break;

    // Upload a CSV → validate → store only if error-free.
    case 'upload_csv':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            echo json_encode(['error' => 'No file uploaded.']); break;
        }
        $tmp = $_FILES['csv']['tmp_name'];
        if (filesize($tmp) > 2 * 1024 * 1024) { echo json_encode(['error' => 'File too large (max 2 MB).']); break; }

        $parsed = ms_parse_csv($tmp);
        if ($parsed['error']) { echo json_encode(['error' => 'CSV error: ' . $parsed['error']]); break; }
        // Swap any masked (__KEEP__) passwords back to the stored real ones before validating.
        $rows = ms_rehydrate_ftp_pass($parsed['rows'], $paramsPath);
        $v = ms_validate_rows($rows, $parsed['header']);

        $stored = false;
        if ($v['error'] === 0 && count($v['rows']) > 0) {
            $rehydrated = tempnam(sys_get_temp_dir(), 'mscsv');
            ms_write_csv($rehydrated, $parsed['header'], $rows);
            ms_store_params_csv($batchDir, $rehydrated);
            @unlink($rehydrated);
            $stored = true;
        }
        echo json_encode(['stored' => $stored, 'filename' => basename($_FILES['csv']['name'] ?? 'upload.csv')] + ms_validation_payload($v));
        break;

    // Download the current stored params.csv with FTP passwords masked (__KEEP__).
    // Re-uploading the file preserves the real passwords (matched by domain).
    case 'download_csv':
        if (!is_file($paramsPath)) { http_response_code(404); echo json_encode(['error' => 'No params.csv stored — upload it first.']); break; }
        $parsed = ms_parse_csv($paramsPath);
        if ($parsed['error']) { http_response_code(400); echo json_encode(['error' => $parsed['error']]); break; }
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="params-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $masterId) . '.csv"');
        ms_write_csv('php://output', $parsed['header'], ms_mask_ftp_pass($parsed['rows']));
        exit;

    // Verification preview: resolve the MASTER's actual page titles against a sample params
    // row — so the operator sees exactly what the cloned sites will publish. Read-only; the
    // titles live in each page's SEO panel (single source of truth), not here.
    case 'preview_titles':
        // Sample row: first error-free params row, else a placeholder.
        $row = null;
        if (is_file($paramsPath)) {
            $p = ms_parse_csv($paramsPath);
            if (!$p['error']) {
                $v = ms_validate_rows($p['rows'], $p['header']);
                foreach ($v['rows'] as $r) { if (!$r['errors']) { $row = $r['data']; break; } }
            }
        }
        $placeholder = $row === null;
        if ($row === null) $row = ['domain' => 'example-city.com', 'business' => 'Example City Pros', 'city' => 'Dallas', 'SS' => 'TX', 'state' => 'Texas'];

        $mf = ACTIVE_SITE_DIR . '/data/site.json';
        $md = is_file($mf) ? json_decode(file_get_contents($mf), true) : null;
        if (!is_array($md)) { echo json_encode(['error' => 'Could not read the master site.']); break; }

        $resolve = function (array $seo, string $label) use ($row) {
            $raw = trim($seo['seo_title'] ?? '');
            $pk  = trim($seo['primary_keyword'] ?? '');
            return [
                'label'     => $label,
                'has_title' => $raw !== '',
                'resolved'  => $raw !== '' ? ms_render_pattern($raw, $row, $pk) : ('(no title set — will use the site name)'),
            ];
        };

        $titles = [];
        $titles[] = $resolve($md['seo'] ?? [], 'Homepage');
        foreach (($md['pages'] ?? []) as $pg) {
            $label = trim($pg['title'] ?? '') !== '' ? $pg['title'] : ('/' . ($pg['slug'] ?? '?'));
            $titles[] = $resolve($pg['seo'] ?? [], $label);
        }

        // Which section layout this sample domain gets (2a), if enabled on the homepage.
        $layout = null;
        if (!empty($md['layout_enabled']) && !empty($md['layout_variants']) && function_exists('ms_variant')) {
            $n = 1 + count($md['layout_variants']);
            $layout = ['index' => ms_variant($row['domain'] ?? '', $n, 'layout') + 1, 'total' => $n];
        }
        echo json_encode(['sample_domain' => $row['domain'] ?? '', 'is_placeholder' => $placeholder, 'titles' => $titles, 'layout' => $layout]);
        break;

    // List saved upload versions (last 15), newest first.
    case 'list_versions':
        echo json_encode(['versions' => ms_list_params_versions($batchDir)]);
        break;

    // Download one saved version, FTP masked.
    case 'download_version':
        $id = (string)($_GET['id'] ?? '');
        if (!ms_valid_version_id($id)) { http_response_code(400); echo json_encode(['error' => 'Invalid version id.']); break; }
        $vf = $batchDir . '/params_versions/' . $id . '.csv';
        if (!is_file($vf)) { http_response_code(404); echo json_encode(['error' => 'Version not found.']); break; }
        $parsed = ms_parse_csv($vf);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="params-' . $id . '.csv"');
        ms_write_csv('php://output', $parsed['header'], ms_mask_ftp_pass($parsed['rows']));
        exit;

    // Restore a saved version as the current params.csv (real passwords, re-validated).
    case 'restore_version':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $id = (string)($_POST['id'] ?? '');
        if (!ms_valid_version_id($id)) { echo json_encode(['error' => 'Invalid version id.']); break; }
        $vf = $batchDir . '/params_versions/' . $id . '.csv';
        if (!is_file($vf)) { echo json_encode(['error' => 'Version not found.']); break; }
        $parsed = ms_parse_csv($vf);
        if ($parsed['error']) { echo json_encode(['error' => $parsed['error']]); break; }
        $v = ms_validate_rows($parsed['rows'], $parsed['header']);
        if ($v['error'] > 0) { echo json_encode(['error' => 'That version has validation errors and was not restored.'] + ms_validation_payload($v)); break; }
        ms_store_params_csv($batchDir, $vf);
        echo json_encode(['restored' => true, 'stored' => true] + ms_validation_payload($v));
        break;

    // Launch a batch run as a detached background process.
    case 'run':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!is_file($paramsPath)) { echo json_encode(['error' => 'No target list stored — upload it first.']); break; }
        $rres = ms_with_launch_lock($runsDir . '/.lock', function () use ($runsDir, $masterId, $batchId) {
            if ($running = ms_active_run($runsDir)) return ['error' => 'This batch is already running.', 'run_id' => $running['run_id'] ?? null];
            $flags = ms_run_flags([
                'jobs' => $_POST['jobs'] ?? 1, 'retries' => $_POST['retries'] ?? 0, 'limit' => $_POST['limit'] ?? 0,
                'no_ai' => !empty($_POST['no_ai']), 'force' => !empty($_POST['force']),
                'skip'  => array_filter(array_map('trim', explode(',', (string) ($_POST['skip'] ?? '')))),
                'no_deploy' => !empty($_POST['no_deploy']),
                'only'  => trim((string) ($_POST['only'] ?? '')),
            ]);
            return ['started' => true, 'run_id' => ms_launch_campaign($masterId, $batchId, $runsDir, $flags)];
        });
        echo json_encode($rres);
        break;

    // Poll the latest run (or a specific run_id) for live progress.
    case 'run_status':
        $rid = $_GET['run_id'] ?? '';
        $file = ($rid !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $rid))
            ? $runsDir . '/' . $rid . '.json'
            : ms_latest_run_file($runsDir);
        $d = $file ? ms_read_run($file) : null;
        echo json_encode($d ?: ['none' => true]);
        break;

    // List recent runs (history).
    case 'list_runs':
        $files = glob($runsDir . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $runs = [];
        foreach (array_slice($files, 0, 30) as $f) {
            $d = ms_read_run($f);
            if (!$d) continue;
            $runs[] = [
                'run_id'      => $d['run_id'] ?? basename($f, '.json'),
                'params_version' => $d['params_version'] ?? '',
                'state'       => $d['state'] ?? '?',
                'started_at'  => $d['started_at'] ?? null,
                'finished_at' => $d['finished_at'] ?? null,
                'total'       => $d['total'] ?? 0, 'ok' => $d['ok'] ?? 0, 'failed' => $d['failed'] ?? 0,
                'cost'        => $d['totals']['cost_usd'] ?? 0,
            ];
        }
        echo json_encode(['runs' => $runs]);
        break;

    // Re-run only the failed rows of a past run (carrying its options forward).
    case 'retry_failed':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $rid = $_POST['run_id'] ?? '';
        if ($rid === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $rid)) { echo json_encode(['error' => 'Invalid run id.']); break; }
        $d = ms_read_run($runsDir . '/' . $rid . '.json');
        if (!$d) { echo json_encode(['error' => 'Run not found.']); break; }
        $failed = array_values(array_unique(array_map(fn($r) => $r['domain'], array_filter($d['results'] ?? [], fn($r) => ($r['status'] ?? '') === 'failed'))));
        if (!$failed) { echo json_encode(['error' => 'No failed rows to retry.']); break; }
        $fres = ms_with_launch_lock($runsDir . '/.lock', function () use ($runsDir, $masterId, $batchId, $d, $failed) {
            if ($running = ms_active_run($runsDir)) return ['error' => 'This batch is already running.', 'run_id' => $running['run_id'] ?? null];
            $o = $d['options'] ?? [];
            $o['only'] = $failed;   // scope the new run to just the failed domains
            return ['started' => true, 'run_id' => ms_launch_campaign($masterId, $batchId, $runsDir, ms_run_flags($o)), 'retrying' => count($failed)];
        });
        echo json_encode($fres);
        break;

    // ── Research step (item 1e): seed cities.json from params + niche-aware lookup.
    // Detached (research can be slow / many API calls); poll with research_status.
    case 'research':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!is_file($paramsPath)) { echo json_encode(['error' => 'No target list stored — upload it first.']); break; }
        $rArgs = [$masterId, '--batch=' . $batchId];
        if (!empty($_POST['dry_run'])) $rArgs[] = '--dry-run';
        echo json_encode(ms_launch_job($batchDir . '/research', '__MS_RESEARCH_DONE__', 'Research is already running.',
            BASE_DIR . '/multisite/research_cities.php', $rArgs));
        break;

    case 'research_status':
        $run = ms_job_resolve_run($batchDir . '/research', (string) ($_GET['run_id'] ?? ''));
        if (!$run) { echo json_encode(['none' => true]); break; }
        $j = ms_job_read_out($run['path'], '__MS_RESEARCH_DONE__');
        echo json_encode(['output' => $j['log'], 'done' => $j['done'], 'exit' => $j['exit'], 'run_id' => $run['run_id']]);
        break;

    // Master lint — flag authoring leaks (literal city/state/zip; master-domain URLs).
    case 'lint_master':
        echo json_encode(ms_lint_master($masterId));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
