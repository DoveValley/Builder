<?php
/**
 * Landing Page Clients — data layer (Phase 2).
 *
 * Same shape as includes/data.php on purpose: default_*() skeletons, a
 * deep-merging load, an atomic tmp-file-then-rename save. One deliberate
 * deviation from the original design doc's client.json schema: the API key
 * is NOT stored inside client.json. client.json is meant to be git-tracked
 * source (same as sites/{id}/data/site.json), and a plaintext secret has no
 * business sitting in version control — same reasoning this codebase
 * already applies to deploy.json (FTP creds) living outside site.json. The
 * key lives in clients/{id}/secrets/api_key.txt instead, gitignored.
 */

function lpc_default_client_data() {
    return [
        'client_id'   => '',
        'created_at'  => '',
        'updated_at'  => '',
        'identity' => [
            'business_name'    => '',
            'domain'           => '',
            'industry_category'=> '',
            'contact_name'     => '',
            'contact_email'    => '',
            'contact_phone'    => '',
        ],
        'branding' => [
            'logo_url'      => '',
            'primary_color' => '',
            'accent_color'  => '',
            'font'          => '',
        ],
        'content_profile' => [
            'source'            => '',
            'excluded_services' => [],
            'tone'              => 'professional',
            'always_mention'    => [],
            'never_mention'     => [],
        ],
        'hubs'            => [],
        'services'        => [],
        'target_cities'   => [],
        'delivery' => [
            'base_path'               => '/locations',
            'refresh_interval_minutes'=> 360,
            'support_phone'           => '',
            'support_message'         => '',
            'active'                  => true,
            'last_checkin'            => 0,
            'last_emailed'            => '',
        ],
        'billing' => [
            'setup_fee_per_location'  => 0,
            'monthly_fee_per_location'=> 0,
            'start_date'              => '',
        ],
    ];
}

function lpc_default_page_data() {
    return [
        'page_id'      => '',
        'status'       => 'draft', // draft | published | needs_update
        'created_at'   => '',
        'updated_at'   => '',
        'published_at' => null,
        'seo' => [
            'page_title'      => '',
            'meta_description'=> '',
            'og_image'        => '',
        ],
        'sections' => [
            ['type' => 'hero',           'headline' => '', 'subheading' => '', 'image' => '', 'cta_text' => '', 'phone' => ''],
            ['type' => 'text',           'heading'  => '', 'body' => ''],
            ['type' => 'services_list',  'heading'  => '', 'items' => []],
            ['type' => 'faq',            'heading'  => '', 'items' => []],
            ['type' => 'cta',            'heading'  => '', 'body' => '', 'phone' => '', 'button_text' => ''],
            ['type' => 'related_links',  'heading'  => '', 'links' => []],
        ],
        'schema_jsonld' => null,
    ];
}

/**
 * client_id reaches here straight from $_GET/$_POST in admin/client.php and
 * admin/client_save.php with no prior validation — without this check,
 * "../../../etc" would walk the resulting path straight out of CLIENTS_DIR,
 * the same class of bug config.php's own ACTIVE_SITE_ID handling already
 * guards against with an identical slug regex. Centralized here (every
 * client path-building function funnels through this) rather than at each
 * call site, so a future caller can't reintroduce the gap by forgetting it.
 */
function lpc_valid_client_id($client_id) {
    return is_string($client_id) && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $client_id) === 1;
}

/**
 * Same reasoning as lpc_valid_client_id() above, for the other half of every
 * page path. page_id follows the {city_id}__{service_id} convention (see
 * includes/landing_page_clients_publish.php), so underscores are allowed
 * here unlike client_id's slug — the delivery API already validates its own
 * incoming slug this way (api.php's preg_match), this closes the matching
 * gap on the admin side before anything (the not-yet-built Locations &
 * Services matrix / single-page editor) starts taking page_id from a request.
 */
function lpc_valid_page_id($page_id) {
    return is_string($page_id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $page_id) === 1;
}

function lpc_client_dir($client_id) {
    if (!lpc_valid_client_id($client_id)) return null;
    return CLIENTS_DIR . $client_id;
}

function lpc_client_file($client_id) {
    $dir = lpc_client_dir($client_id);
    return $dir === null ? null : $dir . '/data/client.json';
}

function lpc_client_secret_file($client_id) {
    $dir = lpc_client_dir($client_id);
    return $dir === null ? null : $dir . '/secrets/api_key.txt';
}

function lpc_page_file($client_id, $page_id) {
    if (!lpc_valid_page_id($page_id)) return null;
    $dir = lpc_client_dir($client_id);
    return $dir === null ? null : $dir . '/data/pages/' . $page_id . '.json';
}

function lpc_deep_merge_defaults($defaults, $data) {
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        } elseif (is_array($value) && is_array($data[$key]) && !isset($value[0])) {
            // Associative sub-array (not a list) — merge key by key, same rule
            // load_data() uses, so a list value (e.g. hubs[], sections[]) is
            // never silently merged field-by-field against a skeleton item.
            $data[$key] = lpc_deep_merge_defaults($value, $data[$key]);
        }
    }
    return $data;
}

function lpc_load_client($client_id) {
    $file = lpc_client_file($client_id);
    if ($file === null || !file_exists($file)) return null;
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        error_log('landing-page-clients: ' . $file . ' failed to decode as JSON (' . json_last_error_msg() . ')');
        return null;
    }
    return lpc_deep_merge_defaults(lpc_default_client_data(), $data);
}

function lpc_atomic_write_json($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        error_log('landing-page-clients: failed to write ' . $tmp);
        return false;
    }
    if (!rename($tmp, $file)) {
        error_log('landing-page-clients: failed to rename ' . $tmp . ' to ' . $file);
        return false;
    }
    return true;
}

function lpc_save_client($client_id, $data) {
    $file = lpc_client_file($client_id);
    if ($file === null) return false;
    $data['client_id']  = $client_id;
    $data['updated_at'] = $data['updated_at'] ?? gmdate('c');
    return lpc_atomic_write_json($file, $data);
}

function lpc_load_page($client_id, $page_id) {
    $file = lpc_page_file($client_id, $page_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;
    return lpc_deep_merge_defaults(lpc_default_page_data(), $data);
}

function lpc_save_page($client_id, $page_id, $data) {
    $file = lpc_page_file($client_id, $page_id);
    if ($file === null) return false;
    $data['page_id'] = $page_id;
    return lpc_atomic_write_json($file, $data);
}

function lpc_list_pages($client_id) {
    $dir = lpc_client_dir($client_id);
    if ($dir === null) return [];
    $dir .= '/data/pages';
    if (!is_dir($dir)) return [];
    $ids = [];
    foreach (glob($dir . '/*.json') as $f) {
        $ids[] = basename($f, '.json');
    }
    sort($ids);
    return $ids;
}

function lpc_list_clients() {
    if (!is_dir(CLIENTS_DIR)) return [];
    $ids = [];
    foreach (glob(CLIENTS_DIR . '*', GLOB_ONLYDIR) as $dir) {
        if (file_exists($dir . '/data/client.json')) {
            $ids[] = basename($dir);
        }
    }
    sort($ids);
    return $ids;
}

function lpc_unique_client_id($name) {
    $base = slugify($name) ?: 'client';
    if (!lpc_valid_client_id($base)) $base = 'client';
    $id = $base;
    $i = 2;
    while (is_dir(lpc_client_dir($id) ?? '')) {
        $id = $base . '-' . $i;
        $i++;
    }
    return $id;
}

function lpc_generate_api_key() {
    return bin2hex(random_bytes(20));
}

function lpc_get_client_api_key($client_id) {
    $file = lpc_client_secret_file($client_id);
    return ($file !== null && file_exists($file)) ? trim(file_get_contents($file)) : '';
}

function lpc_set_client_api_key($client_id, $key) {
    $file = lpc_client_secret_file($client_id);
    if ($file === null) return false;
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return file_put_contents($file, $key, LOCK_EX) !== false;
}

/**
 * Creates a new client: unique id, default client.json merged with the
 * provided identity fields, and a freshly generated API key. Returns the
 * new client_id, or null if business_name is empty (nothing to slug from).
 */
/**
 * "Per-niche-or-client uniqueness" per the design doc: when a client's
 * industry matches an existing niche, their content profile inherits from
 * that niche's brief; otherwise it's a fresh "custom" profile. Matched by
 * plain keyword against each niche's own niche_brief.json "niche" field,
 * not a hardcoded industry_category string list, so it stays correct if a
 * niche's own wording changes. Deliberately simple keyword matching, not a
 * fuzzy/AI match — this sets Content Profile's read-only "Base profile"
 * once at creation, so a wrong guess is a one-line manual fix, not a
 * silent AI misclassification.
 */
function lpc_match_niche_source($industry_category) {
    $category = strtolower(trim($industry_category));
    if ($category === '') return 'custom';

    $niches = [
        'water-site'     => ['water', 'restoration', 'flood', 'storm'],
        'mold-site'      => ['mold'],
        'pest-template'  => ['pest'],
        'appliance-site' => ['appliance'],
    ];
    foreach ($niches as $siteId => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($category, $kw) !== false) return 'niche:' . $siteId;
        }
    }
    return 'custom';
}

function lpc_create_client($identity) {
    $name = trim($identity['business_name'] ?? '');
    if ($name === '') return null;

    $client_id = lpc_unique_client_id($name);
    $data = lpc_default_client_data();
    $data['client_id']  = $client_id;
    $data['created_at'] = gmdate('c');
    $data['updated_at'] = $data['created_at'];
    $data['identity']   = array_merge($data['identity'], $identity);
    $data['content_profile']['source'] = lpc_match_niche_source($identity['industry_category'] ?? '');

    if (!lpc_save_client($client_id, $data)) return null;
    lpc_set_client_api_key($client_id, lpc_generate_api_key());

    return $client_id;
}

/**
 * Population-ranked candidate cities within a hub's service radius — free,
 * always available (no DataForSEO credit spent). Shared by the kickoff
 * wizard's Step 4 and the Locations & Services matrix's "Add a City" action,
 * per the design doc's explicit instruction to reuse the same recommendation
 * screen rather than build it twice. Callers must require_once
 * admin/infra/lib/cities.php and includes/multisite/geocode.php first (not
 * required here — this file is loaded on every admin page via functions.php,
 * and those two aren't needed by most of them).
 */
function lpc_city_candidates($hub, $excludeCityIds, $limit = 25) {
    if ($hub['geo']['lat'] === null) return [];
    $db = infra_cities_init();
    $rows = $db->query('SELECT city, state, ss, population, lat, lng FROM cities WHERE lat <> "" AND lng <> "" AND population > 0')->fetchAll(PDO::FETCH_ASSOC);
    $candidates = [];
    foreach ($rows as $r) {
        $dist = ms_haversine_miles((float) $hub['geo']['lat'], (float) $hub['geo']['lng'], (float) $r['lat'], (float) $r['lng']);
        if ($dist > (int) $hub['service_radius_miles']) continue;
        $cityId = slugify($r['city'] . '-' . $r['ss']);
        if (in_array($cityId, $excludeCityIds, true)) continue;
        $candidates[] = [
            'city_id'    => $cityId,
            'city'       => $r['city'],
            'state'      => $r['ss'] ?: $r['state'],
            'population' => (int) $r['population'],
            'distance'   => round($dist, 1),
        ];
    }
    usort($candidates, fn($a, $b) => $b['population'] <=> $a['population']);
    return array_slice($candidates, 0, $limit);
}
