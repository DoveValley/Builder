<?php
// Schedule plugin — course schedule management + shortcode rendering.

register_plugin(
    'schedule',
    'Course Schedule',
    'Manage live and on-demand course dates, prices, and availability. Embed in any page with [course_schedule] or [course_card] shortcodes.',
    '&#128197;',
    __DIR__
);

// Inject schedule CSS in <head> when this plugin is active.
// Both schedule.css and card.css are loaded together since card widget
// can appear on the same page as the table widget.
add_hook('head_styles', function(string $pfx): void {
    echo '<link rel="stylesheet" href="' . h($pfx) . 'assets/css/schedule.css">' . "\n";
    echo '<link rel="stylesheet" href="' . h($pfx) . 'assets/css/card.css">' . "\n";
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
