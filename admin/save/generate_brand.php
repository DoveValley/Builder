<?php
// Generate brand assets (logo + favicon) from this site's Logo Library selection
// + current palette. Runs in save.php scope ($data, $message, $activeTab). First
// applies the posted palette (reusing the theme handler) so "change colors →
// Generate" is one click, then renders the two-tone wordmark (+ bug-tile icon
// and matching favicon, if the active Logo Config has one) in those colors.
require __DIR__ . '/theme.php';                               // apply posted palette to $data['theme']
require_once __DIR__ . '/../../includes/multisite/visual.php'; // ms_generate_logo(), ms_resolve_logo_lines(), ms_convert_bin()

$activeTab = 'genvisual';
$siteVars  = [
    'business' => trim((string)($data['site_vars']['business'] ?? '')),
    'city'     => trim((string)($data['site_vars']['city']     ?? '')),
    'state'    => trim((string)($data['site_vars']['state']    ?? '')),
    'SS'       => trim((string)($data['site_vars']['SS']       ?? '')),
];

if ($siteVars['business'] === '') {
    $message = 'error:Set+a+business+name+first+(Header+tab).';
    return;
}
if (!function_exists('ms_convert_bin') || ms_convert_bin() === null) {
    $message = 'error:ImageMagick+not+available+on+this+server.';
    return;
}

// This site's own Logo Config (single_logo_id), or the zero-config default
// (business/city, no icon) if the library is empty or nothing's picked yet.
$logoDoc    = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/logo_configs.json'), true) ?: [];
$logoConfigs = is_array($logoDoc['logos'] ?? null) ? $logoDoc['logos'] : [];
$singleLogoId = (int)($logoDoc['single_logo_id'] ?? 0);
$logoConfig = null;
foreach ($logoConfigs as $idx => $l) {
    if ((int)($l['id'] ?? ($idx + 1)) === $singleLogoId) { $logoConfig = $l; break; }
}
$lines = ms_resolve_logo_lines($logoConfig, $siteVars, ACTIVE_SITE_ID);

$logo = ms_generate_logo($data, ACTIVE_SITE_DIR, $lines['line1'], $lines['line2'], 'brand', $lines['iconPath'], $lines['line1Color'], $lines['line2Color'], $lines['iconBg'], $lines['iconStyle']);

// point the LocalBusiness schema logo at the generated file
if ($logo && isset($data['local_business'])) {
    $data['local_business']['lb_logo'] = '{website}/' . $logo;
}

$message = $logo
    ? 'success:Logo+%26+favicon+generated+from+your+palette.'
    : 'error:Could+not+generate+the+logo.';
