<?php
/**
 * import_pages.php — Scrape a landing page from katypestpros.com and import into site.json
 *
 * Usage:
 *   php import_pages.php https://katypestpros.com/some-page/
 *   php import_pages.php --dry-run https://katypestpros.com/some-page/
 */

define('DATA_FILE', __DIR__ . '/data/site.json');

function fetch_html(string $url): string {
    $ctx = stream_context_create(['http' => [
        'timeout'         => 20,
        'follow_location' => true,
        'header'          => "User-Agent: Mozilla/5.0 (compatible; import-bot/1.0)\r\n",
    ]]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

function make_xpath(string $html): DOMXPath {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

function xval(DOMXPath $x, string $query, ?DOMNode $ctx = null): string {
    return trim($ctx ? $x->evaluate("string($query)", $ctx) : $x->evaluate("string($query)"));
}

function inner_html(DOMNode $node): string {
    $dom = $node->ownerDocument;
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return $out;
}

function clean_text(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $text));
}

function load_site_json(): array {
    if (!file_exists(DATA_FILE)) return [];
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}

function save_site_json(array $data): void {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ─── scraper ──────────────────────────────────────────────────────────────────

function scrape_page(string $url): ?array {
    $html = fetch_html($url);
    if (!$html) { echo "  ERROR: could not fetch $url\n"; return null; }

    $x    = make_xpath($html);
    $slug = trim(parse_url($url, PHP_URL_PATH), '/');
    $blocks = [];

    // Helper: make relative image paths absolute using the live site base URL
    $base = rtrim(parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST), '/');
    $abs = function(string $src) use ($base): string {
        if (!$src) return '';
        if (str_starts_with($src, 'http') || str_starts_with($src, '//')) return $src;
        return $base . '/' . ltrim($src, '/');
    };

    // 1. Hero (inner_banner section)
    $hero_sec = $x->query('//section[contains(@class,"inner_banner")]')->item(0);
    if ($hero_sec) {
        $h1       = xval($x, './/h1', $hero_sec);
        $p_nodes  = $x->query('.//p[not(ancestor::*[contains(@class,"bread")])]', $hero_sec);
        $sub      = $p_nodes->length ? clean_text(xval($x, '.', $p_nodes->item(0))) : '';
        $img_node = $x->query('.//img', $hero_sec)->item(0);
        $img_src  = $img_node ? $img_node->getAttribute('src') : '';
        $img_alt  = $img_node ? $img_node->getAttribute('alt') : '';

        if ($h1) {
            $blocks[] = [
                'type'         => 'hero_split',
                'hs_heading'   => $h1,
                'hs_subtext'   => $sub,
                'hs_btn_text'  => 'Call Us',
                'hs_btn_url'   => 'tel:+12812150160',
                'hs_photo'     => $abs($img_src),
                'hs_photo_alt' => $img_alt,
                'hs_caption1'  => $h1,
                'hs_caption2'  => 'Katy, TX',
                'hs_bg_color'  => '#f3f6f7',
            ];
        }
    }

    // 2. Feature split (species/services list)
    $fs_sec = $x->query('//div[contains(@class,"rts-problem-solution-area")]')->item(0);
    if ($fs_sec) {
        // Clean spans inside h2 (e.g. <span>Cockroach</span>)
        foreach ($x->query('.//h2/span', $fs_sec) as $sp) {
            $sp->parentNode->replaceChild($sp->ownerDocument->createTextNode($sp->textContent), $sp);
        }
        $fs_h2       = xval($x, 'string(.//h2)', $fs_sec);
        $fs_img_node = $x->query('.//div[contains(@class,"thumbnail")]//img', $fs_sec)->item(0);
        $fs_img_src  = $abs($fs_img_node ? $fs_img_node->getAttribute('src') : '');
        $fs_img_alt  = $fs_img_node ? $fs_img_node->getAttribute('alt') : '';
        $disc_node   = $x->query('.//p[contains(@class,"disc")]', $fs_sec)->item(0);
        $fs_sub      = $disc_node ? clean_text(xval($x, '.', $disc_node)) : '';

        $fs_items = [];
        foreach ($x->query('.//div[contains(@class,"solution-content-inner-one")]//li', $fs_sec) as $li) {
            $strong = $x->query('.//strong', $li)->item(0);
            $head   = $strong ? clean_text($strong->textContent) : '';
            $full   = clean_text($li->textContent);
            $body   = $head ? trim(ltrim(str_replace($head, '', $full), ' –-')) : $full;
            if ($head) $fs_items[] = ['icon' => '', 'alt' => '', 'heading' => rtrim($head, ' –-'), 'text' => $body];
        }
        // Trailing paragraph after the list
        $fs_trailing_node = $x->query('.//div[contains(@class,"solution-content-inner-one")]//p[not(contains(@class,"disc"))]', $fs_sec)->item(0);
        $fs_trailing      = $fs_trailing_node ? clean_text($fs_trailing_node->textContent) : '';
        // Phone CTA
        $fs_phone_node    = $x->query('.//div[contains(@class,"call-info")]//a', $fs_sec)->item(0);
        $fs_phone         = $fs_phone_node ? clean_text($fs_phone_node->textContent) : '';

        if ($fs_h2) {
            $blocks[] = [
                'type'           => 'feature_split',
                'fs_heading'     => $fs_h2,
                'fs_subtext'     => $fs_sub,
                'fs_trailing'    => $fs_trailing,
                'fs_image_side'  => 'left',
                'fs_photo'       => $fs_img_src,
                'fs_photo_alt'   => $fs_img_alt,
                'fs_star_text'   => '',
                'fs_bg_color'    => '#f3f6f7',
                'fs_accent'      => '#fd783b',
                'fs_items'       => $fs_items,
                'fs_phone'       => $fs_phone ?: '(281) 215-0160',
                'fs_phone_label' => 'Call Us 24/7',
            ];
        }
    }

    // 3. Blog text content — iterate DOM nodes, split on h2 and cta-section divs
    $text_holder = $x->query(
        '//section[contains(@class,"blog-single-area")]//div[contains(@class,"col-xl-8")]//div[contains(@class,"text-holder")]'
    )->item(0) ?? $x->query(
        '//section[contains(@class,"blog-single-area")]//div[contains(@class,"text-holder")]'
    )->item(0);

    if ($text_holder) {
        $cur_heading  = '';
        $cur_body_html = '';
        $cur_img_src  = '';
        $cur_img_alt  = '';

        $flush = function() use (&$blocks, &$cur_heading, &$cur_body_html, &$cur_img_src, &$cur_img_alt) {
            if (!$cur_heading) return;
            $body = trim($cur_body_html);
            if ($cur_img_src) {
                // image_right: text goes through text_to_html as HTML, so wrap heading in <h2>
                $blocks[] = [
                    'type'           => 'image_right',
                    'text'           => "<h2>$cur_heading</h2>\n$body",
                    'photo'          => $cur_img_src,
                    'photo_alt'      => $cur_img_alt,
                    'photo_ratio'    => 'landscape',
                    'photo_position' => 'center',
                ];
            } else {
                // text block: first line becomes the heading via array_shift, body is HTML
                $blocks[] = [
                    'type'          => 'text',
                    'heading_level' => 'h2',
                    'text'          => "$cur_heading\n\n$body",
                    'photo'         => '',
                    'photo_alt'     => '',
                    'photo_ratio'   => 'landscape',
                    'photo_position'=> 'center',
                ];
            }
            $cur_heading = $cur_body_html = $cur_img_src = $cur_img_alt = '';
        };

        foreach ($text_holder->childNodes as $node) {
            $nodeName = $node->nodeName;

            if ($nodeName === 'h2') {
                $flush();
                // Strip span tags (e.g. icon spans) from heading text
                foreach ($x->query('.//span', $node) as $sp) {
                    $sp->parentNode->replaceChild(
                        $sp->ownerDocument->createTextNode($sp->textContent), $sp
                    );
                }
                $cur_heading = clean_text($node->textContent);

            } elseif ($nodeName === 'div' && str_contains($node->getAttribute('class'), 'cta-section')) {
                $flush();
                $cta_h2  = $x->query('.//h2', $node)->item(0);
                $cta_p   = $x->query('.//p', $node)->item(0);
                $blocks[] = [
                    'type'         => 'cta_banner',
                    'cb_text'      => $cta_h2 ? clean_text($cta_h2->textContent) : '',
                    'cb_subtext'   => $cta_p  ? clean_text($cta_p->textContent)  : '',
                    'cb_btn_text'  => 'Call Now',
                    'cb_btn_url'   => 'tel:+12812150160',
                    'cb_bg'        => 'accent',
                    'cb_bg_custom' => '#fd783b',
                ];

            } elseif ($nodeName === 'p') {
                // Standalone image paragraph → capture as section image
                $img = $x->query('.//img', $node)->item(0);
                $p_inner = inner_html($node);
                $p_text_only = trim(preg_replace('/<img[^>]*\/?>/i', '', $p_inner));
                if ($img && !$cur_img_src && $p_text_only === '') {
                    $cur_img_src = $abs($img->getAttribute('src'));
                    $cur_img_alt = $img->getAttribute('alt');
                } else {
                    $cur_body_html .= $node->ownerDocument->saveHTML($node);
                }

            } elseif ($nodeName === '#text') {
                // skip whitespace-only text nodes

            } else {
                // ul, ol, table, etc.
                $cur_body_html .= $node->ownerDocument->saveHTML($node);
            }
        }
        $flush();
    }

    // 4. Katy Map / Info block (sidebar)
    $map_embed_node = $x->query('//div[contains(@class,"sidebar-wrapper")]//iframe')->item(0);
    $map_embed      = $map_embed_node ? $map_embed_node->ownerDocument->saveHTML($map_embed_node) : '';
    $wiki_p_node    = $x->query('//div[contains(@class,"wiki_new_class")]//p')->item(0);
    $wiki_text      = $wiki_p_node ? clean_text($wiki_p_node->textContent) : '';
    $wiki_img_node  = $x->query('//div[contains(@class,"wiki_new_class")]//img')->item(0);
    $wiki_img       = $wiki_img_node ? $abs($wiki_img_node->getAttribute('src')) : '';
    $wiki_img_alt   = $wiki_img_node ? $wiki_img_node->getAttribute('alt') : 'Katy, TX';

    if ($map_embed || $wiki_text) {
        $blocks[] = [
            'type'                 => 'map_info',
            'mi_map_heading'       => 'Katy Map',
            'mi_map_embed'         => $map_embed,
            'mi_info_heading'      => 'Katy Information',
            'mi_info_text'         => $wiki_text,
            'mi_info_photo'        => $wiki_img,
            'mi_info_alt'          => $wiki_img_alt,
            'mi_head_color'        => 'custom',
            'mi_head_color_custom' => '#27477d',
        ];
    }

    // 5. FAQ
    $faq_heading = '';
    foreach ($x->query('//h2') as $h2node) {
        $t = clean_text($h2node->textContent);
        if (str_contains($t, 'FAQ') || str_contains($t, 'Frequently')) { $faq_heading = $t; break; }
    }
    $faq_items = [];
    foreach ($x->query('//div[contains(@class,"services_page_faq")]//div[contains(@class,"accordion-item")]') as $acc) {
        $q_node = $x->query('.//h3[contains(@class,"Faq_Question")]', $acc)->item(0);
        $a_node = $x->query('.//div[contains(@class,"Faq_Answer")]', $acc)->item(0);
        if (!$q_node || !$a_node) continue;
        foreach ($x->query('.//span', $q_node) as $sp) $sp->parentNode->removeChild($sp);
        $q = clean_text($q_node->textContent);
        $a = trim(preg_replace('/\s+/', ' ', $a_node->textContent));
        if ($q) $faq_items[] = ['question' => $q, 'answer' => $a];
    }
    if (!empty($faq_items)) {
        $blocks[] = [
            'type'                => 'faq_two_col',
            'fq_heading'          => $faq_heading ?: 'Frequently Asked Questions',
            'fq_items'            => $faq_items,
            'fq_bg_color'         => '#ffffff',
            'fq_head_color'       => 'custom',
            'fq_head_color_custom'=> '#27477d',
            'fq_icon_bg'          => 'accent',
            'fq_icon_bg_custom'   => '#fd783b',
            'fq_item_bg'          => '#ffffff',
        ];
    }

    // 5. Service links grid
    $service_links = [];
    foreach ($x->query('//section[contains(@class,"main-all-services")]//ul/li/a') as $a_node) {
        $label = clean_text($a_node->textContent);
        $href  = $a_node->getAttribute('href');
        if ($label && $href) $service_links[] = ['label' => $label, 'url' => $href];
    }
    // Split CTA block (orange left + dark blue right with phone)
    $split_sec = $x->query('//section[contains(@class,"content-block")]')->item(0);
    if ($split_sec) {
        $sc_left_h  = clean_text(xval($x, 'string(.//div[contains(@class,"content-list-sec")]//div[contains(@class,"h4")])', $split_sec));
        $sc_left_p  = clean_text(xval($x, 'string(.//div[contains(@class,"content-list-sec")]//p)', $split_sec));
        $sc_label   = clean_text(xval($x, 'string(.//div[contains(@class,"content-text-area-sec")]//p)', $split_sec));
        $sc_phone   = clean_text(xval($x, 'string(.//div[contains(@class,"content-text-area-sec")]//a)', $split_sec));
        if ($sc_left_h || $sc_phone) {
            $blocks[] = [
                'type'              => 'split_cta',
                'sc_left_heading'   => $sc_left_h,
                'sc_left_text'      => $sc_left_p,
                'sc_left_bg'        => 'accent',
                'sc_left_bg_custom' => '#fd783b',
                'sc_right_label'    => $sc_label,
                'sc_right_phone'    => $sc_phone,
                'sc_right_phone_url'=> 'tel:+12812150160',
                'sc_right_bg'       => 'header',
                'sc_right_bg_custom'=> '#120575',
            ];
        }
    }

    // Orange banner heading above the links grid (services-top-heading)
    $banner_heading = xval($x, 'string(//section[contains(@class,"main-all-services")]//div[contains(@class,"services-top-heading")]//h2)');
    if ($banner_heading) {
        $blocks[] = [
            'type'         => 'cta_banner',
            'cb_text'      => $banner_heading,
            'cb_subtext'   => '',
            'cb_btn_text'  => '',
            'cb_btn_url'   => '',
            'cb_bg'        => 'accent',
            'cb_bg_custom' => '#fd783b',
        ];
    }

    // Use the h3 inside the bg-image section, not the h2 above it
    $links_heading = xval($x, 'string(//section[contains(@class,"main-all-services")]//h3)');
    $links_subtext = xval($x, 'string(//section[contains(@class,"main-all-services")]//p)');
    if (!empty($service_links)) {
        $blocks[] = [
            'type'             => 'links_grid',
            'anchor'           => 'pest_services',
            'lg_heading'       => $links_heading ?: 'Our Pest Control Services',
            'lg_subtext'       => $links_subtext ?: 'We provide fast, reliable, and affordable pest control services across Katy, TX.',
            'lg_sublabel'      => '',
            'lg_photo'         => 'uploads/media/pest-katy_home_a1b2c3.webp',
            'lg_photo_alt'     => 'Pest control services Katy TX',
            'lg_cols'          => 5,
            'lg_overlay'       => '0.2',
            'lg_links'         => $service_links,
            'lg_style'         => 'dark',
            'lg_bg_color'      => '#ffffff',
            'lg_accent'        => 'accent',
            'lg_accent_custom' => '#fd783b',
        ];
    }

    // 6. Brand values wide_banner (section.testi-wrap1)
    $testi_sec = $x->query('//section[contains(@class,"testi-wrap1")]')->item(0);
    if ($testi_sec) {
        $wb_heading_node  = $x->query('.//h2[contains(@class,"social-bars-title")]', $testi_sec)->item(0);
        $wb_heading       = $wb_heading_node ? clean_text($wb_heading_node->textContent) : '';
        $wb_subtext_node  = $x->query('.//div[contains(@class,"social-bars")]//p', $testi_sec)->item(0);
        $wb_subtext       = $wb_subtext_node ? clean_text($wb_subtext_node->textContent) : '';
        $wb_shape         = $x->query('.//div[contains(@class,"testi-shape2")]', $testi_sec)->item(0);
        $wb_bg_src        = $wb_shape ? $wb_shape->getAttribute('data-bg-src') : '';
        $wb_photo         = $wb_bg_src ? $abs($wb_bg_src) : '';
        if ($wb_heading) {
            $blocks[] = [
                'type'               => 'wide_banner',
                'wb_badge'           => '',
                'wb_heading'         => $wb_heading,
                'wb_subtext'         => $wb_subtext,
                'wb_btn_text'        => '',
                'wb_btn_url'         => '',
                'wb_photo'           => $wb_photo,
                'wb_photo_alt'       => 'Katy Pest Control Values',
                'wb_overlay'         => '0.45',
                'wb_badge_bg'        => 'accent',
                'wb_badge_bg_custom' => '#fd783b',
                'wb_btn_style'       => 'filled',
            ];
        }
    }

    // 7. Service cards (section.certificates-area)
    $cert_sec = $x->query('//section[contains(@class,"certificates-area")]')->item(0);
    if ($cert_sec) {
        $sc_badge_node   = $x->query('.//div[contains(@class,"subtitle")]', $cert_sec)->item(0);
        $sc_badge        = $sc_badge_node ? clean_text($sc_badge_node->textContent) : '';
        $sc_heading_node = $x->query('.//h2', $cert_sec)->item(0);
        $sc_heading      = $sc_heading_node ? clean_text($sc_heading_node->textContent) : '';
        $sc_items        = [];
        foreach ($x->query('.//div[contains(@class,"single-certificates-box")]', $cert_sec) as $card) {
            $a_node = $x->query('.//h3/a', $card)->item(0);
            if (!$a_node) continue;
            $sc_items[] = [
                'icon'       => '',
                'icon_emoji' => '🛡️',
                'alt'        => '',
                'heading'    => clean_text($a_node->textContent),
                'text'       => '',
            ];
        }
        if (!empty($sc_items)) {
            $blocks[] = [
                'type'                => 'service_cards',
                'sc_badge'            => $sc_badge,
                'sc_heading'          => $sc_heading,
                'sc_cols'             => 4,
                'sc_items'            => $sc_items,
                'sc_badge_bg'         => 'accent',
                'sc_badge_bg_custom'  => '#fd783b',
                'sc_head_color'       => 'custom',
                'sc_head_color_custom'=> '#27477d',
                'sc_icon_bg'          => '#fef0e7',
            ];
        }
    }

    // SEO
    $page_title = xval($x, '//title');
    $meta_desc  = xval($x, 'string(//meta[@name="description"]/@content)');

    return [
        'title'          => $page_title ?: $slug,
        'slug'           => $slug,
        'content_blocks' => $blocks,
        'seo'            => [
            'meta_description' => $meta_desc,
            'meta_keywords'    => '',
            'og_title'         => $page_title,
            'og_description'   => $meta_desc,
            'og_image'         => '',
            'schema'           => '',
        ],
    ];
}

// ─── main ─────────────────────────────────────────────────────────────────────

$dry_run = in_array('--dry-run', $argv);
$url     = null;
foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if (str_starts_with($arg, '--')) continue;
    if (filter_var($arg, FILTER_VALIDATE_URL)) { $url = $arg; break; }
}

if (!$url) {
    echo "Usage: php import_pages.php [--dry-run] https://katypestpros.com/page-slug/\n";
    exit(1);
}

echo ($dry_run ? "[DRY RUN] " : "") . "Fetching: $url\n";

$page = scrape_page($url);
if (!$page) exit(1);

$n = count($page['content_blocks']);
echo "  slug   : {$page['slug']}\n";
echo "  title  : {$page['title']}\n";
echo "  blocks : $n\n";
foreach ($page['content_blocks'] as $b) {
    echo "    - {$b['type']}\n";
}

if ($dry_run) {
    echo "\n[dry-run] Nothing saved.\n";
    exit(0);
}

$data = load_site_json();
if (!isset($data['pages'])) $data['pages'] = [];

// Check for existing slug
$existing_pid = null;
foreach ($data['pages'] as $pid => $p) {
    if (($p['slug'] ?? '') === $page['slug']) { $existing_pid = $pid; break; }
}

if ($existing_pid) {
    $data['pages'][$existing_pid] = $page;
    echo "\nUpdated existing page (slug: {$page['slug']})\n";
} else {
    $data['pages'][uniqid('p', true)] = $page;
    echo "\nAdded new page (slug: {$page['slug']})\n";
}

save_site_json($data);
echo "Saved to " . DATA_FILE . "\n";
