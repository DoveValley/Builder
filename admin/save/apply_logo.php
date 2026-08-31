<?php
// Apply a Logo Config to THIS site (single-site) — the Logo Library's own
// "Use for this site" action, mirrored from apply_preset.php but for the
// independent logo-text/icon axis (see includes/multisite/visual.php).
// Runs in save.php scope ($data loaded, saved after). Regenerates the logo in
// the site's CURRENT theme colors (unaffected — colors are the theme preset's
// job, not the Logo Config's), points the LocalBusiness logo at it, and
// records single_logo_id.
require_once __DIR__ . '/../../includes/multisite/visual.php'; // ms_generate_logo(), ms_resolve_logo_lines()

$activeTab = 'genvisual';

$logoFile = ACTIVE_SITE_DIR . '/multisite/logo_configs.json';
$doc      = @json_decode((string)@file_get_contents($logoFile), true) ?: [];
$logos    = is_array($doc['logos'] ?? null) ? $doc['logos'] : [];

$logoId = (int)($_POST['logo_id'] ?? 0);

// Find the config by its stored id (1-based), else by ordinal fallback.
$config = null;
foreach ($logos as $idx => $l) {
    if ((int)($l['id'] ?? ($idx + 1)) === $logoId) { $config = $l; break; }
}
if ($config === null) { $message = 'error:Logo+config+not+found+—+save+the+library+first.'; return; }

if (!function_exists('ms_convert_bin') || ms_convert_bin() === null) {
    $message = 'error:ImageMagick+not+available+on+this+server.';
    return;
}

$siteVars = [
    'business' => trim((string)($data['site_vars']['business'] ?? '')),
    'city'     => trim((string)($data['site_vars']['city']     ?? '')),
    'state'    => trim((string)($data['site_vars']['state']    ?? '')),
    'SS'       => trim((string)($data['site_vars']['SS']       ?? '')),
];
if ($siteVars['business'] === '') { $message = 'error:Set+a+business+name+first+(Header+tab).'; return; }

$lines = ms_resolve_logo_lines($config, $siteVars, ACTIVE_SITE_ID);
$logo  = ms_generate_logo($data, ACTIVE_SITE_DIR, $lines['line1'], $lines['line2'], 'brand', $lines['iconPath'], $lines['line1Color'], $lines['line2Color'], $lines['iconBg'], $lines['iconStyle']);

// Point the LocalBusiness schema logo at the generated file.
if ($logo && isset($data['local_business'])) {
    $data['local_business']['lb_logo'] = '{website}/' . $logo;
}

// Record which Logo Config this site now uses (persist single_logo_id).
$doc['single_logo_id'] = $logoId;
$tmp = $logoFile . '.tmp.' . getmypid();
if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
    @rename($tmp, $logoFile);
} else {
    @unlink($tmp);
}

$name = (string)($config['name'] ?? ('#' . $logoId));
$message = $logo
    ? 'success:Applied+logo+"' . rawurlencode($name) . '"+—+logo+%26+favicon+regenerated.'
    : 'error:Logo+config+applied+but+the+logo+could+not+be+generated.';
