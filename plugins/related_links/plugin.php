<?php
/**
 * Related Links plugin — resolves a curated candidate list into real, existing
 * internal links for the `related_links` block (includes/blocks.php).
 *
 * The problem this solves: Page Pool means every domain ships a different subset
 * of the master's pages, so a hand-typed link on a template can 404 on any domain
 * that happened not to build the target page. This plugin never guesses — it is
 * handed MORE candidates than will be shown (editorial slack for Page Pool luck),
 * checks each one against ms_page_slug_exists() (includes/helpers.php — the same
 * per-domain page-existence check plugins/services_links/plugin.php already uses,
 * not a second copy of that logic), and returns only the ones that are real, in
 * the order given, capped at the requested count.
 *
 * Deliberately isolated, like plugins/services_links and
 * plugins/appliance_services_menu: the block's render case in includes/blocks.php
 * calls this softly (function_exists() guard), so a site that never adds a
 * related_links block is completely unaffected by this file's presence, and a
 * problem here can never take another block type down with it.
 */

register_plugin(
    'related_links',
    'Related Links',
    'Resolves a curated list of related-page candidates into real internal links for the "Related Links" block — every link is checked against this domain\'s actual built pages before it renders, so it can never point at a page Page Pool did not build.',
    '&#128279;',
    __DIR__
);

/**
 * Resolve $candidates into up to $max [text, url] pairs that actually exist on
 * this domain, in the order given.
 *
 * @param array $candidates Each item: ['url' => '/some-slug-{city_slug}', 'text' => 'anchor text'].
 *                           {city_slug} (and any other shortcode) resolves per-domain here.
 * @param int   $max         Stop once this many real links are found (0 = no limit).
 * @return array<int, array{0:string,1:string}> [text, resolved url] pairs — real pages only.
 */
function related_links_resolve(array $candidates, int $max = 3): array {
    $links = [];
    foreach ($candidates as $c) {
        if (!is_array($c)) continue;
        $text = trim((string) ($c['text'] ?? ''));
        $url  = trim((string) ($c['url']  ?? ''));
        if ($text === '' || $url === '') continue;

        $resolvedUrl = resolve_shortcodes($url);
        if (isset($resolvedUrl[0]) && $resolvedUrl[0] === '/') {
            $slug = trim((string) parse_url($resolvedUrl, PHP_URL_PATH), '/');
            // ms_page_slug_exists() only knows flat top-level page slugs, so a nested-path
            // candidate (e.g. blog/some-post) naturally never matches and falls through to
            // skip below — no special-case exemption for it needed or wanted.
            if ($slug !== '' && !ms_page_slug_exists($slug)) {
                continue; // this candidate was not built for this domain — skip, never guess
            }
        }

        $links[] = [resolve_shortcodes($text), $resolvedUrl];
        if ($max > 0 && count($links) >= $max) break;
    }
    return $links;
}
