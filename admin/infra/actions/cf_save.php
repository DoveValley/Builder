<?php
/**
 * infra/actions/cf_save.php — add, edit, test and remove Cloudflare accounts (CSRF, PRG).
 * action = save | test | delete
 *
 * Writes admin/infra/config/cloudflare.json (0600, gitignored). The API token is a
 * secret: blank on an edit means "keep the stored one", so it is never rendered back.
 *
 * Deliberately preserves any stored email + global_key rather than editing them here.
 * cf_auth_headers() prefers a global key when present, and lib/registrar.php signs its
 * Cloudflare Registrar calls with the same record — so silently dropping those fields
 * from this form could break domain buying. Removing them is its own decision.
 *
 * "delete" removes the account RECORD. Zones, DNS and the sites behind them are
 * untouched; the console simply stops talking to that account.
 */
require_once __DIR__ . '/../bootstrap.php';

$back = '../index.php?view=cloudflare';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();

$action = (string) ($_POST['action'] ?? '');
$path   = infra_config_path('cloudflare.json');
$cfg    = infra_load_json($path, []);
if (!isset($cfg['accounts']) || !is_array($cfg['accounts'])) $cfg['accounts'] = [];

$findIdx = function (string $id) use ($cfg): ?int {
    foreach ($cfg['accounts'] as $i => $a) if (($a['id'] ?? '') === $id) return (int) $i;
    return null;
};

$id  = trim((string) ($_POST['id'] ?? ''));
$idx = $id !== '' ? $findIdx($id) : null;

/* ---- remove the record ------------------------------------------------ */
if ($action === 'delete') {
    if ($idx === null) {
        infra_set_flash('err', 'That account is not in the list.');
    } else {
        $label = $cfg['accounts'][$idx]['label'] ?? $id;
        array_splice($cfg['accounts'], $idx, 1);
        if (infra_save_json($path, $cfg)) {
            infra_cache_forget('cf_zones:' . $id);
            infra_set_flash('ok', 'Removed "' . $label . '" from the console. Your Cloudflare account, its zones and the websites behind them are untouched.');
        } else {
            infra_set_flash('err', 'Could not write the account list.');
        }
    }
    header('Location: ' . $back); exit;
}

/* ---- collect + check --------------------------------------------------- */
$label  = trim((string) ($_POST['label'] ?? ''));
$acctId = trim((string) ($_POST['account_id'] ?? ''));
$token  = trim((string) ($_POST['api_token'] ?? ''));

$errors = [];
if ($label  === '') $errors[] = 'Give the account a name.';
if ($acctId === '') $errors[] = 'Enter the Cloudflare account ID.';

$existing = $idx !== null ? $cfg['accounts'][$idx] : [];
if ($token === '') {
    if ($idx === null) $errors[] = 'Paste an API token.';
    else               $token = $existing['api_token'] ?? '';
}

if ($errors) {
    infra_set_flash('err', implode(' ', $errors));
    header('Location: ' . $back); exit;
}

// Keep every field this form does not manage — notably email/global_key, which the
// registrar path signs with.
$candidate = array_merge($existing, [
    'id'         => $id !== '' ? $id : 'acct-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'label'      => $label,
    'account_id' => $acctId,
    'api_token'  => $token,
]);
unset($candidate['niches']);   // never read by anything; dropped like the server one

/* ---- test without saving ---------------------------------------------- */
if ($action === 'test') {
    $probe = cf_probe($candidate);
    if (!empty($probe['ok'])) {
        $zones = cf_list_zones($candidate);
        infra_set_flash('ok', '✓ Cloudflare accepted these credentials — ' . count($zones) . ' zone(s) visible. Nothing was saved.');
    } else {
        infra_set_flash('err', '✗ Cloudflare rejected these credentials — ' . ($probe['error'] ?? $probe['message'] ?? 'no reply') . '. Nothing was saved.');
    }
    header('Location: ' . $back); exit;
}

/* ---- save ------------------------------------------------------------- */
if ($action === 'save') {
    foreach ($cfg['accounts'] as $i => $a) {
        if ($i === $idx) continue;
        if (strcasecmp($a['account_id'] ?? '', $acctId) === 0) {
            infra_set_flash('err', 'Another entry already uses that account ID ("' . ($a['label'] ?? $a['id']) . '").');
            header('Location: ' . $back); exit;
        }
    }

    if ($idx === null) $cfg['accounts'][] = $candidate;
    else               $cfg['accounts'][$idx] = $candidate;

    if (!infra_save_json($path, $cfg)) {
        infra_set_flash('err', 'Could not write the account list.');
        header('Location: ' . $back); exit;
    }
    infra_cache_forget('cf_zones:' . $candidate['id']);

    $probe = cf_probe($candidate);
    infra_set_flash(!empty($probe['ok']) ? 'ok' : 'warn',
        !empty($probe['ok'])
            ? 'Saved "' . $label . '" — Cloudflare accepted the credentials.'
            : 'Saved "' . $label . '", but Cloudflare rejected the credentials: '
              . ($probe['error'] ?? $probe['message'] ?? 'no reply'));
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
