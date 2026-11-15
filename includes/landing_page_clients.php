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
            // Without this, the hub page exists (reachable by URL) but is
            // literally undiscoverable from the client's own site — not a
            // real WP page/post, so it can never show up in search, related
            // posts, or any menu on its own. 'footer' (default) is safest:
            // a plain wp_footer() link works on nearly every theme,
            // including block-based ones, with zero risk of rewriting a
            // menu the client already curated by hand. 'nav' auto-injects
            // into the theme's primary nav menu instead — looks more native
            // when it works, but only works on classic (non-block) themes
            // with a menu actually assigned to that location. 'off' disables
            // this entirely.
            'nav_link_placement'     => 'footer', // 'off' | 'footer' | 'nav'
            'nav_link_label'         => 'View Our Service Locations',
            'active'                  => true,
            'last_checkin'            => 0,
            'last_emailed'            => '',
            // Dedup for tools/lpc_check_alerts.php — reset to '' the moment a
            // client is no longer Stale, so a FUTURE staleness episode alerts
            // immediately rather than waiting out an old window from a
            // previous, unrelated episode.
            'last_stale_alert_at'     => '',
        ],
        'billing' => [
            'setup_fee_per_location'  => 0,
            'monthly_fee_per_location'=> 0,
            'start_date'              => '',
        ],
        'reporting' => [
            // Search Console property URL (e.g. "https://mrrestore.com/" or
            // "sc-domain:mrrestore.com") — the CLIENT grants access by adding
            // our service account (gsc_service_account_email()) as a user on
            // this property in their own GSC account; nothing happens on our
            // end until they do. Empty = not connected, shown honestly as such.
            'gsc_property' => '',
        ],
        // Both fields below are deliberately inert — stored and editable from
        // day one, but nothing reads or acts on them yet. Added ahead of time
        // because it's unclear whether real Google Analytics/Search Console
        // access will be granted, and there's no dedicated Cloudflare account
        // for this solution yet (separate from the 20 niche-fleet CF
        // accounts) — so there's nothing real to wire either one into today.
        // Same "field exists from day one, options fill in later" pattern the
        // original design already used for the delivery-server assignment.
        'integrations' => [
            'ga_measurement_id'  => '', // e.g. "G-XXXXXXXXXX" — not injected into any delivered page yet
            'cloudflare_account' => '', // which CF account this client's CDN would use — no account exists yet
        ],
    ];
}

function lpc_default_page_data() {
    return [
        'page_id'      => '',
        'status'       => 'draft', // draft | published | needs_update
        // Manual toggle only — never set automatically by editing or saving
        // content. While true, lpc_generate_page_content() (the editor's
        // Regenerate button) refuses to overwrite this page; everything else
        // (Save, Publish) is unaffected.
        'locked'       => false,
        'created_at'   => '',
        'updated_at'   => '',
        'published_at' => null,
        'seo' => [
            'page_title'      => '',
            'meta_description'=> '',
            'og_image'        => '',
            // Never silently blank — lpc_generate_page_content() and
            // lpc_generate_drafts_for_target_city() both fill a default
            // ("{service} in {city}, {state}") the first time a page is
            // created if this is empty, same gotcha already hit for real on
            // the niche fleet (an empty primary_keyword silently degrades
            // every AI-generated section, see feedback_primary_keyword_drives
            // _ai_prompt_quality in memory).
            'primary_keyword'   => '',
            'secondary_keywords'=> [],
        ],
        // 16 sections, up from the original 6 — real per-city research (census
        // data) plus niche-injected framing needs room to breathe, matching the
        // depth the niche fleet's own landing pages carry. Still only 14
        // DELIVERY types (plugin contract): 'highlights' and 'steps' are two
        // new plain <ul>/<ol> lists, 'comparison' is a new AI-authored two-
        // column type, 'service_area'/'area_map'/'trust_bar'/'safety_toolkit'/
        // 'data_chart' are five more auto-computed (never AI-authored)
        // additions — every element used across all 14 types is exactly what
        // WordPress's block editor already produces for an ordinary post
        // (plus one <img>), so it inherits any theme's own styling with zero
        // custom CSS, same principle as the original 6 (verified live against
        // Twenty Twenty-Three/Four/Five and Astra). Multiple 'text' sections
        // need a 'key' to disambiguate which AI-authored content fills which —
        // the plugin's renderer only ever looks at 'type', never 'key'
        // (that's purely an admin/AI bookkeeping concern, invisible to
        // delivery).
        'sections' => [
            ['type' => 'hero', 'headline' => '', 'subheading' => '', 'image' => '', 'image_alt' => '', 'image_width' => null, 'image_height' => null, 'cta_text' => '', 'phone' => ''],
            // Static per-niche checkmark phrases (lpc_default_trust_bar_items()
            // fallback when a niche hasn't set its own) — auto-computed at
            // publish time, same as related_links/service_area/area_map below,
            // never AI-authored or per-city.
            ['type' => 'trust_bar', 'items' => []],
            ['type' => 'text',       'key' => 'city_intro',            'heading' => '', 'body' => ''],
            // Educational two-column "this vs that" block (e.g. water
            // restoration's "what survives a flood vs what doesn't",
            // appliance repair's "worth repairing vs worth replacing") — what
            // the two columns represent is niche-specific (content_slots.
            // comparison), AI-authored like the other text/list slots, new
            // section type since nothing existing models two parallel
            // labeled lists.
            ['type' => 'comparison', 'key' => 'comparison', 'heading' => '', 'intro' => '', 'label_a' => '', 'items_a' => [], 'label_b' => '', 'items_b' => []],
            ['type' => 'highlights', 'key' => 'feature_columns_local', 'heading' => '', 'items' => []],
            ['type' => 'text',       'key' => 'local_relevance',       'heading' => '', 'body' => ''],
            ['type' => 'steps',      'key' => 'how_it_works',          'heading' => '', 'items' => []],
            // 4 season cards, not one paragraph — reuses the 'highlights' type
            // (same card-grid as feature_columns_local), never a new delivery
            // type of its own. lpc_apply_ai_sections()'s 'highlights'/'steps'/
            // 'services_list'/'faq' case already handles any section matching
            // by 'key', so changing this one key's shape needed no new code
            // there, only a new AI prompt shape (see lpc_ai_user_prompt()).
            ['type' => 'highlights', 'key' => 'seasonal_calendar',     'heading' => '', 'items' => []],
            ['type' => 'text',       'key' => 'why_choose_us',         'heading' => '', 'body' => ''],
            ['type' => 'services_list',  'heading'  => '', 'items' => []],
            ['type' => 'faq',            'heading'  => '', 'items' => []],
            // Auto-computed at publish time (lpc_safety_toolkit_items()) —
            // reuses plugins/safety_toolkit's own per-niche data (5 real
            // national trust organizations, 3 shown, deterministic per
            // client). Never AI-generated or hand-edited; blank/[] when the
            // niche has no safety_toolkit data file yet.
            ['type' => 'safety_toolkit', 'heading' => 'Verify Your Provider', 'intro' => 'Before you choose a provider, these independent organizations can help you confirm licensing, industry standards, and track record.', 'items' => []],
            ['type' => 'cta',            'heading'  => '', 'body' => '', 'phone' => '', 'button_text' => ''],
            // Auto-computed at publish time (lpc_resolve_area_map()) — a real
            // map image, not hand-authored or AI-generated. Heading has a
            // fixed default (reset on regenerate, same as related_links'
            // below), the image itself is never editable by hand. Renders
            // nothing if there isn't enough real data to draw from (no
            // geocoded coords on record, or no real nearby towns within
            // range) — see landing_page_clients_area_images.php.
            ['type' => 'area_map', 'heading' => 'Service Area Map', 'image' => '', 'image_alt' => '', 'image_width' => null, 'image_height' => null],
            // Auto-computed at publish time (lpc_resolve_data_chart()), a real
            // climate chart from NOAA data — but unlike area_map, fetching the
            // underlying data is NOT automatic: it's an explicit admin "Fetch
            // Climate Data" action (page_editor.php), same "real external
            // data, not automatic" reasoning as the wizard's own "Fetch
            // Keyword Data" button. Renders nothing until that's been done at
            // least once for this city — see
            // includes/landing_page_clients_area_charts.php.
            ['type' => 'data_chart', 'heading' => '', 'image' => '', 'image_alt' => '', 'image_width' => null, 'image_height' => null, 'table_html' => ''],
            // Auto-computed at publish time (lpc_compute_service_area()), never
            // hand-authored or AI-generated — same "always recomputed fresh from
            // client.json, not just filled once" rule related_links already
            // follows below. State/office city are plain text; nearby_cities
            // links to that same service's page in the nearby city when one
            // is actually published (same safety rule related_links uses),
            // falling back to plain text otherwise.
            ['type' => 'service_area',   'heading'  => 'Our Service Area', 'intro' => '', 'state' => '', 'office_city' => '', 'nearby_cities' => []],
            // Fixed default heading, not blank — a freshly-generated or
            // regenerated page (reset straight from this schema, never
            // touched by a manual Save) shipped this section as a bare,
            // unlabeled list with no heading at all. client_save.php's own
            // content-save handler already sets 'Related Services' here;
            // this just makes that the real default instead of relying on
            // every page having been manually saved at least once first.
            ['type' => 'related_links',  'heading'  => 'Related Services', 'links' => []],
        ],
        'schema_jsonld' => null,
        // Rank-check is a real paid DataForSEO call (same cost model as the
        // wizard's keyword fetch) — cached here, refreshed only by an
        // explicit "Check Rankings" click on the Reporting tab, never
        // automatically on page load.
        'rank_position'   => null,
        'rank_checked_at' => null,
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
function lpc_create_client($identity, $niche_id = '') {
    $name = trim($identity['business_name'] ?? '');
    if ($name === '') return null;

    $client_id = lpc_unique_client_id($name);
    $data = lpc_default_client_data();
    $data['client_id']  = $client_id;
    $data['created_at'] = gmdate('c');
    $data['updated_at'] = $data['created_at'];
    $data['identity']   = array_merge($data['identity'], $identity);
    // "Per-niche-or-client uniqueness" per the design doc. $niche_id is the
    // wizard's own explicit "Which niche?" selection (Scott's ask — niche
    // picking should be a visible, deliberate question, not silently
    // inferred); only falls back to auto-matching industry_category via
    // lpc_match_niche_profile() if nothing was explicitly chosen. Set once
    // here and shown read-only on Content Profile going forward (a wrong
    // guess is a one-line manual fix, not a silent AI misclassification,
    // since this never auto-recomputes).
    $data['content_profile']['source'] = $niche_id !== ''
        ? 'niche:' . $niche_id
        : lpc_match_niche_profile($identity['industry_category'] ?? '');

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

/**
 * Real per-service keyword demand for one city — one DataForSEO phrase per
 * service ("{service} in {city}, {state}"), one batched infra_kw_fetch()
 * call (same provider module the wizard's own city-level keyword check
 * already uses, not a second keyword client). Returns
 * [service_id => ['volume'=>, 'cpc'=>, 'kd'=>]], keyed back by service_id
 * (not the raw phrase) so callers never re-derive the mapping themselves.
 * Deliberately NOT persisted here — the caller session-caches it, same
 * "transient, not written into client.json" choice the wizard's
 * $_SESSION['lpc_wizard_kw'] already made for the identical city-level case.
 * Callers must require_once admin/infra/lib/keywords.php first (same
 * convention as lpc_city_candidates() above).
 */
function lpc_service_keyword_check($services, $city, $state) {
    if (!infra_kw_has_creds('dataforseo')) return ['ok' => false, 'msg' => 'No DataForSEO credentials configured.', 'data' => []];
    if (!$services) return ['ok' => true, 'msg' => '', 'data' => []];

    $phraseBySvc = [];
    foreach ($services as $s) {
        // infra_kw_phrase() (not a hand-built string) — DataForSEO rejects a
        // literal comma in the keyword text, and this helper already strips
        // the kind of punctuation/county-suffix noise a raw city/state
        // concatenation would otherwise carry straight into the API call.
        $phraseBySvc[$s['service_id']] = infra_kw_phrase($s['label'] . ' in {city} {state}', ['city' => $city, 'state' => $state]);
    }
    $result = infra_kw_fetch('dataforseo', array_values($phraseBySvc));
    if (!$result['ok']) return ['ok' => false, 'msg' => $result['msg'] ?? 'Keyword lookup failed.', 'data' => []];

    $data = [];
    foreach ($phraseBySvc as $svcId => $phrase) {
        // infra_kw_dfs_fetch() keys its rows by strtolower($keyword) — a
        // case-sensitive lookup against the original phrase always silently
        // misses (found 2026-11-15; the wizard's own city-level fetch had
        // the identical bug, fixed alongside this one).
        $key = strtolower($phrase);
        if (isset($result['rows'][$key])) $data[$svcId] = $result['rows'][$key];
    }
    return ['ok' => true, 'msg' => '', 'data' => $data];
}

/**
 * Sends the handoff/key email to a client. Reuses the niche fleet's own
 * contact_send.php header-injection-safe construction (CRLF-stripped
 * headers, plain PHP mail() — no new email system for this one feature)
 * rather than building a second mail path. Not a literal call to
 * contact_send.php itself — that file is a public, CSRF/honeypot/rate
 * -limited POST handler for a totally different caller (a site visitor's
 * contact form); this is an authenticated admin action with its own inputs.
 *
 * "Plugin install instructions" deliberately does NOT include a real
 * download URL — there is no hosted public download for the plugin yet
 * (see project_landing_page_clients_feature_design memory), so this says
 * so honestly rather than link to something that doesn't exist.
 */
function lpc_send_client_email($client_id, $recipient, $includeKey, $includePluginInstructions, $message) {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return false;

    $client = lpc_load_client($client_id);
    if (!$client) return false;

    $body = trim($message) . "\n\n";
    if ($includeKey) {
        $body .= "Your API Key:\n" . lpc_get_client_api_key($client_id) . "\n\n";
    }
    if ($includePluginInstructions) {
        $body .= "Plugin install instructions:\n"
               . "1. Install the \"Landing Page Client\" plugin (contact us for the current file).\n"
               . "2. Activate it.\n"
               . "3. Go to Settings \xe2\x86\x92 Landing Page Client and paste in your API key above.\n\n";
    }

    $safeRecipient = str_replace(["\r", "\n"], '', $recipient);
    $subject = 'Your Landing Page Clients setup info — ' . ($client['identity']['business_name'] ?: $client_id);
    $headers = implode("\r\n", [
        'From: noreply@' . preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost'),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);

    if (!mail($safeRecipient, $subject, $body, $headers)) return false;

    $client['delivery']['last_emailed'] = gmdate('c');
    lpc_save_client($client_id, $client);
    return true;
}
