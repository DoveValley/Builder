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
require_once __DIR__ . '/../lib/cf_alloc.php';   // binding an account to a box

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

/* ---- bind an account to a box ----------------------------------------- */
/* The pairing is stored ON THE ACCOUNT — one field, one value — so "an account can
 * only be on one box" is not a rule anything has to enforce. See lib/cf_alloc.php. */
if ($action === 'bind' || $action === 'unbind') {
    if ($idx === null) {
        infra_set_flash('err', 'That account is not in the list.');
        header('Location: ' . $back); exit;
    }
    // Unbind is its own action rather than "bind with an empty box". It used to be a
    // submit button named server_id with an empty value, sitting in a form that already
    // had a hidden server_id — so which one won came down to DOM order, and a binding
    // could be cleared by a click that did not look like it was clearing anything.
    // That is how CF #1 lost its box while keeping the cap somebody had typed.
    $srv = $action === 'unbind' ? '' : trim((string) ($_POST['server_id'] ?? ''));
    if ($srv !== '' && !infra_hestia_server($srv)) {
        infra_set_flash('err', 'That box is not in the registry.');
        header('Location: ' . $back); exit;
    }
    $cfg['accounts'][$idx]['server_id'] = $srv;
    $cfg['accounts'][$idx]['order']  = max(0, (int) ($_POST['order'] ?? 0));
    // OPEN or CLOSED, not a number. Placement reads this and nothing else — no counts,
    // no cache, nothing that can be stale or fetched from the wrong account. Closing is
    // a footprint decision a person makes; Cloudflare enforces nothing.
    if (array_key_exists('closed', $_POST)) {
        $cfg['accounts'][$idx]['closed'] = ((string) $_POST['closed']) === 'yes' ? 'yes' : '';
    }

    if (!infra_save_json($path, $cfg)) {
        infra_set_flash('err', 'Could not write the account list.');
        header('Location: ' . $back); exit;
    }
    $lbl = $cfg['accounts'][$idx]['label'] ?? $id;
    $box = $srv !== '' ? (infra_hestia_server($srv)['label'] ?? $srv) : '';
    $shut = !infra_cf_is_open($cfg['accounts'][$idx]);
    infra_set_flash('ok', $srv === ''
        ? '"' . $lbl . '" is no longer bound to a box. Its existing zones are untouched.'
        : '"' . $lbl . '" is bound to ' . $box . ' and is '
          . ($shut ? 'CLOSED — it will not take new zones' : 'taking new zones for it')
          . '. Zones already in it are untouched — Cloudflare cannot move a zone between accounts.');
    header('Location: ' . $back); exit;
}

/* ---- ask Cloudflare which accounts this credential can see ------------- */
if ($action === 'discover') {
    // STORES the answer; the page renders what is stored. The page itself never goes
    // to the network — same rule as every other tab in this console, and the reason
    // opening one is instant.
    $d = infra_cf_discover();
    infra_cache_put('cf_discovered', $d);
    infra_set_flash($d['ok'] ? 'ok' : 'err', $d['msg']);
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

// The box is part of the account form now, so an account can be created already bound
// instead of appearing as an option on all twenty box cards afterwards. Only applied
// when the form actually carried the field — the bind action posts none of these, and
// merging absent fields would wipe a binding made elsewhere.
if (array_key_exists('server_id', $_POST)) {
    $srv = trim((string) $_POST['server_id']);
    if ($srv !== '' && !infra_hestia_server($srv)) {
        infra_set_flash('err', 'That box is not in the registry.');
        header('Location: ' . $back); exit;
    }
    $candidate['server_id'] = $srv;
}
if (array_key_exists('order', $_POST))  $candidate['order']  = max(0, (int) $_POST['order']);
if (array_key_exists('closed', $_POST)) $candidate['closed'] = ((string) $_POST['closed']) === 'yes' ? 'yes' : '';
unset($candidate['niches']);   // never read by anything; dropped like the server one

/* ---- test without saving ---------------------------------------------- */
if ($action === 'test') {
    $probe = cf_probe($candidate);
    if (!empty($probe['ok'])) {
        $zones = cf_list_zones($candidate);
        // Naming the account that answered is the whole point of the test. "Credentials
        // accepted" says the token works; "connected to <name>, 0 zones" is what tells
        // you the id you pasted belongs to the account you meant.
        $nm = (string) ($probe['name'] ?? '') !== '' ? $probe['name'] : cf_account_name($candidate);
        // VERIFIED and COULD-NOT-CHECK are different answers and get different words.
        // Saying "connected" for the second one is how fourteen accounts that could not
        // create a single zone sat green on this page.
        if (($probe['state'] ?? '') === 'unverified') {
            infra_set_flash('warn', '⚠ The token works and Cloudflare knows that account id — but access to '
                . 'it could NOT be confirmed, because the token cannot list its own accounts. An account you '
                . 'have no rights to answers exactly like an empty account you own. Add "Account Settings: Read" '
                . 'to the token to make this checkable. Nothing was saved.');
        } else {
            infra_set_flash('ok', '✓ Verified — this token really can use '
                . ($nm !== '' ? '"' . $nm . '"' : 'that account')
                . ', ' . count($zones) . ' zone(s) in it. Check that is the account you meant. Nothing was saved.');
        }
    } else {
        infra_set_flash('err', ($probe['state'] ?? '') === 'foreign'
            ? '✗ That account id is real, but this token cannot use it — ' . $probe['error']
              . ' Nothing was saved.'
            : '✗ Cloudflare would not confirm that account — ' . ($probe['error'] ?: 'no reply')
              . '. Either the account ID is wrong or this token cannot see it. Nothing was saved.');
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
            ? 'Saved "' . $label . '" — connected to ' . (($nm = cf_account_name($candidate)) !== '' ? '"' . $nm . '"' : 'that account') . ' at Cloudflare.'
            : 'Saved "' . $label . '", but Cloudflare would not confirm that account: '
              . ($probe['error'] ?: 'no reply')
              . ' — either the account ID is wrong or this token cannot see it.');
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
