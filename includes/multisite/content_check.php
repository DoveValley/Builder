<?php
/**
 * Site Final Check — a registry of fast, local, file/text-only checks run against a
 * multisite master's own template pages (sites/{master}/data/pages/*.json + site.json).
 *
 * ONE LIST drives everything: the ⓘ popup's numbered description (admin/rollout.php reads
 * cc_registry() to print it, never hand-typed), the actual run (cc_run() calls each
 * registry entry's fn), and the report (same registry, same order, now with results). This
 * is deliberate — a hand-maintained numbered list next to separately-coded logic is exactly
 * the kind of thing that quietly drifts apart over time. To add or remove a check, add or
 * remove ONE entry here and its one function. Nothing else needs to change.
 *
 * Deliberately synchronous — every check here is a local file read or a string comparison,
 * no network calls, so even pest-template's 1100+ pages check in a couple of seconds. This
 * is NOT the home for anything that needs an AI vision call or an external API (PageSpeed
 * Insights, Rich Results Test) — those are slow and cost real money per check, and belong in
 * their own job-queued tool later, not bolted onto this one.
 *
 * seo_gate.php's existing 5 checks are NOT wrapped here on purpose: ms_seo_gate() requires
 * already-built HTML output ("no built output to check" otherwise), which breaks the
 * "always fast, always runnable against raw template data" contract every check here keeps.
 */

/** The one list. Add/remove a check by adding/removing one entry (+ its function below). */
function cc_registry(): array {
    return [
        ['id' => 'photo_fields', 'label' => 'Every photo field with a real (non-token) value points at a file that actually exists', 'fn' => 'cc_check_photo_fields'],
        ['id' => 'favicon',      'label' => 'A favicon is set, and the file it points at actually exists', 'fn' => 'cc_check_favicon'],
        ['id' => 'title_length', 'label' => 'Title tags are not too long for Google to show in full (checked against the raw template text — a real city/business name can add a few characters on top)', 'fn' => 'cc_check_title_length'],
        ['id' => 'meta_length',  'label' => 'Meta descriptions are not too long for Google to show in full (same raw-template caveat as titles)', 'fn' => 'cc_check_meta_length'],
        ['id' => 'alt_keyword',  'label' => "Every real photo has alt text, and it relates to that page's own primary keyword", 'fn' => 'cc_check_alt_keyword'],
        ['id' => 'site_vars',    'label' => 'Business phone/email/name are not an obvious placeholder (e.g. a 555 exchange, "example.com", "Your Business")', 'fn' => 'cc_check_site_vars'],
        ['id' => 'dupe_font',    'label' => "No two entries in this master's font pool use the exact same font-family (a real past bug — cost mold/appliance 5–12 mobile PageSpeed points)", 'fn' => 'cc_check_duplicate_font'],
        ['id' => 'cannibalize',  'label' => 'No two pages share the exact same primary keyword (splits ranking signal between them instead of concentrating it on one page)', 'fn' => 'cc_check_keyword_cannibalization'],
        ['id' => 'orphan_pages', 'label' => 'Every page is linked to from at least one other page\'s Related Links block (not just reachable by guessing the URL)', 'fn' => 'cc_check_orphan_pages'],
        ['id' => 'og_title_length', 'label' => 'Social-share title (og:title, also used for Twitter) is not too long — Facebook/Twitter truncate it same as Google does a title tag', 'fn' => 'cc_check_og_title_length'],
        ['id' => 'og_desc_length',  'label' => 'Social-share description (og:description, also used for Twitter) is not too long', 'fn' => 'cc_check_og_description_length'],
    ];
}

/** Run every check in the registry, in order. Returns the registry entries + their results. */
function cc_run(string $masterId): array {
    $results = [];
    foreach (cc_registry() as $i => $check) {
        $fn = $check['fn'];
        $r  = $fn($masterId);
        $results[] = [
            'n'              => $i + 1,
            'id'             => $check['id'],
            'label'          => $check['label'],
            'ok'             => $r['ok'],
            'count'          => $r['count'],
            'issues'         => $r['issues'],
            'recommendation' => $r['recommendation'],
        ];
    }
    return $results;
}

/** sites/{master}/uploads/ base — computed directly, not via UPLOAD_DIR, which tracks
 *  whichever site is active in the admin SESSION, not necessarily this batch's master. */
function cc_master_base(string $masterId): string {
    return BASE_DIR . '/sites/' . $masterId;
}

/** Does a stored "...uploads/..." path resolve to a real file under this specific master? */
function cc_file_exists(string $masterBase, string $path): bool {
    if ($path === '') return false;
    $pos = strpos($path, 'uploads/');
    if ($pos === false) return false;
    return is_file($masterBase . '/uploads/' . substr($path, $pos + strlen('uploads/')));
}

/** The whitelist a photo value could live under — same field names Pic Drop manages
 *  (picdrop_fields(), includes/picdrop.php) plus og_image/featured_image, which are
 *  deliberately NOT Pic Drop slots (SEO tab / blog post, see picdrop.php's own docblock)
 *  but are exactly the same kind of field for this purpose. */
const CC_PHOTO_FIELD_KEYS = [
    'hs_photo', 'hs_bg_photo', 'it_photo', 'fs_photo', 'hg_photo', 'wb_photo', 'if_photo',
    'lg_photo', 'mi_info_photo', 'mi_photo', 'photo', 'bg_photo', 'image',
    'og_image', 'featured_image',
];

/** Recursively walk a decoded page/site JSON tree, flagging any photo-field value that's a
 *  stored path pointing at a file that doesn't exist. Recursion alone handles repeater rows
 *  (e.g. a gallery's nth item) without needing to know each block type's own shape.
 *
 *  A value containing "{" is deliberately SKIPPED, not flagged — includes/shortcodes.php
 *  documents a whole family of legitimate tokens a photo field can hold at the template
 *  level ({city_image}, {chart_*}, etc.), resolved only at actual build/render time against
 *  real per-city data. A raw template will ALWAYS have these in photo fields; that's the
 *  correct, intended state, not a bug. (A token that fails to resolve at build time is a
 *  real bug, but catching that needs built HTML output, same reason seo_gate isn't wrapped
 *  here — see this file's top docblock.) */
function cc_walk_photo_fields($node, string $masterBase, string $pageLabel, array &$issues): void {
    if (!is_array($node)) return;
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array($k, CC_PHOTO_FIELD_KEYS, true) && is_string($v) && $v !== '' && !str_contains($v, '{')) {
            if (!cc_file_exists($masterBase, $v)) {
                $issues[] = ['page' => $pageLabel, 'field' => $k, 'detail' => "file does not exist: {$v}"];
            }
        }
        if (is_array($v)) cc_walk_photo_fields($v, $masterBase, $pageLabel, $issues);
    }
}

function cc_check_photo_fields(string $masterId): array {
    $base = cc_master_base($masterId);
    $issues = [];

    $siteFile = "$base/data/site.json";
    $site = is_file($siteFile) ? json_decode((string) file_get_contents($siteFile), true) : null;
    if (is_array($site)) cc_walk_photo_fields($site, $base, 'Homepage / site-wide', $issues);

    foreach (glob("$base/data/pages/*.json") ?: [] as $f) {
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) continue;
        $label = (string) ($data['slug'] ?? basename($f, '.json'));
        cc_walk_photo_fields($data, $base, $label, $issues);
    }

    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues
            ? 'For og_image specifically: just rebuild — a fixed fallback now picks a real photo automatically. For every other field: the correct photo needs to be re-uploaded in Pic Drop for that slot.'
            : '',
    ];
}

function cc_check_favicon(string $masterId): array {
    $base = cc_master_base($masterId);
    $site = json_decode((string) @file_get_contents("$base/data/site.json"), true) ?: [];
    $fav  = (string) ($site['header']['favicon'] ?? '');

    if ($fav === '') {
        return ['ok' => false, 'count' => 1,
            'issues' => [['page' => 'Homepage / site-wide', 'field' => 'header.favicon', 'detail' => 'no favicon is set at all']],
            'recommendation' => 'Set a favicon in the Header tab, or apply a Theme Preset in Gen-Visual (it generates one automatically).'];
    }
    $exists = cc_file_exists($base, $fav);
    return [
        'ok'             => $exists,
        'count'          => $exists ? 0 : 1,
        'issues'         => $exists ? [] : [['page' => 'Homepage / site-wide', 'field' => 'header.favicon', 'detail' => "file does not exist: {$fav}"]],
        'recommendation' => $exists ? '' : 'Re-apply a Theme Preset in Gen-Visual, or run "Colors, fonts, logo & favicon only" to regenerate it.',
    ];
}

function cc_check_field_length(string $masterId, string $field, int $limit, string $label): array {
    $base = cc_master_base($masterId);
    $issues = [];
    foreach (glob("$base/data/pages/*.json") ?: [] as $f) {
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) continue;
        $val = (string) ($data['seo'][$field] ?? '');
        if ($val === '') continue;
        $len = strlen($val);
        if ($len > $limit) {
            $issues[] = [
                'page'   => (string) ($data['slug'] ?? basename($f, '.json')),
                'field'  => "seo.$field",
                'detail' => "{$len} characters (limit {$limit}, raw template text)",
            ];
        }
    }
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues ? "{$label} is too long on " . count($issues) . ' page(s) — shorten it in that page\'s SEO fields.' : '',
    ];
}

function cc_check_title_length(string $masterId): array {
    return cc_check_field_length($masterId, 'seo_title', 60, 'The title tag');
}

function cc_check_meta_length(string $masterId): array {
    return cc_check_field_length($masterId, 'meta_description', 155, 'The meta description');
}

function cc_check_og_title_length(string $masterId): array {
    // 70, not 60: og:title isn't shown in a Google SERP, so it doesn't share the title
    // tag's exact limit — but Facebook/Twitter/LinkedIn do truncate a social-preview
    // title past roughly this length. Found a real one at 81 chars on pest-template
    // while calibrating this threshold against live data before shipping it.
    return cc_check_field_length($masterId, 'og_title', 70, 'The social-share title (og:title)');
}

function cc_check_og_description_length(string $masterId): array {
    return cc_check_field_length($masterId, 'og_description', 200, 'The social-share description (og:description)');
}

/** Recursively visit every associative (non-list) array in the tree — one page, one
 *  content block, one repeater row (a gallery/tab/card item), all the same way — so a
 *  photo field and its sibling alt field (both live in the same dict) can be read together
 *  without needing to know each block type's own shape. */
function cc_walk_dicts($node, callable $cb): void {
    if (!is_array($node)) return;
    if (!array_is_list($node)) $cb($node);
    foreach ($node as $v) {
        if (is_array($v)) cc_walk_dicts($v, $cb);
    }
}

/** Lowercased, stopword-free words from a phrase — the overlap vocabulary for the
 *  alt-text/keyword check. Words under 3 characters are dropped (too likely to coincidentally
 *  match something unrelated: "in", "to", "it"). */
function cc_significant_words(string $phrase): array {
    static $stop = ['the','a','an','and','or','in','on','for','of','to','near','me','your','is','are'];
    $words = preg_split('/[^a-z0-9]+/', strtolower($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($words, fn($w) => strlen($w) >= 3 && !in_array($w, $stop, true)));
}

/** Photo field -> its own alt field, per the Pic Drop whitelist (includes/picdrop.php's
 *  picdrop_fields()) plus featured_image, which isn't a Pic Drop slot but is the same kind
 *  of field for this purpose. Background photos (hs_bg_photo/bg_photo) are deliberately
 *  excluded — Pic Drop itself gives them no alt field, since a CSS background image has
 *  nothing for a screen reader to announce. og_image is excluded too — never rendered as a
 *  visible <img>, see picdrop.php's own docblock. */
const CC_PHOTO_ALT_MAP = [
    'hs_photo' => 'hs_photo_alt', 'it_photo' => 'it_alt', 'fs_photo' => 'fs_photo_alt',
    'hg_photo' => 'hg_photo_alt', 'wb_photo' => 'wb_photo_alt', 'if_photo' => 'if_photo_alt',
    'lg_photo' => 'lg_photo_alt', 'mi_info_photo' => 'mi_info_alt', 'mi_photo' => 'mi_info_alt',
    'photo' => 'photo_alt', 'image' => 'alt', 'featured_image' => 'featured_image_alt',
];

/** Filenames that don't need a topic/keyword match — logos, icons, favicons, etc. Canonical
 *  home for this list: includes/image_cleanup.php's imgclean_is_content_file() defers to it,
 *  so the two tools can't quietly drift on what counts as "decorative." */
function cc_is_decorative_filename(string $filename): bool {
    if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $filename)) return false;
    $base = strtolower($filename);
    foreach (['logo', 'icon', 'favicon', 'badge', 'sprite'] as $ex) {
        if (strpos($base, $ex) !== false) return false;
    }
    return true;
}

/**
 * Scans every real <img> tag in a rendered page for two things: a missing/empty alt
 * attribute, and (when $keyword is known) alt text that doesn't relate to this page's own
 * primary keyword. Decorative images (logo/icon/favicon/badge/sprite) are exempt from both —
 * same list cc_is_decorative_filename() applies everywhere else.
 *
 * The hero image — hero_split's <img class="hs-image">, the ONE hero block type that
 * renders a real <img> rather than a CSS background (plain hero and hero_grid both use
 * background-image, so they have no alt-bearing element at all — the same reason
 * CC_PHOTO_ALT_MAP excludes background-photo fields from the template-mode alt check) — is
 * held to a stricter bar than any other content photo: it's the single strongest place on
 * the page for the keyword to land, so this requires most of the keyword's words (or the
 * whole phrase) rather than just one shared word.
 *
 * Deliberately does NOT check whether a filename matches the keyword, or whether the alt/
 * filename matches what's actually IN the photo — the first was tried for template mode and
 * dropped (see admin/rollout.php's "what runs" modal: AI-generated photos legitimately carry
 * opaque cache-style names on 47-80% of photos in some niches, same filenames here since a
 * build never renames a file); the second needs an actual AI vision call per photo, which is
 * exactly what includes/image_cleanup.php's Image Name Cleanup tool already does — duplicating
 * it here would break this tool's always-free, always-synchronous design.
 */
function cc_html_issue_images(string $html, string $pageLabel, string $keyword): array {
    $words        = cc_significant_words($keyword);
    $keywordLower = strtolower(trim($keyword));
    $issues       = [];
    if (!preg_match_all('~<img\b[^>]*>~i', $html, $tags)) return $issues;
    foreach ($tags[0] as $tag) {
        if (!preg_match('~\bsrc=["\']([^"\']+)["\']~i', $tag, $sm)) continue;
        $filename = basename((string) (parse_url($sm[1], PHP_URL_PATH) ?: $sm[1]));
        if (!cc_is_decorative_filename($filename)) continue;

        $isHero = (bool) preg_match('~\bclass=["\'][^"\']*\bhs-image\b[^"\']*["\']~i', $tag);
        $field  = $isHero ? 'hero image alt' : 'content image alt';

        $hasAlt = (bool) preg_match('~\balt=["\']([^"\']*)["\']~i', $tag, $am);
        $alt    = $hasAlt ? trim(html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5)) : '';
        if ($alt === '') {
            $issues[] = ['page' => $pageLabel, 'field' => $field, 'detail' => "{$filename} has no alt text at all"];
            continue;
        }
        if (!$words) continue; // no keyword known for this page — not this check's problem

        // City Image / Area Map / Data Chart plugin output ({city_image}, {chart_*} —
        // see includes/shortcodes.php) is legitimately about geography or weather, not
        // the page's service, so a keyword mismatch here is correct, not a bug. Verified
        // against all 4 niche masters before shipping: every chart-/service-area-map-/
        // city-scenic- hit in a first real pass was exactly this, zero genuine misses.
        if (preg_match('/^(chart-[a-z0-9_]+|service-area-map|city-scenic)-/i', $filename)) continue;

        $altLower = strtolower($alt);
        $hits = 0;
        foreach ($words as $w) { if (str_contains($altLower, $w)) $hits++; }

        if ($isHero) {
            $needed = max(1, (int) ceil(count($words) / 2));
            if (!str_contains($altLower, $keywordLower) && $hits < $needed) {
                $issues[] = ['page' => $pageLabel, 'field' => $field, 'detail' => "{$filename} alt text \"{$alt}\" doesn't closely match this page's keyword \"{$keyword}\" — the hero is the strongest place on the page for it to land"];
            }
        } elseif ($hits === 0) {
            $issues[] = ['page' => $pageLabel, 'field' => $field, 'detail' => "{$filename} alt text \"{$alt}\" doesn't share a word with this page's keyword"];
        }
    }
    return $issues;
}

function cc_check_alt_keyword(string $masterId): array {
    $base = cc_master_base($masterId);
    $issues = [];
    foreach (glob("$base/data/pages/*.json") ?: [] as $f) {
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) continue;
        $label   = (string) ($data['slug'] ?? basename($f, '.json'));
        $keyword = (string) ($data['seo']['primary_keyword'] ?? '');
        $words   = cc_significant_words($keyword);
        if (!$words) continue; // nothing to compare against — not this check's problem

        cc_walk_dicts($data, function ($dict) use ($words, $label, &$issues) {
            foreach (CC_PHOTO_ALT_MAP as $photoKey => $altKey) {
                $photoVal = $dict[$photoKey] ?? null;
                // Only a REAL, non-token photo — a block that doesn't use this slot, or
                // one still holding a dynamic {token}, has nothing to judge the alt text
                // of (same reasoning as cc_walk_photo_fields).
                if (!is_string($photoVal) || $photoVal === '' || str_contains($photoVal, '{')) continue;

                $altVal = (string) ($dict[$altKey] ?? '');
                if ($altVal === '') {
                    $issues[] = ['page' => $label, 'field' => $altKey, 'detail' => 'no alt text at all on this photo'];
                    continue;
                }
                // {service}/{primary_keyword} resolve to this exact page's own keyword at
                // render time — that's the strongest possible match, not a failure to check
                // for literally. Skip straight to pass without a literal-text comparison.
                if (str_contains($altVal, '{service}') || str_contains($altVal, '{primary_keyword}')) continue;

                $altLower = strtolower($altVal);
                $hit = false;
                foreach ($words as $w) { if (str_contains($altLower, $w)) { $hit = true; break; } }
                if (!$hit) {
                    $issues[] = ['page' => $label, 'field' => $altKey, 'detail' => "alt text \"{$altVal}\" doesn't share a word with this page's keyword"];
                }
            }
        });
    }
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues ? 'Add or rewrite alt text for the listed photo slots so it describes the photo AND relates to the page it\'s on — in Pic Drop or the block editor.' : '',
    ];
}

function cc_check_site_vars(string $masterId): array {
    $base = cc_master_base($masterId);
    $site = json_decode((string) @file_get_contents("$base/data/site.json"), true) ?: [];
    $sv   = $site['site_vars'] ?? [];
    $issues = [];

    $business = (string) ($sv['business'] ?? '');
    foreach (['example', 'test business', 'placeholder', 'lorem ipsum', 'your business', 'sample business', 'demo business', 'acme'] as $bad) {
        if ($business !== '' && str_contains(strtolower($business), $bad)) {
            $issues[] = ['page' => 'Homepage / site-wide', 'field' => 'site_vars.business', 'detail' => "looks like a placeholder: \"{$business}\""];
            break;
        }
    }

    $phone = (string) ($sv['phone'] ?? '');
    // The 555 exchange (NXX-555-XXXX) is reserved for fiction — never a real assigned
    // number — the universal placeholder-phone tell. Checked as the MIDDLE three digits
    // specifically, not "contains 555 anywhere," which would flag plenty of real numbers.
    if ($phone !== '' && preg_match('/\b\d{3}[-. ]?555[-. ]?\d{4}\b/', $phone)) {
        $issues[] = ['page' => 'Homepage / site-wide', 'field' => 'site_vars.phone', 'detail' => "looks like a placeholder (555 exchange): \"{$phone}\""];
    }
    if ($phone !== '' && preg_match('/^(\d)\1{6,}$/', preg_replace('/\D/', '', $phone))) {
        $issues[] = ['page' => 'Homepage / site-wide', 'field' => 'site_vars.phone', 'detail' => "looks like a placeholder (repeated digit): \"{$phone}\""];
    }

    $email = (string) ($sv['email'] ?? '');
    foreach (['example.com', 'test.com', 'yourdomain.com', 'domain.com'] as $bad) {
        if ($email !== '' && str_contains(strtolower($email), $bad)) {
            $issues[] = ['page' => 'Homepage / site-wide', 'field' => 'site_vars.email', 'detail' => "looks like a placeholder: \"{$email}\""];
        }
    }

    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => $issues,
        'recommendation' => $issues ? 'Fix the listed site_vars with this business\'s real phone/email/name — these feed every page on every domain built from this master.' : '',
    ];
}

function cc_check_duplicate_font(string $masterId): array {
    $base = cc_master_base($masterId);
    $file = "$base/multisite/theme_presets.json";
    if (!is_file($file)) return ['ok' => true, 'count' => 0, 'issues' => [], 'recommendation' => ''];
    $data  = json_decode((string) file_get_contents($file), true) ?: [];
    $fonts = $data['fonts'] ?? [];
    $seen  = []; // stack (lowercased) => first font name that used it
    $issues = [];
    foreach ($fonts as $f) {
        $stack = strtolower(trim((string) ($f['stack'] ?? '')));
        $name  = (string) ($f['name'] ?? '(unnamed)');
        if ($stack === '') continue;
        if (isset($seen[$stack])) {
            $issues[] = ['page' => 'Gen-Visual / font pool', 'field' => 'theme_presets.json fonts[]',
                'detail' => "\"{$name}\" and \"{$seen[$stack]}\" both resolve to the exact same font-family: {$stack}"];
        } else {
            $seen[$stack] = $name;
        }
    }
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => $issues,
        'recommendation' => $issues ? 'Two font pool entries render identically — a domain that "rotates" between them isn\'t actually varying. Give one a distinct font-family.' : '',
    ];
}

/**
 * Group every page in this master by its own city_vars.city_slug. For a single-city
 * master (appliance-site, mold-site, water-site — every page targets the one same demo
 * city), this produces exactly one group holding every page. For a MULTI-city master
 * (pest-template: 1158 pages spanning ~100+ different cities, each city's page set
 * destined for a DIFFERENT live domain via Page Pool), this is the difference between a
 * real finding and total noise — pages in different cities never coexist on the same
 * live site, so nothing about how they relate to each other means anything.
 *
 * Found live, 2026-10-24: an earlier version of cc_check_keyword_cannibalization() and
 * cc_check_orphan_pages() compared across the WHOLE master regardless of city. On
 * pest-template that produced "ant pest control is the primary keyword on 102 different
 * pages" (every city's own ant-control page, correctly sharing that keyword — each will
 * only ever appear on its own, separate domain) and 1147 false "orphans" (a page in
 * Albany, OR flagged because nothing in Boerne, TX links to it — they could never link
 * to each other in the first place). Both checks must only ever compare within one
 * city's group, never across.
 *
 * @return array<string, array> city_slug => [page data, ...]
 */
function cc_pages_by_city(string $masterId): array
{
    $groups = [];
    foreach (glob(cc_master_base($masterId) . '/data/pages/*.json') ?: [] as $f) {
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) continue;
        $cs = (string) ($data['city_vars']['city_slug'] ?? '');
        $groups[$cs][] = $data;
    }
    return $groups;
}

function cc_check_keyword_cannibalization(string $masterId): array {
    $issues = [];
    foreach (cc_pages_by_city($masterId) as $cityPages) {
        $byKeyword = []; // normalized keyword => [slug, ...] — WITHIN this one city only
        foreach ($cityPages as $data) {
            $kw = trim((string) ($data['seo']['primary_keyword'] ?? ''));
            if ($kw === '') continue;
            $byKeyword[strtolower($kw)][] = (string) ($data['slug'] ?? '');
        }
        foreach ($byKeyword as $norm => $slugs) {
            if (count($slugs) > 1) {
                $issues[] = ['page' => implode(', ', $slugs), 'field' => 'seo.primary_keyword',
                    'detail' => "\"{$norm}\" is the primary keyword on " . count($slugs) . ' different pages in the same city'];
            }
        }
    }
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues ? 'Give each listed page its own distinct primary keyword, or merge the duplicates into one page — two pages on the same live site competing for the same keyword split the ranking signal that one page targeting it alone would keep.' : '',
    ];
}

/**
 * A page is reachable by typing its exact URL whether or not anything links to it —
 * this checks the DIFFERENT, real thing: does any other page in the SAME city's page
 * set actually link to it via a Related Links block. Scoped to one city group at a time
 * (see cc_pages_by_city()'s docblock for why that's required, not optional) and to
 * Related Links specifically, not every possible link-shaped field on the page (nav/
 * footer/services_links are structural chrome rendered the same on every page, not an
 * editorial "this page is relevant to that one" signal, and services_links' hub grids
 * are auto-derived from slug patterns — see plugins/services_links/plugin.php's own
 * docblock — so there's no hand-kept list there that could drift the way Related Links'
 * curated candidate lists can and do).
 *
 * Verified against real data before shipping: appliance-site's 120 pages (one city) have
 * Related Links candidate lists covering only 89 distinct targets combined — 31 pages
 * never referenced anywhere, confirmed real (not a URL-pattern parsing miss: every
 * candidate URL on the site uses the same `-{city_slug}` token, checked directly).
 */
function cc_check_orphan_pages(string $masterId): array {
    $issues = [];
    foreach (cc_pages_by_city($masterId) as $citySlug => $cityPages) {
        if ($citySlug === '') continue; // nothing to stem against — skip rather than guess

        $stems = [];    // stem => slug, for reporting
        $incoming = []; // stem => true
        foreach ($cityPages as $data) {
            $slug = (string) ($data['slug'] ?? '');
            $stem = preg_replace('/-' . preg_quote($citySlug, '/') . '$/', '', $slug);
            $stems[$stem] = $slug;
            foreach (($data['content_blocks'] ?? []) as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'related_links') continue;
                foreach (($block['rl_items'] ?? []) as $item) {
                    $url = trim((string) ($item['url'] ?? ''), '/');
                    $incoming[preg_replace('/-\{city_slug\}$/', '', $url)] = true;
                }
            }
        }
        // The homepage/site.json's own Related Links block can also link OUT to pages —
        // counts as a source of incoming links for every city group (the home page is
        // the one shared entry point), but the home page itself is never "orphaned."
        $site = json_decode((string) @file_get_contents(cc_master_base($masterId) . '/data/site.json'), true);
        if (is_array($site)) {
            foreach (($site['content_blocks'] ?? []) as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'related_links') continue;
                foreach (($block['rl_items'] ?? []) as $item) {
                    $url = trim((string) ($item['url'] ?? ''), '/');
                    $incoming[preg_replace('/-\{city_slug\}$/', '', $url)] = true;
                }
            }
        }

        foreach ($stems as $stem => $slug) {
            if (!isset($incoming[$stem])) {
                $issues[] = ['page' => $slug, 'field' => 'related_links (within its own city)', 'detail' => 'no other page in this city\'s page set links to this page'];
            }
        }
    }
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues ? 'Add these pages to the Related Links candidate list on at least one relevant sibling page in the same city — a page search engines and visitors never get linked to is far less likely to rank or be found.' : '',
    ];
}

/* ════════════════════════════════════════════════════════════════════════════
 * SHARED HTML-content checks — used by BOTH mode 2 (built domain, local files) and
 * mode 3 (live domain, real HTTP). The logic ("is this title too long," "is noindex
 * present") only cares about a string of final rendered HTML — it has no idea and
 * doesn't need to know whether that string came from a local file or a live fetch.
 * Writing it once here means mode 2 and mode 3 can never quietly drift into checking
 * the same thing two different ways; if the detection logic improves, both modes get
 * the improvement for free.
 *
 * Each cc_html_issue_*() takes raw HTML + a page label for reporting, and returns a
 * single issue array or null (nothing wrong). The per-mode registry functions below
 * handle iterating however many pages that mode has HTML for and aggregating into the
 * {ok,count,issues,recommendation} shape every other check already returns.
 * ════════════════════════════════════════════════════════════════════════════ */

function cc_html_issue_title(string $html, string $pageLabel): ?array {
    if (!preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        return ['page' => $pageLabel, 'field' => '<title>', 'detail' => 'no <title> tag found at all'];
    }
    $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    $len = strlen($title);
    if ($len === 0) return ['page' => $pageLabel, 'field' => '<title>', 'detail' => '<title> tag is empty'];
    if ($len > 60) return ['page' => $pageLabel, 'field' => '<title>', 'detail' => "{$len} characters (limit 60) — actual rendered text: \"{$title}\""];
    return null;
}

function cc_html_issue_meta_description(string $html, string $pageLabel): ?array {
    if (!preg_match('~<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']~is', $html, $m)) {
        return null; // absent is a template-mode concern already covered; don't double-report here
    }
    $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    $len = strlen($desc);
    if ($len > 155) return ['page' => $pageLabel, 'field' => 'meta description', 'detail' => "{$len} characters (limit 155) — actual rendered text: \"{$desc}\""];
    return null;
}

/**
 * A page can look completely normal to a visitor and still be invisible to Google —
 * a leftover noindex from staging is the classic, silent way this happens. Matches
 * <meta name="robots" ...noindex...> case-insensitively, wherever "noindex" appears
 * in the content attribute (it can be combined with other directives, e.g.
 * "noindex, follow").
 */
function cc_html_issue_noindex(string $html, string $pageLabel): ?array {
    if (preg_match('~<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*noindex[^"\']*)["\']~i', $html, $m)) {
        return ['page' => $pageLabel, 'field' => 'meta robots', 'detail' => "noindex is set (\"{$m[1]}\") — Google will never index this page while this is present"];
    }
    return null;
}

/** $expectedHost with no scheme/path, e.g. "wrenappliancerepair.com". */
function cc_html_issue_canonical(string $html, string $pageLabel, string $expectedHost): ?array {
    if (!preg_match('~<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']~i', $html, $m)) {
        return ['page' => $pageLabel, 'field' => 'canonical', 'detail' => 'no canonical link tag found'];
    }
    $host = strtolower((string) parse_url($m[1], PHP_URL_HOST));
    $expectedHost = strtolower(preg_replace('/^www\./', '', $expectedHost));
    $host = preg_replace('/^www\./', '', $host);
    if ($host !== '' && $host !== $expectedHost) {
        return ['page' => $pageLabel, 'field' => 'canonical', 'detail' => "canonical points at {$host}, not this domain ({$expectedHost}) — \"{$m[1]}\""];
    }
    return null;
}

/** Yield [relative path => html contents] for every .html file under $dir. */
function cc_each_html_file(string $dir): Generator {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'html') continue;
        $html = (string) @file_get_contents($f->getPathname());
        yield substr($f->getPathname(), strlen($dir) + 1) => $html;
    }
}

/** Shared shape-builder: turn a flat list of issues into the standard check result. */
function cc_issue_list_result(array $issues, string $recommendation): array {
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues ? $recommendation : '',
    ];
}

/* ════════════════════════════════════════════════════════════════════════════
 * MODE 2 — "First built domain": checks that need a real build's rendered HTML
 * to exist, which the template-only checks above structurally cannot see. Kept as
 * fully separate functions/registry from cc_registry()/cc_run() above, on purpose —
 * zero risk of touching already-shipped, already-tested template-mode behavior.
 *
 * Scoped deliberately narrow for this first pass: ms_seo_gate() (Google's own 5-check
 * gate, already run automatically on every build) can't be re-exposed here without new
 * plumbing — it reads $workingDir/data/site.json, and build_one.php deletes workingDir
 * right after the build finishes. Schema-identity/aggregateRating/areaServed checks need
 * careful JSON-LD parsing that deserves its own careful pass, not a rushed one bolted
 * onto this batch.
 * ════════════════════════════════════════════════════════════════════════════ */

function cc_registry_built(): array {
    return [
        ['id' => 'unresolved_tokens', 'label' => 'No literal {token} placeholder survived into the actual rendered HTML (checked on a real build, not the raw template)', 'fn' => 'cc_check_unresolved_tokens'],
        ['id' => 'built_title',       'label' => 'Title tag, as it actually rendered, is not too long — checked on every real page, not the raw template guess', 'fn' => 'cc_check_built_title'],
        ['id' => 'built_meta',        'label' => 'Meta description, as it actually rendered, is not too long', 'fn' => 'cc_check_built_meta'],
        ['id' => 'built_noindex',     'label' => 'No page accidentally carries a noindex tag (a page can look completely normal and still never reach Google)', 'fn' => 'cc_check_built_noindex'],
        ['id' => 'built_canonical',   'label' => 'Every page\'s canonical tag points at this domain, not somewhere else', 'fn' => 'cc_check_built_canonical'],
        ['id' => 'built_images',      'label' => 'Every content photo has real alt text that relates to its own page\'s keyword — checked stricter on the hero image than on the rest', 'fn' => 'cc_check_built_images'],
    ];
}

/** Homepage's own primary_keyword, plus every data/pages/*.json page's, keyed by the exact
 *  relative output path cc_each_html_file() yields for it ('index.html' for the homepage,
 *  '{slug}/index.html' for a landing page) — so a rendered file can be matched straight back
 *  to the template keyword that produced it. City pages and blog posts (generated per-domain,
 *  not stored as a static data/pages/*.json file) aren't in this map; cc_html_issue_images()
 *  still checks them for missing alt, it just has no keyword to compare against — same as
 *  cc_check_alt_keyword() already does for any page with no keyword set. */
function cc_page_keywords_by_output_path(string $masterId): array {
    $base = cc_master_base($masterId);
    $map = [];
    $site = json_decode((string) @file_get_contents("$base/data/site.json"), true) ?: [];
    $map['index.html'] = (string) ($site['seo']['primary_keyword'] ?? '');
    foreach (glob("$base/data/pages/*.json") ?: [] as $f) {
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) continue;
        $slug = (string) ($data['slug'] ?? '');
        if ($slug === '') continue;
        $map["$slug/index.html"] = (string) ($data['seo']['primary_keyword'] ?? '');
    }
    return $map;
}

function cc_check_built_images(array $domainInfo): array {
    $keywords = cc_page_keywords_by_output_path($domainInfo['masterId']);
    $issues = [];
    foreach (cc_each_html_file($domainInfo['dir']) as $rel => $html) {
        $keyword = $keywords[$rel] ?? '';
        $issues = array_merge($issues, cc_html_issue_images($html, $rel, $keyword));
    }
    return cc_issue_list_result($issues, 'Add real alt text to the listed photos, written to relate to that page\'s own keyword — and for a hero image specifically, make it a close match: it\'s the single strongest place on the page for it to land.');
}

/**
 * The first domain, in this batch's own target-list order, that has a completed build —
 * NOT the first LIVE domain. Matches the existing manual checklist's own wording ("after
 * Generate Sites and BEFORE Go Live, run..."): this has to be checkable before a domain
 * is anywhere near DNS, or it stops being a pre-flight gate and becomes a post-mortem.
 */
function cc_find_first_built_domain(string $masterId, string $batchId): ?array {
    $csvPath = ms_batch_dir($masterId, $batchId) . '/params.csv';
    if (!is_file($csvPath)) return null;
    $built  = ms_batch_built($masterId, $batchId);
    $parsed = ms_parse_csv($csvPath);
    foreach ($parsed['rows'] ?? [] as $row) {
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        if ($domain === '') continue;
        $slug = ms_domain_slug($domain);
        if (isset($built[$slug])) {
            return ['domain' => $domain, 'dir' => $built[$slug]['dir'], 'built_at' => $built[$slug]['built_at']];
        }
    }
    return null;
}

/**
 * Verified against 20 real built domains before shipping (appliance-site/b3's full
 * output/ at the time): zero false positives from this pattern — no inline JS object
 * destructuring, no CSS rule block, nothing else in real rendered output happens to look
 * like a lowercase-only {token}. Deliberately narrow (letters/underscores only, nothing
 * else between the braces) — a JSON-LD `{"@context":...}` or a CSS `{color:red}` can't
 * match this pattern either way, so there's no need to special-case script/style tags.
 */
function cc_check_unresolved_tokens(array $domainInfo): array {
    $dir = $domainInfo['dir'];
    $issues = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'html') continue;
        $html = (string) @file_get_contents($f->getPathname());
        if (preg_match_all('~\{[a-z_]+\}~', $html, $m)) {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            foreach (array_unique($m[0]) as $tok) {
                $issues[] = ['page' => $rel, 'field' => 'rendered HTML', 'detail' => "literal unresolved token found: {$tok}"];
            }
        }
    }
    return [
        'ok'             => empty($issues),
        'count'          => count($issues),
        'issues'         => array_slice($issues, 0, 30),
        'recommendation' => $issues ? 'A shortcode token survived into live HTML — find the field that set it (often a photo/alt field captured before resolve_shortcodes() ran) and fix the source, then rebuild.' : '',
    ];
}

function cc_check_built_title(array $domainInfo): array {
    $issues = [];
    foreach (cc_each_html_file($domainInfo['dir']) as $rel => $html) {
        if ($issue = cc_html_issue_title($html, $rel)) $issues[] = $issue;
    }
    return cc_issue_list_result($issues, 'Shorten the <title> tag on the listed pages — this is the actual rendered text, post city/business substitution, so it\'s the real test of what Google will truncate.');
}

function cc_check_built_meta(array $domainInfo): array {
    $issues = [];
    foreach (cc_each_html_file($domainInfo['dir']) as $rel => $html) {
        if ($issue = cc_html_issue_meta_description($html, $rel)) $issues[] = $issue;
    }
    return cc_issue_list_result($issues, 'Shorten the meta description on the listed pages.');
}

/** 404.html is DELIBERATELY noindexed with no canonical — static_build.php sets
 *  $seo['robots_noindex'] = true for it on purpose, and site-template.php's own comment
 *  explains why a noindexed page has no canonical either ("a bare slug ('') falling
 *  through [to a real canonical] would be a false, misleading pair of signals on a
 *  404"). Found live while testing this exact check before shipping it — excluding by
 *  name rather than guessing at a general rule, since 404.html is the one page this
 *  codebase has actually confirmed intentional for. */
const CC_NOINDEX_EXEMPT_PAGES = ['404.html'];

function cc_check_built_noindex(array $domainInfo): array {
    $issues = [];
    foreach (cc_each_html_file($domainInfo['dir']) as $rel => $html) {
        if (in_array($rel, CC_NOINDEX_EXEMPT_PAGES, true)) continue;
        if ($issue = cc_html_issue_noindex($html, $rel)) $issues[] = $issue;
    }
    return cc_issue_list_result($issues, 'Remove the noindex directive on the listed pages, unless it was genuinely intentional for that one page.');
}

function cc_check_built_canonical(array $domainInfo): array {
    $issues = [];
    foreach (cc_each_html_file($domainInfo['dir']) as $rel => $html) {
        if (in_array($rel, CC_NOINDEX_EXEMPT_PAGES, true)) continue;
        if ($issue = cc_html_issue_canonical($html, $rel, $domainInfo['domain'])) $issues[] = $issue;
    }
    return cc_issue_list_result($issues, 'Fix the canonical tag on the listed pages — a clone that canonicalizes to the wrong domain hands that page\'s ranking away entirely.');
}

/** Run every "built domain" check against the batch's first built domain. */
function cc_run_built(string $masterId, string $batchId): array {
    $domainInfo = cc_find_first_built_domain($masterId, $batchId);
    if ($domainInfo === null) {
        return ['domain' => null, 'results' => []];
    }
    $domainInfo['masterId'] = $masterId;
    $results = [];
    foreach (cc_registry_built() as $i => $check) {
        $fn = $check['fn'];
        $r  = $fn($domainInfo);
        $results[] = ['n' => $i + 1, 'id' => $check['id'], 'label' => $check['label'],
            'ok' => $r['ok'], 'count' => $r['count'], 'issues' => $r['issues'], 'recommendation' => $r['recommendation']];
    }
    return ['domain' => $domainInfo['domain'], 'results' => $results];
}

/* ════════════════════════════════════════════════════════════════════════════
 * MODE 3 — "Live domain": checks that need the domain actually resolving on the
 * real internet — a real HTTP request, not a file read. Separate precondition from
 * mode 2 (a domain can be fully built and still not be live yet), so this has its own
 * domain-discovery against the live fleet state, not the batch's local build output.
 *
 * The homepage is fetched ONCE in cc_run_live() and handed to every check via $ctx —
 * title/meta/noindex/canonical reuse the exact same cc_html_issue_*() functions mode 2
 * uses (same detection logic, same thresholds, just handed a live-fetched string
 * instead of a local file's contents), so the two modes can never quietly check the
 * same thing two different ways.
 *
 * Scoped narrow for the same reason as mode 2: everything here is one or two cheap GET/
 * HEAD requests, no cost. Broken-internal-link crawling and sitemap-completeness are
 * real, valuable, and NOT here — they need an actual crawler walking the live site, a
 * bigger, separate build. The external-API tier (PageSpeed Insights, Rich Results Test,
 * Search Console) is a different thing again — real cost and API-key setup, its own
 * decision.
 * ════════════════════════════════════════════════════════════════════════════ */

function cc_registry_live(): array {
    return [
        ['id' => 'https_ok',        'label' => 'The live domain actually resolves over HTTPS with no certificate or connection error', 'fn' => 'cc_check_https'],
        ['id' => 'robots_sane',     'label' => 'robots.txt exists and does not block the whole site from crawlers', 'fn' => 'cc_check_robots_sane'],
        ['id' => 'live_title',      'label' => 'Homepage title tag, as it actually rendered live, is not too long', 'fn' => 'cc_check_live_title'],
        ['id' => 'live_meta',       'label' => 'Homepage meta description, as it actually rendered live, is not too long', 'fn' => 'cc_check_live_meta'],
        ['id' => 'live_noindex',    'label' => 'Homepage does not accidentally carry a noindex tag', 'fn' => 'cc_check_live_noindex'],
        ['id' => 'live_canonical',  'label' => 'Homepage canonical tag points at this domain, not somewhere else', 'fn' => 'cc_check_live_canonical'],
        ['id' => 'og_image_loads',  'label' => 'The social-share image (og:image) is a real, loadable file — not just present in the markup', 'fn' => 'cc_check_og_image_loads'],
        ['id' => 'redirect_chain',  'label' => 'http:// reaches this domain\'s https:// version in exactly one hop — no wrong-domain redirect, no redirect chain', 'fn' => 'cc_check_redirect_consistency'],
        ['id' => 'live_images',     'label' => 'Homepage photos, as they actually rendered live, have real alt text that relates to the homepage keyword — checked stricter on the hero image than on the rest', 'fn' => 'cc_check_live_images'],
    ];
}

/** The first domain in this batch that infra_state_db() shows as actually live. */
function cc_find_live_domain(string $masterId, string $batchId): ?string {
    require_once __DIR__ . '/../../admin/infra/lib/state.php';
    $db = infra_state_db();
    $stmt = $db->prepare("SELECT domain FROM domains WHERE batch = ? AND status = 'live' ORDER BY go_live_at LIMIT 1");
    $stmt->execute([$masterId . '/' . $batchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['domain'] ?? null;
}

function cc_check_https(array $ctx): array {
    $r = $ctx['home'];
    $ok = $r['code'] >= 200 && $r['code'] < 400 && $r['error'] === '';
    return cc_issue_list_result(
        $ok ? [] : [['page' => $ctx['domain'], 'field' => 'https://', 'detail' => $r['error'] !== '' ? $r['error'] : "HTTP {$r['code']}"]],
        'The domain did not respond cleanly over HTTPS — check DNS, the SSL certificate, and that the origin is actually up.'
    );
}

function cc_check_robots_sane(array $ctx): array {
    require_once __DIR__ . '/../../admin/infra/lib/http.php';
    $domain = $ctx['domain'];
    $r = infra_http('GET', "https://{$domain}/robots.txt", ['timeout' => 15]);
    if ($r['code'] !== 200) {
        return cc_issue_list_result(
            [['page' => $domain, 'field' => '/robots.txt', 'detail' => "did not load (HTTP {$r['code']})"]],
            'robots.txt should always exist on a static build (static_build.php writes one unconditionally) — a missing one usually means the upload is incomplete.'
        );
    }
    $body = (string) ($r['raw'] ?? '');
    $blocksEverything = (bool) preg_match('/^\s*Disallow:\s*\/\s*$/mi', $body);
    return cc_issue_list_result(
        $blocksEverything ? [['page' => $domain, 'field' => '/robots.txt', 'detail' => 'contains a blanket "Disallow: /" — blocks every crawler from the entire site']] : [],
        'Remove the blanket Disallow rule — this is telling Google (and everything else) not to crawl any page on this domain.'
    );
}

function cc_check_live_title(array $ctx): array {
    $issue = cc_html_issue_title((string) ($ctx['home']['raw'] ?? ''), $ctx['domain'] . ' (homepage)');
    return cc_issue_list_result($issue ? [$issue] : [], 'Shorten the live <title> tag — this is the real, post-substitution text Google actually sees.');
}

function cc_check_live_meta(array $ctx): array {
    $issue = cc_html_issue_meta_description((string) ($ctx['home']['raw'] ?? ''), $ctx['domain'] . ' (homepage)');
    return cc_issue_list_result($issue ? [$issue] : [], 'Shorten the live meta description.');
}

function cc_check_live_noindex(array $ctx): array {
    $issue = cc_html_issue_noindex((string) ($ctx['home']['raw'] ?? ''), $ctx['domain'] . ' (homepage)');
    return cc_issue_list_result($issue ? [$issue] : [], 'Remove the noindex directive — the homepage should never carry one.');
}

function cc_check_live_canonical(array $ctx): array {
    $issue = cc_html_issue_canonical((string) ($ctx['home']['raw'] ?? ''), $ctx['domain'] . ' (homepage)', $ctx['domain']);
    return cc_issue_list_result($issue ? [$issue] : [], 'Fix the canonical tag — it should point at this exact domain.');
}

function cc_check_og_image_loads(array $ctx): array {
    $html = (string) ($ctx['home']['raw'] ?? '');
    if (!preg_match('~<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']~i', $html, $m)) {
        return cc_issue_list_result(
            [['page' => $ctx['domain'] . ' (homepage)', 'field' => 'og:image', 'detail' => 'no og:image meta tag found']],
            'Set an og:image on the homepage.'
        );
    }
    require_once __DIR__ . '/../../admin/infra/lib/http.php';
    $r = infra_http('GET', $m[1], ['timeout' => 15]);
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    return cc_issue_list_result(
        $ok ? [] : [['page' => $ctx['domain'] . ' (homepage)', 'field' => 'og:image', 'detail' => "image at {$m[1]} did not load (HTTP {$r['code']})"]],
        'The social-share image URL is broken — this is exactly the og_image class of bug fixed fleet-wide earlier (see includes/site-template.php\'s fallback) — rebuild so it points at a real file.'
    );
}

/**
 * Not built on infra_http() — that module deliberately never follows redirects (every
 * other caller needs the raw first response, e.g. to read a Location header itself), and
 * extending its shared contract for this one niche need would risk changing behavior for
 * every other consumer across the infra console. A small, self-contained curl call here
 * is the more honest fix than bending a shared module to one caller's shape.
 */
function cc_trace_redirects(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true, // HEAD-equivalent — only the redirect chain matters here
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $result = [
        'final_url'      => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'redirect_count' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
        'error'          => curl_error($ch),
    ];
    curl_close($ch);
    return $result;
}

/** Homepage-only, not every page — live mode stays at one HTTP request per check by
 *  design (see this file's mode-3 docblock); the full every-page version of this same
 *  check already runs for free in built mode (cc_check_built_images), off local files. */
function cc_check_live_images(array $ctx): array {
    $base = cc_master_base($ctx['masterId']);
    $site = json_decode((string) @file_get_contents("$base/data/site.json"), true) ?: [];
    $keyword = (string) ($site['seo']['primary_keyword'] ?? '');
    $issues = cc_html_issue_images((string) ($ctx['home']['raw'] ?? ''), $ctx['domain'] . ' (homepage)', $keyword);
    return cc_issue_list_result($issues, 'Add real alt text to the listed photos, written to relate to the homepage keyword — and for the hero image specifically, make it a close match.');
}

function cc_check_redirect_consistency(array $ctx): array {
    $domain = $ctx['domain'];
    $trace  = cc_trace_redirects("http://{$domain}/");
    $issues = [];
    if ($trace['error'] !== '') {
        $issues[] = ['page' => $domain, 'field' => 'http://', 'detail' => "could not test redirects: {$trace['error']}"];
    } else {
        $finalScheme  = strtolower((string) parse_url($trace['final_url'], PHP_URL_SCHEME));
        $finalHost    = preg_replace('/^www\./', '', strtolower((string) parse_url($trace['final_url'], PHP_URL_HOST)));
        $expectedHost = preg_replace('/^www\./', '', strtolower($domain));
        if ($finalScheme !== 'https') {
            $issues[] = ['page' => $domain, 'field' => 'http://', 'detail' => "never reaches https:// — final URL was {$trace['final_url']}"];
        } elseif ($finalHost !== $expectedHost) {
            $issues[] = ['page' => $domain, 'field' => 'http://', 'detail' => "redirects to a different host ({$finalHost}) instead of this domain"];
        } elseif ($trace['redirect_count'] > 1) {
            $issues[] = ['page' => $domain, 'field' => 'http://', 'detail' => "takes {$trace['redirect_count']} redirect hops to reach https:// instead of 1 — wastes crawl budget and adds latency"];
        }
    }
    return cc_issue_list_result($issues, 'Fix the redirect so http:// reaches this domain\'s https:// version in exactly one hop.');
}

/** Run every "live domain" check against the batch's first confirmed-live domain. The
 *  homepage is fetched ONCE here, not once per check — title/meta/noindex/canonical/
 *  og:image all read from the same $ctx['home'] response. */
function cc_run_live(string $masterId, string $batchId): array {
    $domain = cc_find_live_domain($masterId, $batchId);
    if ($domain === null) {
        return ['domain' => null, 'results' => []];
    }
    require_once __DIR__ . '/../../admin/infra/lib/http.php';
    $ctx = ['domain' => $domain, 'masterId' => $masterId, 'home' => infra_http('GET', "https://{$domain}/", ['timeout' => 15])];

    $results = [];
    foreach (cc_registry_live() as $i => $check) {
        $fn = $check['fn'];
        $r  = $fn($ctx);
        $results[] = ['n' => $i + 1, 'id' => $check['id'], 'label' => $check['label'],
            'ok' => $r['ok'], 'count' => $r['count'], 'issues' => $r['issues'], 'recommendation' => $r['recommendation']];
    }
    return ['domain' => $domain, 'results' => $results];
}
