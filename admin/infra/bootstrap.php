<?php
/**
 * infra/bootstrap.php — the ONLY factory touchpoint: shares the admin login
 * session via config.php (session_start + auth). No factory domain logic is used.
 * Everything else in admin/infra/ is self-contained.
 */
require_once __DIR__ . '/../../config.php';   // session_start() + ADMIN_* constants

if (empty($_SESSION['admin_logged_in'])) {
    // Absolute path, not '../login.php' — this file is included from both
    // admin/infra/ and admin/infra/actions/, and the relative form resolved to a
    // non-existent admin/infra/login.php (404) for every action handler.
    $adminBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if (basename($adminBase) === 'infra') $adminBase = dirname($adminBase);
    header('Location: ' . ($adminBase ?: '/admin') . '/login.php');
    exit;
}

if (empty($_SESSION['infra_csrf'])) {
    $_SESSION['infra_csrf'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/hestia_fleet.php'; // the fleet: registry + client. Loaded here
                                               // because provisioning, Deploy, New Site and
                                               // Bulk all need it, not just the Servers tab.
require_once __DIR__ . '/lib/cloudflare.php';
require_once __DIR__ . '/lib/state.php';
require_once __DIR__ . '/lib/cache.php';
require_once __DIR__ . '/lib/uptime.php';
require_once __DIR__ . '/lib/fleet.php';
require_once __DIR__ . '/lib/registrar.php';
require_once __DIR__ . '/lib/golive.php';

function ih($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function infra_csrf(): string { return $_SESSION['infra_csrf']; }
function infra_check_csrf(): bool { return isset($_POST['csrf']) && hash_equals($_SESSION['infra_csrf'] ?? '', $_POST['csrf']); }

/**
 * Let go of the session file so the rest of the console stays usable.
 *
 * PHP takes an EXCLUSIVE lock on the session file in session_start() and holds it
 * until the request ends. One slow request therefore blocks every other request
 * from the same browser — so starting an availability check (Porkbun is one name
 * per ten seconds) or a Cities sweep (a hundred-second budget) and then clicking
 * a tab made the console look hung. It was not hung: the tab was queued behind
 * the job. Measured: 5.02s for a nav click during a slow request, 0.006s without.
 *
 * Call this once the session has been READ — after the auth gate and the CSRF
 * check, which is everything most handlers need it for. The work that follows
 * runs unlocked.
 *
 * ⚠ After this, writing to $_SESSION is SILENTLY DISCARDED. Call
 * infra_session_resume() before writing (infra_set_flash() does it for you).
 */
function infra_session_release(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

/** Re-open the session, briefly, to write a result back. */
function infra_session_resume(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // Re-opening once output has started would try to send the session cookie
    // again and print a warning into the middle of the response. Handlers that
    // stream (bulk_run) do not touch the session after they start writing, so
    // declining is correct here rather than lossy.
    if (headers_sent()) return;
    session_start();
}

function infra_set_flash(string $type, string $msg): void { infra_session_resume(); $_SESSION['infra_flash'] = ['type' => $type, 'msg' => $msg]; }

/**
 * Read a one-shot session value and clear it, re-opening the session just long
 * enough to make the clear stick.
 *
 * Exists because "read it, then unset it" is silently broken once the session has
 * been released: the read works (the array is still in memory) and the unset does
 * nothing, so the message or result would reappear on every page load afterwards.
 * A failure mode with no error attached to it, so it gets a function of its own.
 */
function infra_session_take(string $key)
{
    if (!isset($_SESSION[$key])) return null;
    $val = $_SESSION[$key];
    infra_session_resume();
    unset($_SESSION[$key]);
    infra_session_release();
    return $val;
}

/**
 * The freshness bar: what is on screen, how old it is, and one button that goes
 * and looks. RED when the answer is stale or was never taken, GREEN when it is
 * current — so the state of the page is legible before reading a single number.
 *
 * Every screen that shows discovered data renders from the LAST STORED ANSWER and
 * never goes to the network on its own. This bar is the whole of the deal: pages
 * are instant, and the slow part happens when you ask for it.
 *
 * @param array $o age (seconds, null = never), stale_after, noun, href, button
 */
function infra_freshness_bar(array $o): void
{
    $age   = $o['age'] ?? null;
    $stale = $age === null || $age > (int) ($o['stale_after'] ?? 900);
    $noun  = (string) ($o['noun'] ?? 'this page');

    $msg = $age === null
        ? 'Never checked — nothing has been read from ' . $noun . ' yet.'
        : 'Showing what ' . $noun . ' said ' . infra_ago($age) . '.';

    $btnBg = $stale ? '#dc2626' : '#16a34a';
    echo '<div class="ic-refresh">';
    echo '<a class="btn" style="background:' . $btnBg . '" href="' . ih((string) $o['href']) . '">&#8635; '
       . ih((string) ($o['button'] ?? 'Refresh')) . '</a>';
    echo '<span class="ic-asof" style="color:' . ($stale ? '#b91c1c' : '#166534') . ';font-weight:600">'
       . ($stale ? '&#9679; stale' : '&#9679; up to date') . '</span>';
    echo '<span class="ic-asof">' . ih($msg) . ($stale ? ' Press Refresh to go and look — it takes a few seconds.' : '') . '</span>';
    echo '</div>';
}
function infra_render_flash(): void
{
    $f = infra_session_take('infra_flash');
    if (!$f) return;
    $bg = $f['type'] === 'ok' ? '#dcfce7;border-color:#86efac;color:#166534'
        : ($f['type'] === 'err' ? '#fee2e2;border-color:#fca5a5;color:#991b1b'
        : '#fef9c3;border-color:#fde047;color:#854d0e');
    echo '<div style="white-space:pre-wrap;border:1px solid;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;background:' . $bg . '">' . ih($f['msg']) . '</div>';
}

function infra_header(string $active = 'dashboard'): void
{
    /*
     * THE ORDER READS AS THE WORK, left to right: where you can buy (Registers),
     * decide on a name (D.Finder), the domain list itself (D.Buy), what has been
     * bought (D.Own), then where it all goes — cities, sites, servers, live.
     *
     * The KEYS are view names and must not be renamed: every view calls
     * infra_header('<key>') to mark itself active, and the labels are only what
     * they are called on screen. Renaming a label is free; renaming a key is not.
     */
    $nav = [
        'dashboard'  => ['label' => 'Dashboard',   'href' => 'index.php'],
        'registrars' => ['label' => 'Registers',   'href' => 'index.php?view=registrars'],
        'dfinder'    => ['label' => 'D.Finder',    'href' => 'index.php?view=dfinder'],
        'domains'    => ['label' => 'D.Buy',       'href' => 'index.php?view=domains'],
        'buyqueue'   => ['label' => 'D.Own',       'href' => 'index.php?view=buyqueue'],
        'cities'     => ['label' => 'Cities/Niche','href' => 'index.php?view=cities'],
        'new'        => ['label' => '+ New Site',  'href' => 'index.php?view=new'],
        'bulk'       => ['label' => 'Bulk',        'href' => 'index.php?view=bulk'],
        'deploy'     => ['label' => 'Deploy',      'href' => 'index.php?view=deploy'],
        'servers'    => ['label' => 'Servers',     'href' => 'index.php?view=servers'],
        'cloudflare' => ['label' => 'Cloudflare',  'href' => 'index.php?view=cloudflare'],
        'golive'     => ['label' => 'Go-Live',     'href' => 'index.php?view=golive'],
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Infrastructure — Console</title>';
    echo '<style>' . infra_css() . '</style></head><body>';
    // Back-to-Factory sits FIRST, left of the brand: it is the way out, and the way
    // out belongs where the eye starts, not at the end of a row of twelve tabs.
    echo '<header class="ic-top">';
    echo '<a class="ic-back" href="../sites.php">&larr; Back to Factory</a>';
    echo '<div class="ic-brand">🛠 Infrastructure</div><nav class="ic-nav">';
    foreach ($nav as $k => $n) {
        $cls = $k === $active ? 'active' : '';
        echo '<a class="' . $cls . '" href="' . ih($n['href']) . '">' . ih($n['label']) . '</a>';
    }
    // sites.php, not index.php — index.php is the editor for whichever site happens to
    // be open, so "Back to Factory" used to drop you inside a site. The factory is the
    // Site Factory panel: sites, batches, infrastructure.
    echo '</nav></header>';
    echo '<main class="ic-main">';
    infra_render_flash();
}

function infra_footer(): void { echo '</main></body></html>'; }

/** Rough, human age. "2 min ago" is the right resolution for a fleet sweep. */
function infra_ago(int $secs): string
{
    if ($secs < 60)    return 'just now';
    if ($secs < 3600)  { $n = intdiv($secs, 60);    return $n . ' min ago'; }
    if ($secs < 86400) { $n = intdiv($secs, 3600);  return $n . ' hour' . ($n === 1 ? '' : 's') . ' ago'; }
    $n = intdiv($secs, 86400);
    return $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
}

/**
 * The Refresh control every fleet screen shares: what is on screen, how old it is,
 * and one button that goes and looks.
 *
 * Why it exists — the dashboard and the Servers tab used to sweep all twenty boxes
 * on page load: four API calls each, in series, 124 calls and 64 measured seconds
 * before either page printed anything. Now they render the last stored answer
 * instantly and the sweep happens here, on purpose, one request per box fired
 * together so the wait is the slowest box rather than the sum of all of them.
 *
 * Says the age out loud. A page showing four-hour-old state as though it were the
 * present is worse than one that is slow, because you cannot tell by looking.
 *
 * @param array $fleet rows from infra_hestia_fleet_cached()
 */
function infra_refresh_bar(array $fleet, bool $withZones = false): void
{
    $ids = [];
    $never = 0;
    foreach ($fleet as $b) {
        // Boxes with no key pair are skipped: there is nothing to authenticate
        // with, so "checking" one is a no-op that would pad the progress count.
        if ($b['pending']) continue;
        $ids[] = $b['id'];
        if ($b['never']) $never++;
    }
    // Screens that show Cloudflare columns sweep the zones in the same pool, so one
    // button leaves the whole picture current instead of half of it.
    if ($withZones) {
        require_once __DIR__ . '/lib/fleet.php';
        foreach (infra_cf_accounts() as $a) $ids[] = 'cf:' . ($a['id'] ?? '');
    }
    $n   = count($ids);
    $age = $withZones ? infra_fleet_data_age() : infra_hestia_fleet_age($fleet);

    if ($age === null) {
        $asof = $n ? 'Nothing has been checked yet — press the button to go and look.'
                   : 'Nothing to check yet.';
    } else {
        $asof = 'Showing what they said ' . infra_ago($age)
              . ($never ? ' · ' . $never . ' never checked' : '');
    }
    // Red until it has been refreshed recently, green when current — the state of
    // the page readable before a single number on it.
    $stale = $age === null || $age > 900;
    echo '<div class="ic-refresh" style="width:100%">'
       . '<span class="ic-asof" style="font-weight:600;color:' . ($stale ? '#b91c1c' : '#166534') . '">'
       . ($stale ? '&#9679; stale' : '&#9679; up to date') . '</span></div>';

    echo '<div class="ic-refresh" id="icRefresh" data-stale="' . ($stale ? '1' : '0') . '"'
       . ' data-ids="' . ih(json_encode(array_values($ids))) . '"'
       . ' data-csrf="' . ih(infra_csrf()) . '"'
       . ' data-url="' . ih(strpos($_SERVER['SCRIPT_NAME'] ?? '', '/actions/') !== false
                            ? 'fleet_refresh.php' : 'actions/fleet_refresh.php') . '">';
    if ($n) {
        echo '<button type="button" class="btn" id="icRefreshBtn" style="background:' . ($stale ? '#dc2626' : '#16a34a') . '">'
           . '&#8635; Check all ' . $n . ($withZones ? ' servers + zones' : ' servers') . '</button>';
    }
    echo '<span class="ic-asof">' . ih($asof) . '</span>';
    echo '<div class="ic-prog" hidden><div class="ic-prog-track"><div class="ic-prog-fill"></div></div>'
       . '<div class="ic-prog-txt"></div></div>';
    echo '</div>';
    echo '<script>' . infra_refresh_js() . '</script>';
}

/** The sweep itself: a fixed-size pool of in-flight requests, one box each. */
function infra_refresh_js(): string
{
    return <<<'JS'
(function(){
  var box = document.getElementById('icRefresh');
  if (!box) return;
  var btn = document.getElementById('icRefreshBtn');
  if (!btn) return;
  var ids = JSON.parse(box.getAttribute('data-ids'));
  var csrf = box.getAttribute('data-csrf');
  var url = box.getAttribute('data-url');
  var prog = box.querySelector('.ic-prog');
  var fill = box.querySelector('.ic-prog-fill');
  var txt = box.querySelector('.ic-prog-txt');
  /* Six at a time. Enough to turn a 64s serial sweep into about ten seconds,
     low enough not to open twenty sockets from one browser tab at once. */
  var LIMIT = 6;
  btn.addEventListener('click', function(){
    if (!ids.length) return;
    btn.disabled = true;
    btn.textContent = 'Checking…';
    prog.hidden = false;
    var done = 0, bad = 0, next = 0;
    function paint(host){
      fill.style.width = Math.round(done / ids.length * 100) + '%';
      txt.textContent = done + ' of ' + ids.length + ' checked'
        + (bad ? ' · ' + bad + ' could not be reached' : '')
        + (host ? ' · ' + host : '');
    }
    paint('');
    function run(){
      if (next >= ids.length) return Promise.resolve();
      var body = new FormData();
      body.append('csrf', csrf);
      body.append('id', ids[next++]);
      return fetch(url, {method: 'POST', body: body, credentials: 'same-origin'})
        .then(function(r){ return r.json(); })
        .catch(function(){ return {ok: false, pending: false}; })
        .then(function(j){
          done++;
          /* Unfinished is not broken: a box awaiting its key pair must not be
             counted here or every newly bought server reads as a fault. */
          if (!j.ok && !j.pending) bad++;
          paint(j.host || '');
          return run();
        });
    }
    var pool = [];
    for (var i = 0; i < Math.min(LIMIT, ids.length); i++) pool.push(run());
    Promise.all(pool).then(function(){
      fill.style.width = '100%';
      txt.textContent = 'Done — loading the new numbers…';
      /* Reload rather than patch the page: every number on it is derived from the
         sweep, and the server already knows how to render them from the cache we
         just filled. Two renderers for the same figures is how they drift. */
      location.reload();
    });
  });
})();
JS;
}

/** Shared client-side table filter for any <input class="ic-search" data-target="tableId">. */
function infra_search_js(): void
{
    echo '<script>document.querySelectorAll(".ic-search").forEach(function(b){'
       . 'b.addEventListener("input",function(){var q=this.value.toLowerCase();'
       . 'var t=document.getElementById(this.dataset.target);if(!t)return;'
       . 't.querySelectorAll("tbody tr").forEach(function(tr){'
       . 'tr.style.display=tr.textContent.toLowerCase().indexOf(q)>-1?"":"none";});});});</script>';
}

function infra_css(): string
{
    return <<<CSS
*{box-sizing:border-box}body{margin:0;font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;background:#f3f4f6}
/* Spacing tuned so all TWELVE tabs sit on one row at 1300px. They used to wrap,
   which dropped a single orphan tab onto a second line and made the bar look
   like it had been cut off rather than laid out. flex-wrap stays: narrower than
   that it should wrap rather than overflow. */
.ic-top{display:flex;align-items:center;gap:16px;background:#111827;color:#fff;padding:0 14px;height:52px}
.ic-brand{font-weight:700;font-size:15px;white-space:nowrap}
.ic-nav{display:flex;gap:2px;flex:1;flex-wrap:wrap}
.ic-nav a{color:#cbd5e1;text-decoration:none;padding:6px 9px;border-radius:6px;font-weight:600;white-space:nowrap}
.ic-nav a:hover{background:#1f2937;color:#fff}.ic-nav a.active{background:#2563eb;color:#fff}
.ic-back{color:#9ca3af;text-decoration:none;font-size:13px;white-space:nowrap}.ic-back:hover{color:#fff}
.ic-main{max-width:1200px;margin:0 auto;padding:24px 20px}
.ic-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:24px}
.ic-tile{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px}
.ic-tile .n{font-size:28px;font-weight:700;line-height:1}.ic-tile .l{color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-top:6px}
.ic-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px;overflow:hidden}
.ic-card>h2{margin:0;padding:14px 16px;font-size:15px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:10px}
.ic-card .body{padding:14px 16px}
/* Collapsible card: a <details class="ic-card ic-fold"> whose <summary> holds an
   <h2> starting with the caret span. Used by Servers and Registrars. Scoped to
   .ic-fold so the plain <details class="ic-card"> elsewhere keeps the browser's
   own disclosure triangle. */
.ic-fold>summary{list-style:none}
.ic-fold>summary::-webkit-details-marker{display:none}
.ic-fold>summary:hover{background:#fafafa}
.ic-fold>summary>h2{margin:0;padding:14px 16px 10px;font-size:15px}
.ic-fold[open]>summary{border-bottom:1px solid #f0f0f0}
.ic-fold>summary h2>span:first-child{display:inline-block;transition:transform .12s}
.ic-fold[open]>summary h2>span:first-child{transform:rotate(90deg)}
/* The one-line digest under a collapsed card's title: what it can do, and what is
   wrong with it, without opening anything. */
.ic-fold .ic-digest{padding:0 16px 13px 30px;color:#6b7280;font-size:12.5px;display:flex;gap:16px;flex-wrap:wrap;align-items:center}
.ic-fold .ic-digest b{color:#374151;font-weight:600}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}
.b-ok{background:#dcfce7;color:#166534}.b-warn{background:#fef9c3;color:#854d0e}.b-err{background:#fee2e2;color:#991b1b}.b-mut{background:#e5e7eb;color:#374151}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #f0f0f0}
th{color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.ic-sticky thead th{position:sticky;top:0;z-index:1;background:#fff;box-shadow:inset 0 -1px 0 #e5e7eb}
tr:hover td{background:#f9fafb}code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12px}
.ic-search{width:100%;max-width:320px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:10px}
.ic-empty{color:#6b7280;padding:16px;text-align:center}
.ic-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:7px 14px;border-radius:8px;font-weight:600;font-size:13px;border:0;cursor:pointer}
.btn.sec{background:#e5e7eb;color:#111827}
.btn:disabled{background:#9ca3af;cursor:default}
/* The fleet Refresh control: button, how old the page is, and the sweep's progress. */
.ic-refresh{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.ic-asof{color:#6b7280;font-size:12px}
.ic-prog{flex-basis:100%;max-width:420px}
.ic-prog-track{height:6px;background:#e5e7eb;border-radius:999px;overflow:hidden}
.ic-prog-fill{height:100%;width:0;background:#2563eb;border-radius:999px;transition:width .2s}
.ic-prog-txt{color:#6b7280;font-size:12px;margin-top:5px}
CSS;
}
