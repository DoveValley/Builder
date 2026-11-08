<?php
// Step: sibling_links
// Every city has 4 landing pages (PMP Certification, PMP Bootcamp, CAPM
// Certification, PMI-CPMAI/AI Training) built from 4 separate templates, but none
// of them link to each other — this step fills a dedicated links_grid block
// (added to each template, tagged `_sibling_links: true` so this step always
// finds the right block regardless of position) with links to this same city's
// other 3 pages. Deterministic, no AI/API calls, no cost — purely computed from
// the other templates' own title/slug_pattern, the same way _gen_resolve_slug()
// already resolves this page's own slug.

function step_sibling_links_meta(): array {
    return [
        'has_cost'    => false,
        'description' => 'Link to this city\'s other 3 certification pages (no new content, same city only)',
    ];
}

function step_sibling_links(array $page, array $city, array $options): array {
    static $templatesCache = null;
    if ($templatesCache === null) {
        $templatesCache = [];
        if (defined('TEMPLATES_FILE') && file_exists(TEMPLATES_FILE)) {
            $raw = json_decode(file_get_contents(TEMPLATES_FILE), true);
            $templatesCache = is_array($raw) ? array_values($raw) : [];
        }
    }

    $cityName = $city['city'] ?? '';
    $thisTplId = $page['template_id'] ?? '';

    $links = [];
    foreach ($templatesCache as $tpl) {
        if (($tpl['id'] ?? '') === $thisTplId) continue;
        $title = str_replace('{city}', $cityName, $tpl['title'] ?? '');
        $slug  = _gen_resolve_slug($tpl['slug_pattern'] ?? '', $city);
        if ($title === '' || $slug === '') continue;
        $links[] = ['label' => $title, 'url' => '/' . $slug . '/'];
    }

    foreach ($page['content_blocks'] as $i => $block) {
        if (empty($block['_sibling_links'])) continue;
        $page['content_blocks'][$i]['lg_heading'] = $cityName !== ''
            ? "Other PM Certifications in {$cityName}"
            : 'Other PM Certifications';
        $page['content_blocks'][$i]['lg_links'] = $links;
    }

    return $page;
}
