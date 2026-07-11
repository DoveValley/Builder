<?php
/* ============================================================
   SHORTCODE SYSTEM
   Tokens: {city} {state} {SS} {city_state} {city_slug} {business} {phone} {email} {zip} {website} {business_domain} {rating} {review_count} {primary_keyword} {service} {city_image} {city_image_alt} {city_image_credit}
   Values stored in $data['site_vars']. Applied at render time.
   {city_image}* tokens are populated by the City Image plugin (plugins/city-image).
   {primary_keyword}/{service} are PER-PAGE — read from $GLOBALS['_page_primary_keyword'],
   set by site-template.php before each page renders.
   ============================================================ */
function resolve_shortcodes(string $text): string {
    if (strpos($text, '{') === false) return $text;   // fast path — nothing to resolve
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
    $email        = $v['email']     ?? '';
    $address      = $v['address']   ?? '';
    $lat          = $v['lat']       ?? '';
    $lng          = $v['lng']       ?? '';
    $business_domain = parse_url($website, PHP_URL_HOST) ?: $website;
    $rating       = $lb['lb_rating']       ?? '';
    $review_count = $lb['lb_review_count'] ?? '';
    $city_state   = $city && $SS ? $city . ', ' . $SS : $city . $SS;
    $primary_keyword = $GLOBALS['_page_primary_keyword'] ?? '';   // per-page, set by site-template.php
    $map = [
        '{city}' => $city, '{state}' => $state, '{SS}' => $SS, '{city_state}' => $city_state,
        '{city_slug}' => $city_slug, '{business}' => $business, '{phone}' => $phone, '{tel}' => $tel,
        '{zip}' => $zip, '{website}' => $website, '{business_domain}' => $business_domain, '{email}' => $email,
        '{rating}' => $rating, '{review_count}' => $review_count, '{address}' => $address,
        '{lat}' => $lat, '{lng}' => $lng, '{primary_keyword}' => $primary_keyword, '{service}' => $primary_keyword,
    ];
    // Plugins may contribute their own tokens (e.g. City Image plugin adds {city_image}*).
    // Guard on hook presence so the hot path pays nothing when no plugin registers tokens.
    if (!empty($GLOBALS['_hooks']['shortcode_tokens'])) {
        $map = filter_hook('shortcode_tokens', $map);
    }
    return strtr($text, $map);
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
    $json = json_encode(array_values($courses), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = COURSES_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) return false;
    return rename($tmp, COURSES_FILE);
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

    $typeOptsHtml = '<option value="">All Course Types</option>';
    foreach ($courseTypes as $ct) {
        $typeOptsHtml .= '<option value="' . h($ct) . '">' . h($ct) . '</option>';
    }

    ob_start(); ?>
<div class="csm-schedule-wrap" id="<?= h($instanceId) ?>" data-type="<?= h($type) ?>" data-showall="<?= $showAll ? '1' : '0' ?>">
    <div class="csm-filters" role="search" aria-label="Course filters">
        <?php if ($showAll && !empty($courseTypes)) : ?>
        <div class="csm-filter-group">
            <label for="<?= h($instanceId) ?>_ct">Course Type</label>
            <select id="<?= h($instanceId) ?>_ct" class="csm-filter" data-filter="course_type" aria-label="Filter by course type"><?= $typeOptsHtml ?></select>
        </div>
        <?php endif; ?>
        <div class="csm-filter-group">
            <label for="<?= h($instanceId) ?>_del">Delivery</label>
            <select id="<?= h($instanceId) ?>_del" class="csm-filter" data-filter="delivery" aria-label="Filter by delivery method">
                <option value="">All</option>
                <option value="Live-Virtual">Live-Virtual</option>
                <option value="On-Demand">On-Demand</option>
            </select>
        </div>
        <div class="csm-filter-group">
            <label for="<?= h($instanceId) ?>_tz">Timezone</label>
            <select id="<?= h($instanceId) ?>_tz" class="csm-filter" data-filter="timezone" aria-label="Select timezone">
                <option value="EST">Eastern (EST)</option>
                <option value="CST">Central (CST)</option>
                <option value="MST">Mountain (MST)</option>
                <option value="PST">Pacific (PST)</option>
            </select>
        </div>
        <div class="csm-filter-group csm-filter-reset">
            <button class="csm-reset-btn" type="button" aria-label="Reset all filters">Reset Filters</button>
        </div>
    </div>
    <div class="csm-table-wrap">
        <table class="csm-table" aria-label="Course schedule">
            <thead><tr>
                <th scope="col">Course Type</th><th scope="col">Delivered</th><th scope="col">Date</th>
                <th scope="col">Time</th><th scope="col">Price</th><th scope="col"><span class="sr-only">Register</span></th><th scope="col">Availability</th>
            </tr></thead>
            <tbody class="csm-tbody"><tr><td colspan="7" class="csm-loading">Loading courses&#8230;</td></tr></tbody>
        </table>
    </div>
    <div class="csm-mobile-cards">
        <div class="csm-mob-filters" role="search" aria-label="Course filters">
            <?php if ($showAll && !empty($courseTypes)) : ?>
            <div class="csm-mob-filter-row">
                <label class="csm-mob-filter-label" for="<?= h($instanceId) ?>_m_ct">Course Type</label>
                <select id="<?= h($instanceId) ?>_m_ct" class="csm-mob-filter" data-mob-filter="course_type" aria-label="Filter by course type"><?= $typeOptsHtml ?></select>
            </div>
            <?php endif; ?>
            <div class="csm-mob-filter-row">
                <label class="csm-mob-filter-label" for="<?= h($instanceId) ?>_m_del">Delivery</label>
                <select id="<?= h($instanceId) ?>_m_del" class="csm-mob-filter" data-mob-filter="delivery" aria-label="Filter by delivery method">
                    <option value="">All</option>
                    <option value="Live-Virtual">Live-Virtual</option>
                    <option value="On-Demand">On-Demand</option>
                </select>
            </div>
            <div class="csm-mob-filter-row">
                <label class="csm-mob-filter-label" for="<?= h($instanceId) ?>_m_tz">Timezone</label>
                <select id="<?= h($instanceId) ?>_m_tz" class="csm-mob-filter csm-mob-tz" data-mob-filter="timezone" aria-label="Select timezone">
                    <option value="EST">Eastern (EST)</option>
                    <option value="CST">Central (CST)</option>
                    <option value="MST">Mountain (MST)</option>
                    <option value="PST">Pacific (PST)</option>
                </select>
            </div>
            <div class="csm-mob-reset-row">
                <button class="csm-mob-reset-btn" type="button" aria-label="Reset all filters">Reset</button>
            </div>
        </div>
        <div class="csm-mobile-scroll csm-mcards" aria-live="polite" aria-label="Course list"></div>
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
        <label class="csm2-tz-label" for="<?= h($instanceId) ?>_tz">Timezone</label>
        <select id="<?= h($instanceId) ?>_tz" class="csm2-tz-select" aria-label="Select timezone">
            <option value="EST">Eastern Standard Time (EST)</option>
            <option value="CST">Central Standard Time (CST)</option>
            <option value="MST">Mountain Standard Time (MST)</option>
            <option value="PST">Pacific Standard Time (PST)</option>
        </select>
    </div>
    <div class="csm2-scroll-area">
        <div class="csm2-section-label"></div>
        <div class="csm2-cards"><div class="csm2-empty">Loading&hellip;</div></div>
    </div>
</div>
<?php return ob_get_clean();
}

/* True if any content block on the current page contains a course shortcode.
   The course-widget CSS is render-blocking and lives in <head>, which renders
   before block bodies populate the shortcode-data globals — so we pre-scan the
   raw blocks here to decide whether that CSS is needed at all on this page. */
function page_uses_course_shortcodes(array $blocks): bool {
    $blob = json_encode($blocks);
    if ($blob === false) return false;
    return stripos($blob, '[course_schedule') !== false
        || stripos($blob, '[course_card') !== false;
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
            // Photo/image/src keys are normally skipped (they hold literal paths), but the
            // {city_image} token legitimately resolves to a path, so let it through.
            if ($skip && strpos($value, '{city_image') !== false) $skip = false;
            if (!$skip) $block[$key] = resolve_shortcodes($value);
        } elseif (is_array($value)) {
            $block[$key] = apply_shortcodes_to_block($value);
        }
    }
    return $block;
}
