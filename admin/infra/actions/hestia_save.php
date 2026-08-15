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

// Blank secrets on an edit mean keep the stored pair; a new server must supply both.
$storedA = $idx !== null ? ($cfg['servers'][$idx]['access_key'] ?? '') : '';
$storedS = $idx !== null ? ($cfg['servers'][$idx]['secret_key'] ?? '') : '';
if ($aKey === '' && $sKey === '') {
    if ($idx === null) $errors[] = 'Paste the Hestia access key and secret key.';
    else { $aKey = $storedA; $sKey = $storedS; }
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
];

/* ---- test without saving ---------------------------------------------- */
if ($action === 'test') {
    infra_http_calls_reset();
    $t0    = microtime(true);
    $probe = hestia_probe($candidate);
    if ($probe['ok']) {
        $facts = infra_hestia_facts(hestia_server_info($candidate));
        $users = hestia_list_users($candidate);
        $sites = hestia_list_sites($candidate);
        $ms    = (int) round((microtime(true) - $t0) * 1000);
        infra_set_flash('ok', '✓ Reached ' . $host . ' — Hestia ' . ($facts['panel_version'] ?: '?')
            . ', ' . count($sites) . ' website(s) under ' . count($users) . ' account(s). '
            . 'Took ' . infra_http_calls() . ' API calls, ' . $ms . 'ms. Nothing was saved.');
    } else {
        // The one failure worth naming, because it is invisible from the status
        // code: Hestia answers HTTP 200 with the body "Error" when the API is off
        // or the caller IP is not allowed. It is not a wrong-password problem.
        $hint = $probe['code'] === 19
            ? ' — run v-add-sys-api on the box and add this server\'s IP to API_ALLOWED_IP'
            : '';
        infra_set_flash('err', '✗ Could not reach ' . $host . ':' . $port . ' — ' . ($probe['error'] ?: 'no reply')
            . $hint . '. Nothing was saved.');
    }
    header('Location: ' . $back); exit;
}

/* ---- save ------------------------------------------------------------- */
if ($action === 'save') {
    foreach ($cfg['servers'] as $i => $s) {
        if ($i === $idx) continue;
        if (strcasecmp($s['host'] ?? '', $host) === 0 && (int) ($s['port'] ?? 0) === $port) {
            infra_set_flash('err', 'Another Hestia server is already using ' . $host . ':' . $port . ' ("' . ($s['label'] ?? $s['id']) . '").');
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
