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
