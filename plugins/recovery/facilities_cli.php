<?php
/**
 * Recovery plugin — SAMHSA facility fetcher (CLI, background).
 *
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/facilities_cli.php [--refresh]
 *
 * For each city: geocode (OSM Nominatim) → pull nearest SAMHSA substance-use facilities
 * → parse levels of care + payment/insurance types → store in facilities.json[city_slug].
 * Incremental save, paced (Nominatim = 1 req/sec), idempotent.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE.\n"); exit(2); }

$refresh = in_array('--refresh', $argv, true);
$dir = ACTIVE_SITE_DIR . '/data/recovery/';
$log = fopen('/tmp/facilities.log', 'w');

function _get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40, CURLOPT_USERAGENT=>'RecoveryDawn/1.0 (recovery-insurance directory)']);
    $r = curl_exec($ch); curl_close($ch); return $r;
}
function geocode($city, $state) {
    $q = rawurlencode("$city, $state, USA");
    $j = json_decode(_get("https://nominatim.openstreetmap.org/search?q=$q&format=json&limit=1&countrycodes=us"), true);
    return (is_array($j) && !empty($j[0])) ? [$j[0]['lat'], $j[0]['lon']] : null;
}
function svc($row, $code) { foreach ($row['services'] ?? [] as $s) if (($s['f2'] ?? '') === $code) return $s['f3'] ?? ''; return ''; }
function levels($s) { $t=strtolower($s); $o=[];
    if(strpos($t,'detox')!==false)$o[]='Detox'; if(strpos($t,'hospital inpatient')!==false)$o[]='Inpatient';
    if(strpos($t,'residential')!==false)$o[]='Residential'; if(strpos($t,'partial hospitalization')!==false)$o[]='PHP';
    if(strpos($t,'intensive outpatient')!==false)$o[]='IOP'; if(strpos($t,'outpatient')!==false)$o[]='Outpatient';
    return array_values(array_unique($o)); }
function payments($s) { $t=strtolower($s); $o=[];
    if(strpos($t,'private health insurance')!==false)$o[]='Private insurance'; if(strpos($t,'medicaid')!==false)$o[]='Medicaid';
    if(strpos($t,'medicare')!==false)$o[]='Medicare'; if(strpos($t,'military')!==false)$o[]='Military insurance';
    if(strpos($t,'cash or self')!==false)$o[]='Self-pay'; if(strpos($t,'sliding')!==false)$o[]='Sliding scale';
    if(strpos($t,'state-financed')!==false||strpos($t,'government funding')!==false)$o[]='State-funded';
    return array_values(array_unique($o)); }

$cities = json_decode(file_get_contents($dir.'cities.json'), true);
$sName = []; foreach (json_decode(file_get_contents($dir.'states.json'), true) as $s) $sName[$s['slug']] = $s['name'];
$fac = is_file($dir.'facilities.json') ? (json_decode(file_get_contents($dir.'facilities.json'), true) ?: []) : [];

$ok=0; $fail=0;
foreach ($cities as $c) {
    $slug = $c['slug'];
    if (!$refresh && !empty($fac[$slug])) continue;
    $ll = geocode($c['name'], $sName[$c['state']] ?? '');
    usleep(1200000); // Nominatim rate limit
    if (!$ll) { $fail++; fwrite($log,"GEO-FAIL {$c['name']}\n"); fflush($log); continue; }
    [$lat,$lng] = $ll;
    $d = json_decode(_get("https://findtreatment.gov/locator/exportsAsJson/v2?sType=sa&sAddr=".rawurlencode("$lat,$lng")."&pageSize=10&page=1&sort=0"), true);
    $rows = $d['rows'] ?? [];
    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'name'=>trim($r['name1']??''), 'street'=>trim(($r['street1']??'').' '.($r['street2']??'')),
            'city'=>$r['city']??'', 'state'=>$r['state']??'', 'zip'=>$r['zip']??'', 'phone'=>$r['phone']??'',
            'lat'=>$r['latitude']??'', 'lng'=>$r['longitude']??'', 'miles'=>$r['miles']??'', 'website'=>$r['website']??'',
            'levels'=>levels(svc($r,'SET').' '.svc($r,'TC')), 'payment'=>payments(svc($r,'PAY').'; '.svc($r,'PYAS')),
        ];
    }
    if (!$list) { $fail++; fwrite($log,"NO-FAC {$c['name']}\n"); fflush($log); continue; }
    $fac[$slug] = $list; $ok++;
    file_put_contents($dir.'facilities.json', json_encode($fac, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    fwrite($log, "OK   {$c['name']}: ".count($list)." facilities\n"); fflush($log);
    usleep(500000);
}
exec('chown -R www-data:www-data '.escapeshellarg(rtrim($dir,'/')));
fwrite($log, "\nDONE ok=$ok fail=$fail\n"); fclose($log);
