<?php
/**
 * findtreatment.gov — SAMHSA's treatment facility locator.
 *
 * One module for this service, per CLAUDE.md. It was previously called inline from
 * facilities_cli.php with a bare curl and no retry, alongside a second hand-rolled
 * Nominatim client that duplicated includes/multisite/geocode.php.
 *
 * The locator's response shape is the reason this is worth a module rather than a
 * one-line fetch: services arrive as an opaque list of code/value pairs (f2 is the
 * code, f3 the value), so reading "what levels of care does this place offer" means
 * knowing that SET and TC are the setting codes and PAY/PYAS are the payment ones.
 * That knowledge belongs here, not in a script.
 */

require_once __DIR__ . '/../../includes/http_get.php';

if (!defined('FINDTREATMENT_ENDPOINT')) {
    define('FINDTREATMENT_ENDPOINT', 'https://findtreatment.gov/locator/exportsAsJson/v2');
}
if (!defined('FINDTREATMENT_UA')) {
    define('FINDTREATMENT_UA', 'RecoveryDawn/1.0 (recovery-insurance directory)');
}

/** One service value off a facility row, by SAMHSA code. */
function findtreatment_service(array $row, string $code): string
{
    foreach ($row['services'] ?? [] as $s) {
        if (($s['f2'] ?? '') === $code) return (string) ($s['f3'] ?? '');
    }
    return '';
}

/**
 * Facilities near a point, newest API shape flattened to what the directory stores.
 *
 * @return array<int,array<string,string|array>> possibly empty; never null
 */
function findtreatment_near(string $lat, string $lng, int $limit = 10): array
{
    $url = FINDTREATMENT_ENDPOINT . '?' . http_build_query([
        'sType' => 'sa', 'sAddr' => $lat . ',' . $lng,
        'pageSize' => $limit, 'page' => 1, 'sort' => 0,
    ]);
    $d = http_get_json($url, ['ua' => FINDTREATMENT_UA, 'timeout' => 40, 'tries' => 3]);

    $out = [];
    foreach (($d['rows'] ?? []) as $r) {
        $out[] = [
            'name'    => trim((string) ($r['name1'] ?? '')),
            'street'  => trim(((string) ($r['street1'] ?? '')) . ' ' . ((string) ($r['street2'] ?? ''))),
            'city'    => (string) ($r['city'] ?? ''),
            'state'   => (string) ($r['state'] ?? ''),
            'zip'     => (string) ($r['zip'] ?? ''),
            'phone'   => (string) ($r['phone'] ?? ''),
            'lat'     => (string) ($r['latitude'] ?? ''),
            'lng'     => (string) ($r['longitude'] ?? ''),
            'miles'   => (string) ($r['miles'] ?? ''),
            'website' => (string) ($r['website'] ?? ''),
            'levels'  => findtreatment_levels(findtreatment_service($r, 'SET') . ' ' . findtreatment_service($r, 'TC')),
            'payment' => findtreatment_payments(findtreatment_service($r, 'PAY') . '; ' . findtreatment_service($r, 'PYAS')),
        ];
    }
    return $out;
}

/**
 * Levels of care, read out of the free-text setting fields.
 *
 * Kept character-for-character as it was, including the order and the exact labels
 * ("Inpatient", not "Hospital inpatient"). facilities.json already holds rows written
 * by this mapping, and a relabel here would quietly split the data into before and
 * after with nothing to say which a row came from.
 */
function findtreatment_levels(string $s): array
{
    $t = strtolower($s); $o = [];
    if (strpos($t, 'detox') !== false)                    $o[] = 'Detox';
    if (strpos($t, 'hospital inpatient') !== false)       $o[] = 'Inpatient';
    if (strpos($t, 'residential') !== false)              $o[] = 'Residential';
    if (strpos($t, 'partial hospitalization') !== false)  $o[] = 'PHP';
    if (strpos($t, 'intensive outpatient') !== false)     $o[] = 'IOP';
    if (strpos($t, 'outpatient') !== false)               $o[] = 'Outpatient';
    return array_values(array_unique($o));
}

/** Payment methods accepted. Same rule as above: unchanged, labels included. */
function findtreatment_payments(string $s): array
{
    $t = strtolower($s); $o = [];
    if (strpos($t, 'private health insurance') !== false) $o[] = 'Private insurance';
    if (strpos($t, 'medicaid') !== false)                 $o[] = 'Medicaid';
    if (strpos($t, 'medicare') !== false)                 $o[] = 'Medicare';
    if (strpos($t, 'military') !== false)                 $o[] = 'Military insurance';
    if (strpos($t, 'cash or self') !== false)             $o[] = 'Self-pay';
    if (strpos($t, 'sliding') !== false)                  $o[] = 'Sliding scale';
    if (strpos($t, 'state-financed') !== false || strpos($t, 'government funding') !== false) $o[] = 'State-funded';
    return array_values(array_unique($o));
}
