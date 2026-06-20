<?php
/* ============================================================
   SHORTCODE SYSTEM
   Tokens: {city} {state} {SS} {city_state} {city_slug} {business} {phone} {zip} {website} {business_domain} {rating} {review_count}
   Values stored in $data['site_vars']. Applied at render time.
   ============================================================ */
function resolve_shortcodes(string $text): string {
    global $data;
    $v            = $data['site_vars']     ?? [];
    $lb           = $data['local_business'] ?? [];
    $city         = $v['city']      ?? '';
    $state        = $v['state']     ?? '';
    $SS           = $v['SS']        ?? '';
    $city_slug    = $v['city_slug'] ?? '';
    $business     = $v['business']  ?? '';
    $phone        = $v['phone']     ?? '';
    $tel          = $v['tel']       ?? '';
    $zip          = $v['zip']       ?? '';
    $website      = $v['website']   ?? '';
    $address      = $v['address']   ?? '';
    $lat          = $v['lat']       ?? '';
    $lng          = $v['lng']       ?? '';
    $business_domain = parse_url($website, PHP_URL_HOST) ?: $website;
    $rating       = $lb['lb_rating']       ?? '';
    $review_count = $lb['lb_review_count'] ?? '';
    $city_state   = $city && $SS ? $city . ', ' . $SS : $city . $SS;
    return str_replace(
        ['{city}', '{state}', '{SS}', '{city_state}', '{city_slug}', '{business}', '{phone}', '{tel}', '{zip}', '{website}', '{business_domain}', '{rating}', '{review_count}', '{address}', '{lat}', '{lng}'],
        [$city,    $state,    $SS,    $city_state,    $city_slug,    $business,    $phone,    $tel,    $zip,    $website,    $business_domain,    $rating,    $review_count,    $address,    $lat,    $lng],
        $text
    );
}

// ─── Course Schedule Shortcodes ───────────────────────────────────────────
$GLOBALS['_csm_w1_data'] = [];
$GLOBALS['_csm_w2_data'] = [];
$GLOBALS['_csm_sc_count'] = 0;

function load_courses(): array {
    if (!defined('COURSES_FILE') || !file_exists(COURSES_FILE)) return [];
    $raw = json_decode(file_get_contents(COURSES_FILE), true);
    return is_array($raw) ? $raw : [];
}

function save_courses(array $courses): bool {
    $dir = dirname(COURSES_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents(COURSES_FILE, json_encode(array_values($courses), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

function _csm_parse_sc_attrs(string $tag): array {
    $attrs = [];
    preg_match_all('/(\w+)="([^"]*)"/', $tag, $m);
    for ($i = 0, $n = count($m[1]); $i < $n; $i++) {
        $attrs[$m[1][$i]] = $m[2][$i];
    }
    return $attrs;
}

function _render_course_schedule_html(string $type, string $instanceId): string {
    $courses     = load_courses();
    $courseTypes = array_values(array_unique(array_column($courses, 'course_type')));
    sort($courseTypes);

    $showAll = strtolower($type) === 'all';
    if (!$showAll) {
        $courses = array_values(array_filter($courses, fn($c) => ($c['course_type'] ?? '') === $type));
    }
    $GLOBALS['_csm_w1_data'][$instanceId] = ['courses' => $courses];

    $typeOpts = '<option value="">All Course Types</option>';
    foreach ($courseTypes as $ct) {
        $typeOpts .= '<option value="' . h($ct) . '">' . h($ct) . '</option>';
    }

    ob_start(); ?>
<div class="csm-schedule-wrap" id="<?= h($instanceId) ?>" data-type="<?= h($type) ?>">
    <div class="csm-filters">
        <div class="csm-filter-group">
            <label>Course Type</label>
            <select class="csm-filter" data-filter="course_type"><?= $typeOpts ?></select>
        </div>
        <div class="csm-filter-group">
            <label>Delivery</label>
            <select class="csm-filter" data-filter="delivery">
                <option value="">All</option>
                <option value="Live-Virtual">Live-Virtual</option>
                <option value="On-Demand">On-Demand</option>
            </select>
        </div>
        <div class="csm-filter-group">
            <label>Timezone</label>
            <select class="csm-filter" data-filter="timezone">
                <option value="EST">EST</option>
                <option value="CST">CST</option>
                <option value="MST">MST</option>
                <option value="PST">PST</option>
            </select>
        </div>
        <div class="csm-filter-group csm-filter-reset">
            <button type="button" class="csm-reset-btn">Reset</button>
        </div>
    </div>
    <div class="csm-table-wrap">
        <table class="csm-table">
            <thead><tr>
                <th>Course</th><th>Delivery</th><th>Dates</th>
                <th>Time</th><th>Price</th><th>Register</th><th>Availability</th>
            </tr></thead>
            <tbody class="csm-tbody"><tr><td colspan="7" class="csm-loading">Loading&hellip;</td></tr></tbody>
        </table>
    </div>
    <div class="csm-mobile-cards">
        <div class="csm-mob-filters">
            <div class="csm-mob-filter-row">
                <span class="csm-mob-filter-label">Course Type</span>
                <select class="csm-mob-filter" data-mob-filter="course_type"><?= $typeOpts ?></select>
            </div>
            <div class="csm-mob-filter-row">
                <span class="csm-mob-filter-label">Delivery</span>
                <select class="csm-mob-filter" data-mob-filter="delivery">
                    <option value="">All</option>
                    <option value="Live-Virtual">Live-Virtual</option>
                    <option value="On-Demand">On-Demand</option>
                </select>
            </div>
            <div class="csm-mob-filter-row">
                <span class="csm-mob-filter-label">Timezone</span>
                <select class="csm-mob-filter" data-mob-filter="timezone">
                    <option value="EST">EST</option>
                    <option value="CST">CST</option>
                    <option value="MST">MST</option>
                    <option value="PST">PST</option>
                </select>
            </div>
            <div class="csm-mob-reset-row">
                <button type="button" class="csm-mob-reset-btn">Reset Filters</button>
            </div>
        </div>
        <div class="csm-mobile-scroll"><div class="csm-mcards"></div></div>
    </div>
</div>
<?php return ob_get_clean();
}

function _render_course_card_html(string $type, int $startTab, string $instanceId): string {
    $courses = load_courses();
    $showAll = strtolower($type) === 'all';
    if (!$showAll) {
        $courses = array_values(array_filter($courses, fn($c) => ($c['course_type'] ?? '') === $type));
    }
    $GLOBALS['_csm_w2_data'][$instanceId] = ['courses' => $courses];

    $tab1Active = ($startTab !== 2) ? ' csm2-active' : '';
    $tab2Active = ($startTab === 2) ? ' csm2-active' : '';

    ob_start(); ?>
<div class="csm2-wrap" id="<?= h($instanceId) ?>" data-type="<?= h($type) ?>" data-start-tab="<?= h($startTab) ?>">
    <div class="csm2-header">
        <div class="csm2-tabs">
            <button type="button" class="csm2-tab<?= $tab1Active ?>" data-delivery="Live-Virtual">Live-Virtual</button>
            <button type="button" class="csm2-tab<?= $tab2Active ?>" data-delivery="On-Demand">On-Demand</button>
        </div>
    </div>
    <div class="csm2-tz-row">
        <label class="csm2-tz-label">Timezone</label>
        <select class="csm2-tz-select">
            <option value="EST">EST</option>
            <option value="CST">CST</option>
            <option value="MST">MST</option>
            <option value="PST">PST</option>
        </select>
    </div>
    <div class="csm2-scroll-area">
        <div class="csm2-section-label"></div>
        <div class="csm2-cards"><div class="csm2-empty">Loading&hellip;</div></div>
    </div>
</div>
<?php return ob_get_clean();
}

function apply_course_shortcodes(string $html): string {
    $html = preg_replace_callback(
        '/\[course_schedule(?:\s[^\]]+)?\]/i',
        function ($m) {
            $attrs = _csm_parse_sc_attrs($m[0]);
            $type  = $attrs['type'] ?? 'All';
            $GLOBALS['_csm_sc_count']++;
            return _render_course_schedule_html($type, 'csm1_inst_' . $GLOBALS['_csm_sc_count']);
        },
        $html
    );
    $html = preg_replace_callback(
        '/\[course_card(?:\s[^\]]+)?\]/i',
        function ($m) {
            $attrs    = _csm_parse_sc_attrs($m[0]);
            $type     = $attrs['type'] ?? 'All';
            $startTab = (int)($attrs['tab'] ?? 1);
            $GLOBALS['_csm_sc_count']++;
            return _render_course_card_html($type, $startTab, 'csm2_inst_' . $GLOBALS['_csm_sc_count']);
        },
        $html
    );
    return $html;
}

function course_shortcode_inline_script(): string {
    $w1 = $GLOBALS['_csm_w1_data'] ?? [];
    $w2 = $GLOBALS['_csm_w2_data'] ?? [];
    if (empty($w1) && empty($w2)) return '';
    $out = '<script>';
    if (!empty($w1)) $out .= 'var csmAllData='  . json_encode($w1, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ';';
    if (!empty($w2)) $out .= 'var csm2AllData=' . json_encode($w2, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ';';
    $out .= '</script>';
    return $out;
}

function apply_shortcodes_to_block(array $block): array {
    static $skipKeys = ['photo','image','logo','icon','src','bg_photo','color','type','anchor','heading_level','ratio','position','align','style','layout','side'];
    foreach ($block as $key => $value) {
        if (is_string($value)) {
            $skip = false;
            if (!str_ends_with($key, '_alt')) {
                foreach ($skipKeys as $sk) {
                    if (stripos($key, $sk) !== false) { $skip = true; break; }
                }
            }
            if (!$skip) $block[$key] = resolve_shortcodes($value);
        } elseif (is_array($value)) {
            $block[$key] = apply_shortcodes_to_block($value);
        }
    }
    return $block;
}
