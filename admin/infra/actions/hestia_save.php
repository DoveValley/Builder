<?php
/**
 * infra/actions/hestia_save.php — add, edit, test and remove HestiaCP servers.
 * action = save | test | delete
 *
 * ⚠ TRIAL CODE, twin of server_save.php. Writes admin/infra/config/hestia.json
 * (0600, gitignored) and touches NOTHING the Plesk path uses — different file,
 * different cache prefix. Delete this file whole if Hestia loses the comparison.
 *
 * Two secrets here rather than Plesk's one (access key + secret key); both follow
 * the same rule — blank on an edit means "keep the stored one", so the form never
 * renders an existing secret back into the page.
 *
 * "delete" removes the RECORD, not the machine.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hestia_fleet.php';

$back = '../index.php?view=servers#hestia';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

$action = (string) ($_POST['action'] ?? '');
$path   = infra_config_path('hestia.json');
$cfg    = infra_load_json($path, []);
if (!isset($cfg['servers']) || !is_array($cfg['servers'])) $cfg['servers'] = [];

$findIdx = function (string $id) use ($cfg): ?int {
    foreach ($cfg['servers'] as $i => $s) if (($s['id'] ?? '') === $id) return (int) $i;
    return null;
};

$id  = trim((string) ($_POST['id'] ?? ''));
$idx = $id !== '' ? $findIdx($id) : null;

/* ---- remove the record ------------------------------------------------ */
if ($action === 'delete') {
    if ($idx === null) {
        infra_set_flash('err', 'That server is not in the list.');
    } else {
        $label = $cfg['servers'][$idx]['label'] ?? $id;
        array_splice($cfg['servers'], $idx, 1);
        if (infra_save_json($path, $cfg)) {
            infra_cache_forget('hestia:' . $id);
            infra_set_flash('ok', 'Removed "' . $label . '" from the console. The server itself is untouched — it is still running and its websites are still up.');
        } else {
            infra_set_flash('err', 'Could not write the Hestia server list.');
        }
    }
    header('Location: ' . $back); exit;
}

/**
 * Run the connection test and describe what came back.
 *
 * Shared by "Test without saving" (which tests whatever is typed into the form)
 * and the per-card "Test connection" (which tests the stored record). Two copies
 * of this would drift into describing the same failure two different ways, which
 * is exactly how a box ends up looking broken on one screen and fine on another.
 *
 * @return array{0:string,1:string} flash level, message
 */
function hestia_test_flash(array $srv): array
{
    // Testing with no key pair would send a request that can only come back as
    // "authentication failed" — a credentials error for a box that simply has no
    // credentials yet. Say the true thing instead of the misleading one.
    if (!hestia_server_configured($srv)) {
        return ['warn', 'There is nothing to test yet — this server has no access key or secret key, '
            . 'so there is no Hestia to talk to. Install Hestia on it, create an access key with '
            . 'v-add-access-key user, then paste the pair in with "Edit these settings".'];
    }

    infra_http_calls_reset();
    $t0    = microtime(true);
    $probe = hestia_probe($srv);
    $ms    = (int) round((microtime(true) - $t0) * 1000);
    $where = ($srv['host'] ?? '?') . ':' . (int) ($srv['port'] ?? 8083);

    if ($probe['ok']) {
        $facts = infra_hestia_facts(hestia_server_info($srv));
        $users = hestia_list_users($srv);
        $sites = hestia_list_sites($srv);
        return ['ok', '✓ Reached ' . $where . ' — Hestia ' . ($facts['panel_version'] ?: '?')
            . ' on ' . ($facts['platform'] ?: '?') . ', ' . count($sites) . ' vhost(s) under '
            . count($users) . ' account(s). ' . infra_http_calls() . ' API call(s), ' . $ms . 'ms.'];
    }

    // Name the failure precisely, because the three ways this goes wrong need three
    // different fixes and they are easy to confuse:
    //   code 19  Hestia answered 200 with the body "Error" — API off, or our IP is
    //            not allowed. Invisible from the HTTP status, which is always 200.
    //   HTTP 401 Hestia answered and rejected the key pair. A credentials problem.
    //   timeout  Nothing answered at all. The box or the port, not the key.
    // Hestia's own exit codes only mean anything when Hestia actually replied, so
    // code -1 (the catch-all for "no Hestia reply") must not be dressed up as one.
    $code = (int) $probe['code'];
    $err  = $probe['error'] ?: 'no reply';
    $port = (int) ($srv['port'] ?? 8083);
    $hint = '';

    if ($code >= 0) {
        $tag = 'code ' . $code . ': ' . hestia_err($code);
        if ($code === 19) {
            $hint = ' — the API is off, or this console\'s IP is not in API_ALLOWED_IP. On the box, run:'
                  . ' v-change-sys-config-value API \'yes\' then'
                  . ' v-change-sys-config-value API_ALLOWED_IP \'187.127.254.206\'';
        }
    } elseif (preg_match('/HTTP (\d{3})/', $err, $m)) {
        $status = (int) $m[1];
        $tag    = 'HTTP ' . $status;
        if ($status === 401) {
            $tag  = 'HTTP 401 — Hestia rejected this key pair';
            $hint = ' — the key does not exist on the box, or the secret does not match it. Mint a new'
                  . ' pair with v-add-access-key user "" "factory console" and paste both halves in.';
        }
    } elseif (stripos($err, 'timeout') !== false || stripos($err, 'connect') !== false) {
        $tag  = 'no connection';
        $hint = ' — nothing answered there. Check the box is up and that port ' . $port
              . ' is reachable from this console (Hestia\'s firewall and any provider firewall).';
    } else {
        $tag = 'no reply from Hestia';
    }

    return ['err', rtrim('✗ Could not reach ' . $where . ' — ' . $err . ' [' . $tag . ']' . $hint, '.') . '.'];
}

/* ---- test a server already in the list (the per-card button) ----------- */
// Separate from "test", which validates and rebuilds a candidate from POSTed
// form fields. This one needs no fields at all: the record is already stored, so
// testing it should not require opening the edit form first.
if ($action === 'test_saved') {
    if ($idx === null) {
        infra_set_flash('err', 'That server is not in the list.');
    } else {
        [$lvl, $msg] = hestia_test_flash($cfg['servers'][$idx]);
        infra_set_flash($lvl, $msg);
    }
    header('Location: ' . $back); exit;
}

/* ---- collect + check the fields (shared by save and test) -------------- */
$label    = trim((string) ($_POST['label'] ?? ''));
$host     = trim((string) ($_POST['host'] ?? ''));
$port     = (int) ($_POST['port'] ?? 8083);
$aKey     = trim((string) ($_POST['access_key'] ?? ''));
$sKey     = trim((string) ($_POST['secret_key'] ?? ''));
$siteUser = strtolower(trim((string) ($_POST['site_user'] ?? '')));
$ip       = trim((string) ($_POST['default_ip'] ?? ''));
$email    = trim((string) ($_POST['contact_email'] ?? ''));
$package  = trim((string) ($_POST['package'] ?? 'default'));
$notes    = trim((string) ($_POST['notes'] ?? ''));

// Same paste-the-browser-bar guard as the Plesk form.
$host = preg_replace('#^https?://#i', '', $host);
$host = preg_replace('#[/:].*$#', '', $host);

$errors = [];
if ($label === '') $errors[] = 'Give the server a name.';
if ($host  === '') $errors[] = 'Enter the address you log in to Hestia at.';
if ($port < 1 || $port > 65535) $errors[] = 'Port must be a number between 1 and 65535.';

if ($siteUser === '') {
    $siteUser = 'fleet';
} elseif (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $siteUser)) {
    $errors[] = 'The account name must start with a letter and use only lowercase letters, numbers and underscores.';
}

// Blank secrets on an edit mean keep the stored pair. Blank on a NEW server means
// the machine exists but Hestia is not on it yet — a real state, and one worth
// being able to write down: a VPS is bought minutes before it is set up, and until
// it is in the console it is only in someone's memory. It saves with no keys and
// shows as "not set up yet" until they are pasted in.
$storedA = $idx !== null ? ($cfg['servers'][$idx]['access_key'] ?? '') : '';
$storedS = $idx !== null ? ($cfg['servers'][$idx]['secret_key'] ?? '') : '';
if ($aKey === '' && $sKey === '') {
    if ($idx !== null) { $aKey = $storedA; $sKey = $storedS; }
} elseif ($aKey === '' || $sKey === '') {
    // Half a credential is never what someone meant, and storing it would produce
    // an auth failure that reads like a wrong password rather than a missing field.
    $errors[] = 'Enter BOTH the access key and the secret key, or leave both blank to keep the stored pair.';
}

if ($ip === '') $ip = $host;

if ($errors) {
    infra_set_flash('err', implode(' ', $errors));
    header('Location: ' . $back); exit;
}

$candidate = [
    'id'            => $id !== '' ? $id : 'hst-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'label'         => $label,
    'host'          => $host,
    'port'          => $port,
    'access_key'    => $aKey,
    'secret_key'    => $sKey,
    'site_user'     => $siteUser,
    'default_ip'    => $ip,
    'contact_email' => $email,
    'package'       => $package !== '' ? $package : 'default',
    'notes'         => $notes,
];

/* ---- test without saving ---------------------------------------------- */
if ($action === 'test') {
    [$lvl, $msg] = hestia_test_flash($candidate);
    infra_set_flash($lvl, $msg . ' Nothing was saved.');
    header('Location: ' . $back); exit;
}

/* ---- save ------------------------------------------------------------- */
if ($action === 'save') {
    foreach ($cfg['servers'] as $i => $s) {
        if ($i === $idx) continue;
        if (strcasecmp($s['host'] ?? '', $host) === 0 && (int) ($s['port'] ?? 0) === $port) {
            // Almost always this is not a genuine clash but the "Add a server"
            // form being used to UPDATE one that already exists — most often to
            // replace its keys. That form carries no id, so it can only ever add,
            // and the save is refused. Saying only "already in use" leaves someone
            // pasting a correct key into a form that cannot accept it, so name the
            // form they actually want.
            infra_set_flash('err',
                $idx === null
                    ? '"' . ($s['label'] ?? $s['id']) . '" is already registered at ' . $host . ':' . $port . ', '
                      . 'and the "Add a HestiaCP server" form can only add new ones — nothing was saved. '
                      . 'To change its keys or settings, use "Edit these settings" on the '
                      . '"' . ($s['label'] ?? $s['id']) . '" card above instead.'
                    : 'Another Hestia server is already using ' . $host . ':' . $port . ' ("' . ($s['label'] ?? $s['id']) . '").');
            header('Location: ' . $back); exit;
        }
    }

    if ($idx === null) $cfg['servers'][] = $candidate;
    else               $cfg['servers'][$idx] = $candidate;

    if (!infra_save_json($path, $cfg)) {
        infra_set_flash('err', 'Could not write the Hestia server list.');
        header('Location: ' . $back); exit;
    }

    infra_cache_forget('hestia:' . $candidate['id']);

    if (!hestia_server_configured($candidate)) {
        infra_set_flash('ok', 'Saved "' . $label . '" — recorded as a machine that is not set up yet. '
            . 'Nothing was contacted, because there is no Hestia on it to contact. '
            . 'Once it is installed and an access key exists, use "Edit these settings" to paste the pair in.');
        header('Location: ' . $back); exit;
    }

    $probe = hestia_probe($candidate);
    infra_set_flash($probe['ok'] ? 'ok' : 'warn',
        $probe['ok']
            ? 'Saved "' . $label . '" — the console can reach it.'
            : 'Saved "' . $label . '", but it could not be reached: ' . ($probe['error'] ?: 'no reply')
              . ($probe['code'] === 19 ? ' (the API is off, or this server\'s IP is not in API_ALLOWED_IP)' : '')
              . '.');
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
