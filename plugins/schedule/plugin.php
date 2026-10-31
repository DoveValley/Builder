<?php
// Schedule plugin — course schedule management + shortcode rendering.

register_plugin(
    'schedule',
    'Course Schedule',
    'Manage live and on-demand course dates, prices, and availability. Embed in any page with [course_schedule] or [course_card] shortcodes.',
    '&#128197;',
    __DIR__
);

// Inject schedule CSS in <head> — but only on pages that actually use a course
// shortcode. The _page_has_course_sc flag is set by site-template.php before
// this hook runs. Inlined as a <style> block rather than two <link>s: both
// files combined are ~11KB raw (a few KB minified), well under the size where
// the earlier style.css inlining experiment (63KB) measurably regressed LCP —
// see that comment in site-template.php. At this size, inlining removes two
// render-blocking network round-trips entirely instead of just softening them
// (PSI flagged these two tiny files costing ~450ms each under slow-4G
// simulation — almost all round-trip latency, not transfer time, which
// inlining eliminates outright). Both schedule.css and card.css load together
// since the card widget can appear on the same page as the table widget.
add_hook('head_styles', function(string $pfx): void {
    if (empty($GLOBALS['_page_has_course_sc'])) return;
    $cssBase = dirname(__DIR__, 2) . '/assets/css/';
    $css = (string) @file_get_contents($cssBase . 'schedule.css') . (string) @file_get_contents($cssBase . 'card.css');
    if ($css === '') return;
    echo '<style>' . css_minify($css) . '</style>' . "\n";
});

// Inject inline course data + JS before </body>, but only when shortcodes
// were actually used on the page (course_shortcode_inline_script returns ''
// when no [course_schedule] or [course_card] was found).
add_hook('body_scripts', function($data, string $pfx): void {
    $script = course_shortcode_inline_script();
    if ($script === '') return;
    echo $script . "\n";
    echo '<script src="' . h($pfx) . 'assets/js/schedule.js"></script>' . "\n";
    echo '<script src="' . h($pfx) . 'assets/js/card.js"></script>' . "\n";
});
