<?php
/**
 * City geocoding (lat/lng) for multisite — authoritative source, not AI.
 *
 * Coordinates are precise numeric facts an LLM will happily hallucinate, so we look
 * them up from OpenStreetMap's Nominatim geocoder (free, no API key). Results are
 * stored ONCE in the master's cities.json (keyed city|SS) and reused — like the rest
 * of the research data — so a rerun is free and stable across builds.
 *
 * Nominatim usage policy: identify yourself with a real User-Agent and send at most
 * ~1 request/second. Both are honored here.
 */

const MS_GEOCODE_ENDPOINT   = 'https://nominatim.openstreetmap.org/search';
const MS_GEOCODE_USER_AGENT = 'SiteFactory-Geocoder/1.0 (multisite city coordinates)';

/**
 * Look up one city's center coordinates. Returns ['lat'=>string,'lng'=>string] or null.
 * Tries "City, SS, USA" first, then "City, State, USA" as a fallback.
 */
function ms_geocode_city(string $city, string $ss = '', string $state = '', int $timeout = 15): ?array {
    $city = trim($city);
    if ($city === '') return null;
    if (!function_exists('curl_init')) return null;

    $queries = [];
    if ($ss !== '')    $queries[] = "{$city}, {$ss}, USA";
    if ($state !== '') $queries[] = "{$city}, {$state}, USA";
    $queries[] = "{$city}, USA";

    foreach (array_unique($queries) as $q) {
        $url = MS_GEOCODE_ENDPOINT . '?' . http_build_query([
            'q' => $q, 'format' => 'json', 'limit' => 1, 'addressdetails' => 0,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => MS_GEOCODE_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $http !== 200) continue;

        $rows = json_decode($body, true);
        if (is_array($rows) && isset($rows[0]['lat'], $rows[0]['lon'])) {
            $lat = round((float)$rows[0]['lat'], 6);
            $lng = round((float)$rows[0]['lon'], 6);
            if ($lat != 0.0 || $lng != 0.0) {
                return ['lat' => (string)$lat, 'lng' => (string)$lng];
            }
        }
    }
    return null;
}

/**
 * Fill missing lat/lng for every city in a cities.json file. Rate-limited to respect
 * Nominatim's ~1 req/sec policy. Cities that already have both values are skipped
 * (unless $force). Writes the file back only if anything changed.
 *
 * @param callable|null $log fn(string $msg): void — progress sink (echo/progress_log).
 * @return array ['filled'=>int,'skipped'=>int,'failed'=>int,'failures'=>string[]]
 */
function ms_geocode_cities_file(string $citiesFile, ?callable $log = null, bool $force = false): array {
    $out = ['filled' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => []];
    $say = $log ?? static function () {};

    $cities = json_decode((string)@file_get_contents($citiesFile), true);
    if (!is_array($cities)) { $say("No cities.json to geocode: {$citiesFile}"); return $out; }

    $isAssoc = array_keys($cities) !== range(0, count($cities) - 1);
    $changed = false;
    $first = true;

    foreach ($cities as $k => $c) {
        if (!is_array($c)) continue;
        $city = trim((string)($c['city'] ?? ''));
        if ($city === '') continue;

        $hasCoords = trim((string)($c['lat'] ?? '')) !== '' && trim((string)($c['lng'] ?? '')) !== '';
        if ($hasCoords && !$force) { $out['skipped']++; continue; }

        // Rate-limit: ~1 req/sec, but not before the first lookup.
        if (!$first) sleep(1);
        $first = false;

        $geo = ms_geocode_city($city, (string)($c['SS'] ?? ''), (string)($c['state'] ?? ''));
        $label = $city . (($c['SS'] ?? '') !== '' ? ', ' . $c['SS'] : '');
        if ($geo === null) {
            $out['failed']++; $out['failures'][] = $label;
            $say("  ✗ could not geocode {$label}");
            continue;
        }
        $cities[$k]['lat'] = $geo['lat'];
        $cities[$k]['lng'] = $geo['lng'];
        $changed = true;
        $out['filled']++;
        $say("  ✓ {$label} → {$geo['lat']}, {$geo['lng']}");
    }

    if ($changed) {
        $payload = $isAssoc ? $cities : array_values($cities);
        $tmp = $citiesFile . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $citiesFile);
    }
    return $out;
}

/**
 * Great-circle distance between two lat/lng points, in miles.
 */
function ms_haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusMiles = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadiusMiles * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Find the closest city in the static major-US-cities reference list to a given point.
 * $excludeCity/$excludeSS skip a same-name/state entry (the source city itself may also
 * be in the major-cities list, e.g. Ann Arbor, MI — without this it would "match itself"
 * at 0 miles). Returns ['city'=>string,'state'=>string,'SS'=>string,'miles'=>float] or
 * null if the reference list can't be read or the input coordinates are blank.
 */
function ms_nearest_major_city(float $lat, float $lng, string $excludeCity = '', string $excludeSS = ''): ?array {
    static $majors = null;
    if ($majors === null) {
        $majors = json_decode((string)@file_get_contents(__DIR__ . '/major_us_cities.json'), true);
        if (!is_array($majors)) $majors = [];
    }
    $excludeCity = strtolower(trim($excludeCity));
    $excludeSS   = strtolower(trim($excludeSS));
    $best = null; $bestMiles = null;
    foreach ($majors as $m) {
        if (!is_array($m) || !isset($m['lat'], $m['lng'])) continue;
        if ($excludeCity !== '' && strtolower((string)($m['city'] ?? '')) === $excludeCity
            && strtolower((string)($m['SS'] ?? '')) === $excludeSS) continue;
        $miles = ms_haversine_miles($lat, $lng, (float)$m['lat'], (float)$m['lng']);
        if ($bestMiles === null || $miles < $bestMiles) { $bestMiles = $miles; $best = $m; }
    }
    if ($best === null) return null;
    return ['city' => $best['city'], 'state' => $best['state'] ?? '', 'SS' => $best['SS'] ?? '', 'miles' => round($bestMiles)];
}

/**
 * Fill a build row's blank lat/lng from the master's cities.json (matched by city|SS).
 * Lets the params CSV leave coordinates blank — they flow in from the geocoded research.
 * Returns the params array, possibly with lat/lng added.
 */
function ms_fill_coords_from_cities(array $params, string $masterId): array {
    $hasLat = trim((string)($params['lat'] ?? '')) !== '';
    $hasLng = trim((string)($params['lng'] ?? '')) !== '';
    if ($hasLat && $hasLng) return $params;

    $city = strtolower(trim((string)($params['city'] ?? '')));
    if ($city === '') return $params;
    $ss = strtolower(trim((string)($params['SS'] ?? '')));

    $file = BASE_DIR . '/sites/' . $masterId . '/data/cities.json';
    $cities = json_decode((string)@file_get_contents($file), true);
    if (!is_array($cities)) return $params;

    foreach ($cities as $c) {
        if (!is_array($c)) continue;
        if (strtolower(trim((string)($c['city'] ?? ''))) !== $city) continue;
        if ($ss !== '' && strtolower(trim((string)($c['SS'] ?? ''))) !== $ss) continue;
        if (!$hasLat && trim((string)($c['lat'] ?? '')) !== '') $params['lat'] = (string)$c['lat'];
        if (!$hasLng && trim((string)($c['lng'] ?? '')) !== '') $params['lng'] = (string)$c['lng'];
        break;
    }
    return $params;
}
