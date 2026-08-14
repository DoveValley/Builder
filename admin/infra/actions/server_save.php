<?php
/**
 * infra/actions/server_save.php — add, edit, test and remove Plesk servers (CSRF, PRG).
 * action = save | test | delete
 *
 * Writes admin/infra/config/servers.json (0600, gitignored). The API token is a
 * secret: a blank token field on an edit means "keep the stored one", so the form
 * never has to render an existing token back into the page.
 *
 * "delete" removes the RECORD, not the machine. The VPS keeps running and its
 * websites stay up — the console simply stops talking to it. Removing an actual
 * site lives on that domain's own page, behind its danger zone.
 */
require_once __DIR__ . '/../bootstrap.php';

$back = '../index.php?view=servers';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

$action = (string) ($_POST['action'] ?? '');
$path   = infra_config_path('servers.json');
$cfg    = infra_load_json($path, []);
if (!isset($cfg['servers']) || !is_array($cfg['servers'])) $cfg['servers'] = [];

/** Index of the server with this id, or null. */
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
            infra_cache_forget('server:' . $id);
            infra_set_flash('ok', 'Removed "' . $label . '" from the console. The server itself is untouched — it is still running and its websites are still up.');
        } else {
            infra_set_flash('err', 'Could not write the server list.');
        }
    }
    header('Location: ' . $back); exit;
}

/* ---- collect + check the fields (shared by save and test) -------------- */
$label   = trim((string) ($_POST['label'] ?? ''));
$host    = trim((string) ($_POST['host'] ?? ''));
$port    = (int) ($_POST['port'] ?? 8443);
$token   = trim((string) ($_POST['api_token'] ?? ''));
$ip      = trim((string) ($_POST['default_ip'] ?? ''));
$sshUser = trim((string) ($_POST['ssh_user'] ?? ''));
$sshKey  = trim((string) ($_POST['ssh_key'] ?? ''));

// Strip a pasted scheme/port/path — people copy the browser bar, and "https://1.2.3.4:8443"
// in the host field produces a confusing connection error rather than an obvious one.
$host = preg_replace('#^https?://#i', '', $host);
$host = preg_replace('#[/:].*$#', '', $host);

$errors = [];
if ($label === '') $errors[] = 'Give the server a name.';
if ($host  === '') $errors[] = 'Enter the address you log in to Plesk at.';
if ($port < 1 || $port > 65535) $errors[] = 'Port must be a number between 1 and 65535.';

// Editing with a blank token means keep the stored one; a new server must supply it.
$stored = $idx !== null ? ($cfg['servers'][$idx]['api_token'] ?? '') : '';
if ($token === '') {
    if ($idx === null) $errors[] = 'Paste the Plesk API key.';
    else               $token = $stored;
}
if ($ip === '') $ip = $host;   // sensible default: the box answers on its own address

if ($errors) {
    infra_set_flash('err', implode(' ', $errors));
    header('Location: ' . $back); exit;
}

$candidate = [
    'id'         => $id !== '' ? $id : 'vps-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'label'      => $label,
    'host'       => $host,
    'port'       => $port,
    'api_token'  => $token,
    'ssh_user'   => $sshUser,
    'ssh_key'    => $sshKey,
    'default_ip' => $ip,
];

/* ---- test without saving ---------------------------------------------- */
if ($action === 'test') {
    $probe = plesk_probe($candidate);
    if ($probe['ok']) {
        $info = plesk_server_info($candidate);
        $sites = plesk_list_sites($candidate);
        infra_set_flash('ok', '✓ Reached ' . $host . ' — Plesk ' . ($info['panel_version'] ?? '?')
            . ', ' . count($sites) . ' website(s) on it. Nothing was saved.');
    } else {
        infra_set_flash('err', '✗ Could not reach ' . $host . ':' . $port . ' — ' . ($probe['error'] ?: 'no reply')
            . '. Nothing was saved.');
    }
    header('Location: ' . $back); exit;
}

/* ---- save ------------------------------------------------------------- */
if ($action === 'save') {
    // A second server on the same host:port is almost always a mistake, not two boxes.
    foreach ($cfg['servers'] as $i => $s) {
        if ($i === $idx) continue;
        if (strcasecmp($s['host'] ?? '', $host) === 0 && (int) ($s['port'] ?? 0) === $port) {
            infra_set_flash('err', 'Another server is already using ' . $host . ':' . $port . ' ("' . ($s['label'] ?? $s['id']) . '").');
            header('Location: ' . $back); exit;
        }
    }

    if ($idx === null) $cfg['servers'][] = $candidate;
    else               $cfg['servers'][$idx] = $candidate;

    if (!infra_save_json($path, $cfg)) {
        infra_set_flash('err', 'Could not write the server list.');
        header('Location: ' . $back); exit;
    }

    // Its cached probe is now stale — the address or key may have changed.
    infra_cache_forget('server:' . $candidate['id']);

    $probe = plesk_probe($candidate);
    infra_set_flash($probe['ok'] ? 'ok' : 'warn',
        $probe['ok']
            ? 'Saved "' . $label . '" — the console can reach it.'
            : 'Saved "' . $label . '", but it could not be reached: ' . ($probe['error'] ?: 'no reply')
              . '. Check the address and key.');
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
