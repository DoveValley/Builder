<?php
// AI generation trigger — streams generate.py output as NDJSON, then emits a done event.
// POST only. Requires admin auth + CSRF token.
//
// Each line: {"type":"line","text":"..."}
// Final line: {"type":"done","success":bool,"exit_code":int,"last_log":obj|null,"error":str|null}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Flush all existing output buffers so lines stream immediately
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/x-ndjson');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // tell nginx not to buffer

function ndjson_emit(array $obj): void {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush(); flush();
}

function ndjson_done(bool $ok, ?int $exitCode = null, ?array $lastLog = null, ?string $error = null): void {
    $r = ['type' => 'done', 'success' => $ok];
    if ($exitCode !== null) $r['exit_code'] = $exitCode;
    if ($lastLog  !== null) $r['last_log']  = $lastLog;
    if ($error    !== null) $r['error']     = $error;
    ndjson_emit($r);
}

/**
 * Is a research pass for this site ALREADY running -- started by EITHER launcher?
 *
 * Two entry points run the same work: this file (Cities tab, --site <id>) and
 * multisite/research_cities.php (Batch tab, --site-dir <path>/sites/<id>). Only the latter
 * had a concurrency guard, so the Cities-tab button would happily start a second
 * generate.py writing the same cities.json while the first was mid-pass. The process table
 * is the only place both launchers are visible -- a lock file taken here would not see a
 * run started over there, which is precisely the case that bit.
 *
 * Returns ['pid'=>int,'secs'=>int] for the live run, or null.
 *
 * Fails OPEN on purpose: if ps cannot be read we return null and let the run proceed. A
 * missed detection costs the old behaviour; a false positive costs a button that never works.
 */
function research_in_flight(string $siteId): ?array
{
    if ($siteId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $siteId)) return null;
    $ps = @shell_exec('ps -eo pid=,etimes=,args= 2>/dev/null');
    if (!is_string($ps) || trim($ps) === '') return null;
    $me = getmypid();
    $q  = preg_quote($siteId, '#');
    foreach (explode("\n", $ps) as $ln) {
        if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', $ln, $m)) continue;
        $pid = (int) $m[1];
        $secs = (int) $m[2];
        $args = $m[3];
        if ($pid === $me) continue;
        if (strpos($args, 'generate.py') === false) continue;
        if (strpos($args, '--research-only') === false) continue;
        // sh -c wrappers carry the same args, so the child and its shell both match;
        // either one is proof a pass is live, and we only report the first.
        if (!preg_match('#--site\s+\x27?' . $q . '\x27?(\s|$)#', $args)
            && !preg_match('#--site-dir\s+\x27?[^\s\x27]*/sites/' . $q . '\x27?(\s|$)#', $args)) {
            continue;
        }
        return ['pid' => $pid, 'secs' => $secs];
    }
    return null;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    ndjson_done(false, null, null, 'Not authenticated.');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ndjson_done(false, null, null, 'POST required.');
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    ndjson_done(false, null, null, 'Invalid request token.');
    exit;
}

if (!ACTIVE_SITE_ID) {
    ndjson_done(false, null, null, 'No active site selected.');
    exit;
}

// ── Parse options ─────────────────────────────────────────────────────────────
$action        = $_POST['action']   ?? 'generate';  // generate | research | sync
$cityId        = trim($_POST['city_id'] ?? '');
$tag           = trim($_POST['tag'] ?? '');
$scope         = in_array($_POST['scope'] ?? '', ['homepage', 'landing', 'core', 'all'], true) ? $_POST['scope'] : 'landing';
$research      = !empty($_POST['research']);
$refresh       = !empty($_POST['refresh']);
$dryRun        = !empty($_POST['dry_run']);
$force         = !empty($_POST['force']);
// Each defaults to checked in the tab (see admin/tabs/ai.php ai-reword-wrap) so an
// unmodified form keeps today's behavior — only an explicit uncheck skips a pass.
$skipLegal       = empty($_POST['legal_reword']);
$skipDisclaimer  = empty($_POST['disclaimer_reword']);
$skipTagline     = empty($_POST['tagline_reword']);
$skipPopup       = empty($_POST['popup_reword']);
$skipBlog        = empty($_POST['blog_posts']);
$modelOverride = '';
$_mo = trim($_POST['model_override'] ?? '');
if (model_is_valid($_mo)) {
    $modelOverride = $_mo;
}

// Sanitize city_id / tag: allow only safe slugs
if ($cityId && !preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $cityId)) {
    $cityId = '';
}
if ($tag && !preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $tag)) {
    $tag = '';
}

// ── API key (not needed for sync-templates) ───────────────────────────────────
$apiKey = ANTHROPIC_API_KEY;
if (!$apiKey && $action !== 'sync') {
    ndjson_done(false, null, null, 'ANTHROPIC_API_KEY is not configured. Add it to config.php or set it as a server environment variable.');
    exit;
}

// ── Build command ─────────────────────────────────────────────────────────────
$python = _find_python();
if (!$python) {
    ndjson_done(false, null, null, 'python3 not found in PATH.');
    exit;
}

$script = BASE_DIR . '/generate.py';
if (!file_exists($script)) {
    ndjson_done(false, null, null, 'generate.py not found.');
    exit;
}

$parts = [escapeshellarg($python), '-u', escapeshellarg($script), '--site', escapeshellarg(ACTIVE_SITE_ID)];

// BOTH research actions write cities.json, so both must refuse to run while a pass is in
// flight — see research_in_flight() for why this is a ps scan and not a lock file.
if ($action === 'research' || $action === 'research_gaps') {
    $rif = research_in_flight((string) ACTIVE_SITE_ID);
    if ($rif !== null) {
        $ago = $rif['secs'] >= 60 ? intdiv($rif['secs'], 60) . ' min' : $rif['secs'] . ' sec';
        ndjson_done(false, null, null,
            'Research is already running for ' . ACTIVE_SITE_ID . ' — started ' . $ago
            . ' ago (pid ' . $rif['pid'] . '). Let it finish: a second pass would have '
            . 'two processes writing cities.json. Watch progress on the Batch tab.');
        exit;
    }
}

switch ($action) {
    case 'sync':
        $parts[] = '--sync-templates';
        if ($dryRun) $parts[] = '--dry-run';
        break;

    case 'research_gaps':
        // Neighbourhood-only retry: clears the verified marker on cities below min_items and
        // re-queries OSM for just those. Separate action rather than a flag on 'research'
        // because it must NOT run the research prompt — see --neighborhoods-retry.
        $parts[] = '--research-only';
        $parts[] = '--neighborhoods-retry';
        if ($dryRun) $parts[] = '--dry-run';
        break;

    case 'research':
        // ai_generate.php had no concurrency guard at all, while the Batch tab's launcher
        // (ms_launch_job) has always had one. So this button would start a SECOND
        // generate.py against the same cities.json whenever a pass was already in flight,
        // and the only visible symptom was "Researching... Ns" counting up while nothing
        // changed. Refuse, and say what is running and for how long -- the client renders
        // a done-event's `error` in red, so this reaches the operator as a real message.
        $parts[] = '--research-only';
        if ($cityId) { $parts[] = '--file'; $parts[] = escapeshellarg($cityId); }
        if ($tag)    { $parts[] = '--tag';  $parts[] = escapeshellarg($tag); }
        if ($dryRun) $parts[] = '--dry-run';
        // Same --research-force generate.py flag the multisite Batch tab's Force checkbox
        // uses — re-researches even a city already fully on file, instead of only filling gaps.
        if ($force)  $parts[] = '--research-force';
        break;

    default: // generate
        if ($scope === 'all') {
            $parts[] = '--all';
        } else {
            // homepage | core | landing all map to --page <scope>
            $parts[] = '--page'; $parts[] = $scope;
        }
        if ($research)       $parts[] = '--research';
        if ($refresh)        $parts[] = '--refresh';
        // --file/--tag only filter landing pages by city; irrelevant for homepage/core scopes.
        if ($cityId && ($scope === 'landing' || $scope === 'all')) { $parts[] = '--file'; $parts[] = escapeshellarg($cityId); }
        if ($tag    && ($scope === 'landing' || $scope === 'all')) { $parts[] = '--tag';  $parts[] = escapeshellarg($tag); }
        if ($dryRun)         $parts[] = '--dry-run';
        if ($modelOverride)  { $parts[] = '--model'; $parts[] = escapeshellarg($modelOverride); }
        // Only take effect for core/all scope (generate.py's own gate), but harmless to
        // always pass — lets the operator skip any of the 4 one-time reword passes or
        // blog generation instead of them silently firing with no way to opt out.
        if ($skipLegal)      $parts[] = '--no-legal-reword';
        if ($skipDisclaimer) $parts[] = '--no-disclaimer-reword';
        if ($skipTagline)    $parts[] = '--no-tagline-reword';
        if ($skipPopup)      $parts[] = '--no-popup-reword';
        if ($skipBlog)       $parts[] = '--no-blog';
        break;
}

// Merge stderr into stdout so everything appears on one pipe
$cmd = implode(' ', $parts) . ' 2>&1';

// ── Run ───────────────────────────────────────────────────────────────────────
set_time_limit(1800); // 30 minutes — long runs (25 pages × 4 blocks) can take 15–20 min

$env = _build_env($apiKey);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'], // required by proc_open; not used because 2>&1 merges into 1
];

$startMs = intval(microtime(true) * 1000);
$process = proc_open($cmd, $descriptors, $pipes, BASE_DIR, $env);

if (!is_resource($process)) {
    ndjson_done(false, null, null, 'Failed to start generate.py.');
    exit;
}

fclose($pipes[0]);
fclose($pipes[2]); // stderr merged into stdout via 2>&1

// Stream stdout one line at a time — fgets() blocks until a full line or pipe closes
while (!feof($pipes[1])) {
    $line = fgets($pipes[1]);
    if ($line === false) break;
    $clean = preg_replace('/\033\[[0-9;]*m/', '', rtrim($line));
    if ($clean === '') continue;
    // Progress marker emitted by generate.py — route as typed event, not log line
    if (str_starts_with($clean, '__PROGRESS__ ')) {
        $frac = substr($clean, 13); // "D/T"
        [$done, $tot] = array_map('intval', explode('/', $frac, 2));
        ndjson_emit(['type' => 'progress', 'done' => $done, 'total' => $tot]);
    } elseif (str_starts_with($clean, '__WORKERS__ ')) {
        // Effective worker count — tells the UI how many per-worker bars to draw
        ndjson_emit(['type' => 'workers_init', 'count' => (int) substr($clean, 12)]);
    } elseif (str_starts_with($clean, '__WORKER__ ')) {
        // "slot done total page" — one worker's current page + block progress
        $p = explode(' ', substr($clean, 11), 4);
        if (count($p) === 4) {
            ndjson_emit([
                'type'  => 'worker',
                'slot'  => (int) $p[0],
                'done'  => (int) $p[1],
                'total' => (int) $p[2],
                'page'  => $p[3],
            ]);
        }
    } else {
        ndjson_emit(['type' => 'line', 'text' => $clean]);
    }
}

fclose($pipes[1]);
$exitCode = proc_close($process);

// ── Read last log entry for stats ─────────────────────────────────────────────
$lastLog = null;
$logFile = GEN_LOG_FILE;
if ($action !== 'sync' && file_exists($logFile)) {
    $raw = json_decode(file_get_contents($logFile), true);
    if (is_array($raw) && !empty($raw)) {
        $lastLog = end($raw);
    }
}

ndjson_done(
    $exitCode === 0,
    $exitCode,
    $lastLog,
    $exitCode !== 0 ? "Process exited with code $exitCode" : null
);
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

function _find_python(): string {
    foreach (['python3', '/usr/bin/python3', '/usr/local/bin/python3'] as $p) {
        if (@is_executable($p) || trim((string)@shell_exec("which $p 2>/dev/null"))) {
            return $p;
        }
    }
    return '';
}

function _build_env(string $apiKey): array {
    $base = [];
    foreach (['PATH', 'HOME', 'USER', 'LANG', 'PYTHONPATH', 'VIRTUAL_ENV'] as $k) {
        $v = getenv($k);
        if ($v !== false) $base[$k] = $v;
    }
    // Ensure a sane PATH that includes common Python install locations
    $base['PATH'] = $base['PATH'] ?? '/usr/bin:/usr/local/bin:/bin:/usr/sbin:/sbin';
    $base['PYTHONUNBUFFERED'] = '1'; // force Python to flush stdout immediately (no pipe buffering)
    $base['ANTHROPIC_API_KEY'] = $apiKey;
    return $base;
}
