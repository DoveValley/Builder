<?php
/**
 * infra/actions/domain_manage.php — edit / remove one domain (CSRF, PRG).
 * action = edit | delete_zone | delete_site | untrack | teardown
 * Destructive actions require a typed-domain confirm field.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/acquire.php';   // infra_domain_buy(), infra_domain_mark_owned()

$domain = strtolower(trim($_POST['domain'] ?? ''));
$action = $_POST['action'] ?? '';
$back    = '../index.php?view=domain&d=' . urlencode($domain);
$toList  = '../index.php?view=domains';

// A row button should return you to the list you pressed it from, not to the
// domain's own page. Only our own view query strings are honoured.
$from = (string) ($_POST['from'] ?? '');
// \z, not $: PCRE's $ also matches before a trailing newline, which would let a
// newline through into header(). PHP rejects that outright, so this was a broken
// redirect rather than an injection — but the allowlist should mean what it says.
if ($from !== '' && preg_match('/^view=[a-z]+[A-Za-z0-9=&_.\-%]*\z/', $from)) {
    $back = '../index.php?' . $from;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $toList); exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();
$rec = infra_state_get_domain($domain);
if (!$rec) { infra_set_flash('err', "Not in fleet state: {$domain}"); header('Location: ' . $toList); exit; }

// destructive actions require re-typing the domain
$destructive = ['delete_zone', 'delete_site', 'untrack', 'teardown', 'buy'];
// `quick` = pressed from a per-row Buy button, which carries its own domain and
// cannot be mis-targeted the way a typed name in a shared form can. It still needs
// CSRF, an authenticated session, and a browser confirm naming the domain and price.
$quick = ($action === 'buy' && !empty($_POST['quick']));
if (!$quick && in_array($action, $destructive, true) && strtolower(trim($_POST['confirm'] ?? '')) !== $domain) {
    infra_set_flash('err', 'Confirmation did not match the domain — nothing changed.');
    header('Location: ' . $back); exit;
}

// The fleet registry. This used to read the Plesk one, which after the migration
// matched no id anything writes — "Delete site" answered "No server on record" and
// Teardown removed the state record while silently leaving the vhost on the box.
$server = null;  foreach (infra_hestia_servers() as $s) if (($s['id'] ?? '') === ($rec['server_id'] ?? '')) $server = $s;
$account = null; foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === ($rec['cf_account_id'] ?? '')) $account = $a;

switch ($action) {
    case 'edit':
        // Every field is validated against its own known set, and anything that does
        // not match leaves the stored value alone rather than overwriting it. The
        // status field is why: its dropdown offered only the seven infrastructure
        // states, so for a domain in begin/ready/owned nothing matched, the browser
        // selected the first option, and saving a niche silently reset the domain to
        // 'staged' — dropping it out of the acquisition views and into the go-live
        // pipeline. Rejecting the unknown value is the guard; the form no longer
        // sends one either.
        $write   = ['domain' => $domain];
        $ignored = [];

        $reg = strtolower(trim((string) ($_POST['registrar'] ?? '')));
        if ($reg === '' || in_array($reg, infra_registrar_names(), true)) $write['registrar'] = $reg;
        else $ignored[] = "registrar '{$reg}' is not configured";

        // The niche is a fixed list, not free text: 'appliance' / 'Appliance' /
        // 'appliances' as three groups would quietly break every count.
        if (array_key_exists('niche', $_POST)) $write['niche'] = infra_niche((string) $_POST['niche']);

        $st = trim((string) ($_POST['status'] ?? ''));
        if ($st !== '' && $st !== $rec['status']) {
            if (infra_is_acquiring($rec)) {
                $ignored[] = "status stays '{$rec['status']}' — an acquisition-stage domain is moved by"
                           . ' checking availability, buying, or provisioning, not by hand';
            } elseif (!in_array($st, INFRA_STATUSES_MANUAL, true)) {
                $ignored[] = "'{$st}' is not a status that can be set by hand";
            } else {
                $write['status'] = $st;
            }
        }

        infra_state_upsert_domain($write);
        infra_set_flash($ignored ? 'warn' : 'ok',
            "Updated {$domain}." . ($ignored ? "\nLeft unchanged: " . implode('; ', $ignored) . '.' : ''));
        header('Location: ' . $back); exit;

    case 'delete_zone':
        if (!$account || ($rec['cf_zone_id'] ?? '') === '') { infra_set_flash('warn', 'No Cloudflare zone on record.'); header('Location: ' . $back); exit; }
        $r = cf_delete_zone($account, $rec['cf_zone_id']);
        if ($r['ok']) infra_state_upsert_domain(['domain' => $domain, 'cf_zone_id' => '', 'nameservers' => '']);
        infra_cache_flush();
        infra_set_flash($r['ok'] ? 'ok' : 'err', "Delete CF zone: {$r['message']}");
        header('Location: ' . $back); exit;

    case 'delete_site':
        if (!$server) { infra_set_flash('warn', 'No server on record.'); header('Location: ' . $back); exit; }
        $r = hestia_delete_site($server, $domain);
        if ($r['ok']) {
            $write = ['domain' => $domain, 'ftp_user' => '', 'ftp_pass' => '', 'server_id' => ''];
            // The site is CONFIRMED gone (hestia_delete_site verifies actual removal,
            // never the exit code) — an owned domain with no site left is just owned
            // again, whatever stage it was in before, including live/releasing: this
            // is a deliberate, typed-confirm, one-at-a-time action, never an automatic
            // re-provision retry, so there is no accidental-regression risk to guard
            // against here the way provision.php's own automatic re-run path has to.
            // Without this, a domain whose host is gone (deleted here, or outside the
            // normal Teardown flow entirely) stays stuck claiming server infrastructure
            // it no longer has — a false "already provisioned" ghost that D.Buy's
            // acquisition-only filter permanently hides.
            if (($rec['owned'] ?? '') === 'yes') {
                $write['status'] = 'owned';
            }
            infra_state_upsert_domain($write);
        }
        infra_cache_flush();
        infra_set_flash($r['ok'] ? 'ok' : 'err', "Delete site: {$r['message']}");
        header('Location: ' . $back); exit;

    case 'buy':
        // The ONLY action in this console that spends money. Guarded by the typed
        // confirm above; every other rail lives in infra_domain_buy().
        $r = infra_domain_buy($domain, [
            'years'      => (int) ($_POST['years'] ?? 1),
            'auto_renew' => !empty($_POST['auto_renew']),
        ]);
        infra_set_flash($r['ok'] ? 'ok' : 'err',
            ($r['ok'] ? "✓ BOUGHT {$domain} — " : "✗ Purchase refused for {$domain}: ") . $r['message']);
        infra_cache_forget('reg_owned');   // it is ours now; the owned index is stale
        header('Location: ' . $back); exit;

    case 'mark_owned':
        $r = infra_domain_mark_owned($domain, (string) ($_POST['registrar'] ?? ''));
        infra_cache_forget('reg_owned');
        infra_set_flash($r['ok'] ? 'ok' : 'err', $r['message']);
        header('Location: ' . $back); exit;

    case 'untrack':
        infra_state_delete_domain($domain);
        infra_set_flash('ok', "Removed {$domain} from fleet state (infrastructure left intact).");
        header('Location: ' . $toList); exit;

    case 'teardown':
        $parts = [];
        if ($account && ($rec['cf_zone_id'] ?? '') !== '') { $z = cf_delete_zone($account, $rec['cf_zone_id']); $parts[] = 'CF zone: ' . $z['message']; }
        if ($server) { $p = hestia_delete_site($server, $domain); $parts[] = 'site: ' . $p['message']; }
        infra_state_delete_domain($domain);
        $parts[] = 'fleet state: removed';
        infra_cache_flush();
        infra_set_flash('warn', "Teardown {$domain} —\n" . implode("\n", $parts));
        header('Location: ' . $toList); exit;

    default:
        infra_set_flash('err', 'Unknown action.');
        header('Location: ' . $back); exit;
}
