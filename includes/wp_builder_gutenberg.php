<?php
/**
 * includes/wp_builder_gutenberg.php — low-level Gutenberg-markup helpers.
 * Shared by every WP Builder profile's block-render functions. Not a block
 * type itself — just the plumbing for emitting valid core-block HTML so pages
 * stay editable in the normal block editor afterward.
 */

function wpb_heading(string $text, int $level = 2, string $className = ''): string
{
    $cls = $className ? ' ' . h($className) : '';
    $attrs = json_encode(array_filter(['level' => $level, 'className' => $className ?: null]));
    return "<!-- wp:heading {$attrs} -->\n<h{$level} class=\"wp-block-heading{$cls}\">" . h($text) . "</h{$level}>\n<!-- /wp:heading -->\n\n";
}

function wpb_paragraph(string $text, string $className = ''): string
{
    $cls = $className ? ' class="' . h($className) . '"' : '';
    $attrs = $className ? '{"className":"' . h($className) . '"}' : '';
    return "<!-- wp:paragraph {$attrs} -->\n<p{$cls}>" . h($text) . "</p>\n<!-- /wp:paragraph -->\n\n";
}

function wpb_image(string $url, string $alt, string $className = ''): string
{
    if ($url === '') return '';
    $cls = $className ? ' ' . h($className) : '';
    $attrs = $className ? '{"className":"' . h($className) . '"}' : '';
    return "<!-- wp:image {$attrs} -->\n<figure class=\"wp-block-image{$cls}\"><img src=\"" . h($url) . '" alt="' . h($alt) . "\"/></figure>\n<!-- /wp:image -->\n\n";
}

function wpb_button(string $text, string $url, string $color): string
{
    return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"' . h($color) . '"}}} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . h($url) . '" style="background-color:' . h($color) . '">'
        . h($text) . "</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n";
}

function wpb_group_open(string $className): string
{
    return '<!-- wp:group {"className":"' . h($className) . '"} --><div class="wp-block-group ' . h($className) . "\">\n";
}

function wpb_group_close(): string
{
    return "</div>\n<!-- /wp:group -->\n\n";
}

/** Wraps bespoke markup (a full custom-designed section) in a core wp:html block — a plain WordPress core block, not a page-builder plugin, so bespoke profiles can still ship rich layout without any extra dependency. */
function wpb_html(string $html): string
{
    return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";
}
