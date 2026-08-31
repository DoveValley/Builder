<?php
// Step: testimonial_rotate
// Varies which testimonials (and in what order) a page's `testimonials`
// block(s) show, keyed deterministically by template + city id — so 100
// cities on the same template don't all render the identical block. Never
// invents a quote or a name: it only reorders/selects from the block's own
// existing tm_items pool (real testimonials already on file), the same way
// ms_pick_theme_preset() rotates a fixed pool of presets instead of
// generating new ones. Idempotent — same city+template always lands on the
// same rotation, so a regenerate doesn't reshuffle an unchanged page.
// No external API calls, no cost.

function step_testimonial_rotate_meta(): array {
    return [
        'has_cost'    => false,
        'description' => 'Rotate testimonial order/selection per city (same real quotes, no new content)',
    ];
}

function step_testimonial_rotate(array $page, array $city, array $options): array {
    foreach ($page['content_blocks'] as $i => $block) {
        if (($block['type'] ?? '') !== 'testimonials') continue;
        $items = $block['tm_items'] ?? [];
        $n = count($items);
        if ($n < 2) continue;

        $seed   = hexdec(substr(md5(($page['template_id'] ?? '') . '|' . ($city['id'] ?? '')), 0, 8));
        $offset = $seed % $n;
        $rotated = array_merge(array_slice($items, $offset), array_slice($items, 0, $offset));

        // Drop one from the pool when there's enough to spare, so cities differ
        // by SET, not just order.
        $show   = $n > 2 ? $n - 1 : $n;
        $page['content_blocks'][$i]['tm_items'] = array_slice($rotated, 0, $show);
    }
    return $page;
}
