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
