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
    $business_domain = parse_url($website, PHP_URL_HOST) ?: $website;
    $rating       = $lb['lb_rating']       ?? '';
    $review_count = $lb['lb_review_count'] ?? '';
    $city_state   = $city && $SS ? $city . ', ' . $SS : $city . $SS;
    return str_replace(
        ['{city}', '{state}', '{SS}', '{city_state}', '{city_slug}', '{business}', '{phone}', '{tel}', '{zip}', '{website}', '{business_domain}', '{rating}', '{review_count}'],
        [$city,    $state,    $SS,    $city_state,    $city_slug,    $business,    $phone,    $tel,    $zip,    $website,    $business_domain,    $rating,    $review_count],
        $text
    );
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
