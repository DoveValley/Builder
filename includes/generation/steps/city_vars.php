<?php
// Step: city_vars
// Sets city_vars on the page from the city object.
// Idempotent — safe to run multiple times.
// No external API calls, no cost.

function step_city_vars_meta(): array {
    return [
        'has_cost'    => false,
        'description' => 'Apply city variables (city, SS, phone, slug, etc.) to the page',
    ];
}

function step_city_vars(array $page, array $city, array $options): array {
    // city_vars drives shortcode resolution at render time (page.php merges
    // these over site_vars). We store the full city object so all {city},
    // {SS}, {phone}, {tel}, {zip}, {website}, {city_slug} etc. are available.
    $page['city_vars'] = $city;
    return $page;
}
