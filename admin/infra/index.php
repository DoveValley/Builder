<?php
/**
 * infra/index.php — Infrastructure console: shared setup, the router, and the
 * dashboard. Every other view is a file in views/.
 *
 * It was one 2,519-line file holding twelve views. They were already independent —
 * each ended in infra_footer(); exit; so control never returned — which is what made
 * the split mechanical: each file is the old block VERBATIM, indentation included, so
 * the rendered HTML is unchanged byte for byte.
 *
 *   views/domains.php     fleet-wide domain inventory, reconciled across sources
 *   views/domain.php      one domain: its wiring, and the danger zone
 *   views/servers.php     the boxes, their host areas and accounts
 *   views/server.php      one box
 *   views/deploy.php      hand provisioned credentials to a batch
 *   views/new.php         provision one domain
 *   views/bulk.php        provision many
 *   views/golive.php      the nameserver switch
 *   views/cities.php      city + niche research and scoring
 *   views/buyqueue.php    scheduled purchases
 *   views/registrars.php  registrar credentials and balances
 *   views/cloudflare.php  zones, DNS and SSL
 *
 * The cell helpers below stay here because domains.php and domain.php both use them,
 * and a shared file for six one-line functions would be a third place to look.
 */
require_once __DIR__ . '/bootstrap.php';

// Views READ the session — who you are, the CSRF token, one-shot flash values —
// and the reads are all done by the time anything slow starts. Let go of the lock
// here, or a tab that goes and looks (Refresh, a zone scan, a file check) freezes
// every other click in the console until it finishes: PHP serialises requests per
// session. infra_session_take() re-opens briefly for the one-shot clears, and
// infra_set_flash() for writes, so nothing is lost by releasing early.
infra_session_release();

$view = $_GET['view'] ?? 'dashboard';
if (!empty($_GET['refresh'])) infra_cache_force();   // ?refresh=1 → bypass cache, re-sweep live

/** A box by id, from the fleet registry the rest of the console uses. */
function infra_find_server(string $id): ?array
{
    foreach (infra_hestia_servers() as $s) if (($s['id'] ?? '') === $id) return $s;
    return null;
}

/* small render helpers ------------------------------------------------- */
function infra_cf_cell(?array $z, bool $hasCf): string
{
    if ($z) {
        $cls = ($z['status'] === 'active') ? 'b-ok' : 'b-warn';
        return '<span class="badge ' . $cls . '">' . ih($z['account_label']) . ' · ' . ih($z['status'] ?: '?') . '</span>';
    }
    return $hasCf ? '<span class="badge b-warn">no zone</span>'
                  : '<span class="badge b-mut">no CF account</span>';
}
function infra_state_cell(string $state): string
{
    $map = [
        'live' => 'b-ok', 'owned' => 'b-ok',
        'staged' => 'b-warn', 'queued' => 'b-warn', 'releasing' => 'b-warn', 'awaiting-ns' => 'b-warn',
        'ready' => 'b-warn',
        'buy-failed' => 'b-err', 'register-failed' => 'b-err', 'partial' => 'b-err',
        'begin' => 'b-mut', 'unknown' => 'b-mut',
    ];
    return '<span class="badge ' . ($map[$state] ?? 'b-mut') . '">' . ih($state) . '</span>';
}

/** Col 2 — "Ready to buy", with the reason when the answer is no. */
function infra_ready_cell(array $r): string
{
    // Once it is owned the question no longer applies — showing a stale yes/no
    // there just invites you to read it as something still to be done.
    if (($r['owned'] ?? '') === 'yes') return '<span style="color:#9ca3af">&mdash;</span>';
    $note = (string) ($r['avail_note'] ?? '');
    $price = (string) ($r['avail_price'] ?? '');
    if ($r['ready_to_buy'] === 'yes') {
        return '<span class="badge b-ok">Yes</span>'
             . ($price !== '' ? ' <span style="color:#6b7280;font-size:11px">$' . ih($price) . '</span>' : '')
             . ($note === 'premium' ? ' <span class="badge b-warn">premium</span>' : '');
    }
    if ($r['ready_to_buy'] === 'no') {
        $label = $note === 'self-owned' ? 'you own it' : ($note !== '' ? $note : 'no');
        return '<span class="badge b-err">No</span> <span style="color:#6b7280;font-size:11px">' . ih($label) . '</span>';
    }
    // Never checked, or the check itself failed — say so rather than implying "no".
    if ($note !== '') return '<span class="badge b-mut">?</span> <span style="color:#b45309;font-size:11px">' . ih($note) . '</span>';
    return '<span class="badge b-mut">not checked</span>';
}

/** Col 5 — "Own": the receipt of a purchase, not an editable opinion. */
function infra_own_cell(array $r): string
{
    if (($r['owned'] ?? '') === 'yes') {
        // Verified auto-renew is still recorded on the row (and shown on the
        // domain's own page); the per-registrar explanation lives on the
        // Registrars tab rather than shouting from every line of the table.
        $ar    = (string) ($r['auto_renew'] ?? '');
        $title = $ar !== '' ? ' title="auto-renew: ' . ih($ar) . '"' : '';
        return '<span class="badge b-ok"' . $title . '>Yes</span>';
    }
    if (($r['state'] ?? '') === 'buy-failed') {
        return '<span class="badge b-err">failed</span>'
             . (($r['buy_error'] ?? '') !== '' ? '<br><span style="color:#991b1b;font-size:11px">' . ih(substr($r['buy_error'], 0, 60)) . '</span>' : '');
    }
    $ready = ($r['ready_to_buy'] ?? '') === 'yes' && ($r['buy_registrar'] ?? '') !== '';
    $due   = ($r['buy_at'] ?? '') !== '' && $r['buy_at'] <= infra_today();
    if ($ready) {
        return ($due ? '<span class="badge b-warn">due</span> ' : '')
             . '<a class="btn sec" style="padding:2px 8px;font-size:11px" href="index.php?view=domain&d='
             . ih($r['domain']) . '">Buy &rarr;</a>';
    }
    if ($due) return '<span class="badge b-warn">due</span>';
    return '<span style="color:#9ca3af">No</span>';
}
function infra_drift_cell(?string $drift): string
{
    if (!$drift) return '<span style="color:#9ca3af">—</span>';
    return '<span class="badge b-err">' . ih($drift) . '</span>';
}

/* ── Routing ──────────────────────────────────────────────────────────────────
   One file per view under views/. Each holds the body of what used to be an
   `if ($view === 'x')` block here, VERBATIM — same indentation, so the rendered
   HTML is byte-for-byte what it was. They end in infra_footer(); exit;, so
   control never returns. Anything unmatched falls through to the dashboard below.

   Views require from ../lib, not ./lib: __DIR__ is views/ inside them. */
$__viewFile = __DIR__ . '/views/' . preg_replace('/[^a-z]/', '', (string) $view) . '.php';
if ($view !== 'dashboard' && is_file($__viewFile)) require $__viewFile;
/* ============================= DASHBOARD ============================= */
// Headline numbers only. The servers themselves live on the Servers tab — one list
// of them, in one place, rather than a second shorter one here that drifts.
// Counts the HESTIA registry, which is the fleet. It used to count the Plesk
// one, and when Plesk came off the Servers tab that left this reading
// "1 server, 0 sites, 1 issue" while eight boxes were up — the wrongest kind of
// number, because it is confident and on the front page.
//
// Reads the LAST SWEEP, not the network. This loop used to call infra_hestia_fleet(),
// which asks every box four questions in series: on twenty boxes that measured 124
// API calls and 64 seconds before this page printed a single byte — to show four
// numbers, two of which (Servers, CF Accounts) are local file reads. The sweep now
// belongs to the Refresh button, which fires the boxes in parallel behind a progress
// bar. Same numbers, on purpose, when they are asked for.
require_once __DIR__ . '/lib/hestia_fleet.php';
$servers = infra_hestia_servers();
$cfAccts = infra_cf_accounts();
$fleet   = infra_hestia_fleet_cached();
$totalSites = 0; $issues = 0; $pending = 0; $never = 0;
foreach ($fleet as $b) {
    // A box awaiting its key pair is unfinished, not broken. Counting it as an
    // issue would make every newly-bought server look like a fault.
    if ($b['pending'])    $pending++;
    // Never asked is a third state, and it is not a fault either. Folding it into
    // "issues" would report faults on a fleet nobody has checked yet.
    elseif ($b['never'])  $never++;
    elseif (!$b['ok'])    $issues++;
    // Deployed sites, so the tile does not count each box's own hostname vhost
    // and report eight sites for an empty fleet.
    $totalSites += $b['deployed'];
}
// A tile can only honestly show a total when something has been counted. With no
// sweep behind it, "0 sites" is a claim about the fleet; "—" is the truth.
$swept   = count($fleet) - $pending - $never;
$siteVal = $swept > 0 ? (string) $totalSites : '&mdash;';
$issVal  = $swept > 0 ? (string) $issues     : '&mdash;';
infra_header('dashboard');
?>
<?php if (empty($servers)): ?><div class="ic-note">No servers registered yet. Add one on the <a href="index.php?view=servers">Servers</a> tab.</div><?php endif; ?>
<?php if ($pending): ?><div class="ic-note"><?= (int) $pending ?> server<?= $pending === 1 ? ' is' : 's are' ?> registered but not set up yet — no Hestia on <?= $pending === 1 ? 'it' : 'them' ?> so far. <a href="index.php?view=servers">Finish <?= $pending === 1 ? 'it' : 'them' ?></a>.</div><?php endif; ?>
<div class="ic-tiles">
  <a class="ic-tile" href="index.php?view=servers" style="text-decoration:none;color:inherit">
    <div class="n"><?= count($servers) ?></div><div class="l">Servers</div></a>
  <a class="ic-tile" href="index.php?view=servers" style="text-decoration:none;color:inherit">
    <div class="n"><?= $siteVal ?></div><div class="l">Sites (live)</div></a>
  <a class="ic-tile" href="index.php?view=cloudflare" style="text-decoration:none;color:inherit">
    <div class="n"><?= count($cfAccts) ?></div><div class="l">CF Accounts</div></a>
  <a class="ic-tile" href="index.php?view=servers" style="text-decoration:none;color:inherit<?= $issues ? ';border-color:#fca5a5' : '' ?>">
    <div class="n"<?= $issues ? ' style="color:#991b1b"' : '' ?>><?= $issVal ?></div><div class="l">Issues</div></a>
</div>
<?php infra_refresh_bar($fleet); ?>
<div style="margin-bottom:16px">
  <a class="btn sec" href="index.php?view=servers">Servers &rarr;</a>
  <a class="btn sec" href="index.php?view=domains">All domains &rarr;</a>
</div>
<?php infra_footer();
