<?php
/**
 * infra/actions/registrar_save.php — manage registrar credentials (CSRF, PRG).
 * action = save | test | test_all | delete
 *
 * Secrets: a blank secret field means "keep what is stored", so the form can be
 * re-submitted without ever rendering a key back into the page. Only the fields
 * declared by infra_registrar_types() are accepted — a POST cannot invent keys.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/acquire.php';

$back = '../index.php?view=registrars';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

$action = (string) ($_POST['action'] ?? '');
$types  = infra_registrar_types();
$path   = infra_config_path('registrar.json');
$cfg    = infra_load_json($path, []);
if (!isset($cfg['registrars']) || !is_array($cfg['registrars'])) $cfg['registrars'] = [];

/** Existing config key for a type (registrars are keyed by name, usually == type). */
$findName = function (string $type) use ($cfg): ?string {
    foreach ($cfg['registrars'] as $name => $c) {
        if (strtolower($c['type'] ?? $name) === $type) return $name;
    }
    return null;
};

/* ---- test every configured registrar --------------------------------- */
if ($action === 'test_all') {
    $results = []; $lines = [];
    foreach (array_keys($cfg['registrars']) as $name) {
        $type = strtolower($cfg['registrars'][$name]['type'] ?? $name);
        $r = infra_registrar_verify($name);
        $results[$type] = $r;
        $bal = ($r['balance'] !== null && $r['balance'] !== '') ? "  balance {$r['balance']} {$r['currency']}" : '';
        $lines[] = ($r['ok'] ? '✓ ' : '✗ ') . $name . ': ' . $r['message'] . $bal;
    }
    $_SESSION['infra_reg_tests'] = $results;
    // The owned-domain index feeds "you already own this" — refresh it while we are here.
    infra_cache_forget('reg_owned');
    infra_owned_index_cached();
    infra_set_flash($lines ? 'ok' : 'warn', $lines ? implode("\n", $lines) : 'No registrars configured yet.');
    header('Location: ' . $back); exit;
}

$type = strtolower(trim((string) ($_POST['type'] ?? '')));
if (!isset($types[$type])) {
    infra_set_flash('err', 'Unknown registrar type.');
    header('Location: ' . $back); exit;
}
$def  = $types[$type];
$name = $findName($type) ?? $type;

switch ($action) {

    case 'save':
        $in  = (array) ($_POST['f'] ?? []);
        $rec = $cfg['registrars'][$name] ?? [];
        $rec['type'] = $type;
        $missing = [];
        foreach ($def['fields'] as $fname => $f) {
            $val = trim((string) ($in[$fname] ?? ''));
            if (!empty($f['secret']) && $val === '') {
                // blank secret = keep the stored one
                if (($rec[$fname] ?? '') === '') $missing[] = $f['label'];
                continue;
            }
            if ($val === '') { $missing[] = $f['label']; continue; }
            $rec[$fname] = $val;
        }
        if ($missing) {
            infra_set_flash('err', $def['label'] . ' not saved — missing: ' . implode(', ', $missing));
            break;
        }
        $cfg['registrars'][$name] = $rec;
        if (!infra_save_json($path, $cfg)) {
            infra_set_flash('err', 'Could not write config/registrar.json — check file permissions.');
            break;
        }
        // Verify straight away: saving a key that does not work is the failure to catch here.
        $v = infra_registrar_verify($name);
        $_SESSION['infra_reg_tests'] = [$type => $v];
        infra_cache_forget('reg_owned');
        infra_set_flash($v['ok'] ? 'ok' : 'warn',
            $def['label'] . ' saved. ' . ($v['ok'] ? 'Credentials verified.' : 'But the test failed: ' . $v['message']));
        break;

    case 'test':
        if (!isset($cfg['registrars'][$name])) {
            infra_set_flash('warn', 'Nothing saved for ' . $def['label'] . ' yet.');
            break;
        }
        $v = infra_registrar_verify($name);
        $_SESSION['infra_reg_tests'] = [$type => $v];
        $bal = ($v['balance'] !== null && $v['balance'] !== '') ? "  Balance {$v['balance']} {$v['currency']}." : '';
        infra_set_flash($v['ok'] ? 'ok' : 'err', $def['label'] . ': ' . $v['message'] . $bal);
        break;

    case 'delete':
        unset($cfg['registrars'][$name]);
        if (!infra_save_json($path, $cfg)) {
            infra_set_flash('err', 'Could not write config/registrar.json.');
            break;
        }
        infra_cache_forget('reg_owned');
        infra_set_flash('ok', $def['label'] . ' credentials removed.');
        break;

    default:
        infra_set_flash('err', 'Unknown action.');
}

header('Location: ' . $back);
