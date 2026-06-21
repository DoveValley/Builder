<?php
function default_starters(): array {
    return [
        [
            'id'     => 'service',
            'label'  => 'Service page',
            'desc'   => 'Hero · Features · Pricing · FAQ · CTA',
            'blocks' => ['hero_split', 'feature_columns', 'pricing_cards', 'faq_two_col', 'cta_banner'],
        ],
        [
            'id'     => 'about',
            'label'  => 'About page',
            'desc'   => 'Hero · Stats · Team · Testimonials · CTA',
            'blocks' => ['hero', 'stats', 'team', 'testimonials', 'cta_banner'],
        ],
        [
            'id'     => 'landing',
            'label'  => 'Landing page',
            'desc'   => 'Hero · Feature split · Service cards · CTA',
            'blocks' => ['hero_split', 'feature_split', 'service_cards', 'cta_banner'],
        ],
        [
            'id'     => 'city',
            'label'  => 'City landing page',
            'desc'   => 'Hero · Features · Links grid · CTA',
            'blocks' => ['hero_split', 'feature_columns', 'links_grid', 'cta_banner'],
        ],
        [
            'id'     => 'contact',
            'label'  => 'Contact page',
            'desc'   => 'Hero · Map & info · Contact form',
            'blocks' => ['hero', 'map_info', 'contact_form'],
        ],
        [
            'id'     => 'legal',
            'label'  => 'Legal / policy page',
            'desc'   => 'Text only',
            'blocks' => ['text'],
        ],
        [
            'id'     => 'blank',
            'label'  => 'Blank page',
            'desc'   => 'Start with no blocks',
            'blocks' => [],
        ],
    ];
}

function starters_load(): array {
    if (defined('STARTERS_FILE') && file_exists(STARTERS_FILE)) {
        $raw = json_decode(file_get_contents(STARTERS_FILE), true);
        if (is_array($raw) && !empty($raw)) return $raw;
    }
    return default_starters();
}

function starters_save(array $starters): bool {
    if (!defined('STARTERS_FILE')) return false;
    $dir = dirname(STARTERS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode(array_values($starters), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = STARTERS_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, STARTERS_FILE);
}

function default_block_data(string $type): array {
    return ['type' => $type];
}

function blocks_from_starter(string $starterId): array {
    $starters = starters_load();
    foreach ($starters as $s) {
        if ($s['id'] === $starterId) {
            return array_map('default_block_data', $s['blocks'] ?? []);
        }
    }
    return [];
}
