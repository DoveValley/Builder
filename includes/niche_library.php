<?php
/**
 * includes/niche_library.php — the shared "write a niche's facts once"
 * library, deliberately independent of both consumers.
 *
 * Neither the fleet's sites/{master}/multisite/niche_brief.json nor LPC's
 * client_niche_profiles/*.json read this file directly at runtime — see
 * docs/lpc-vs-site-factory-boundary.md. This is a COPY-IN source, not a live
 * link: a "pull from library" action on each side's own niche screen copies
 * these fields in once, and a later library edit does not retroactively
 * change a niche brief or profile that already pulled from it. That's on
 * purpose — a library edit must never silently change content already
 * generated from a prior pull, with no human looking at the change first.
 *
 * Holds only what's true regardless of business model: what services/pages
 * exist in this niche, their natural keyword, what's locally/seasonally
 * unique about it, and what extra per-city facts are worth researching.
 * Does NOT hold: guardrails, business_descriptor, or anything else that
 * depends on whether the business performing the work is a referral network
 * (the fleet) or the real operator (LPC) — those stay authored separately on
 * each side, always.
 *
 * SCHEMA IS DELIBERATELY LOOSE. Every load runs through
 * niche_library_with_defaults() so a field added to the shape later never
 * breaks a niche written before that field existed, and a one-off field
 * added to a single niche's own file never has to be added everywhere else.
 * Neither side is forced to use every field this file defines — read what
 * you need, ignore the rest.
 */

function niche_library_dir() {
    return BASE_DIR . '/niche_library/';
}

function niche_library_valid_id($niche_id) {
    return is_string($niche_id) && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $niche_id) === 1;
}

function niche_library_file($niche_id) {
    if (!niche_library_valid_id($niche_id)) return null;
    return niche_library_dir() . $niche_id . '.json';
}

/** Current shape. Bump NICHE_LIBRARY_VERSION, not these defaults, if a future
 *  change needs to tell old files apart from new ones at read time. */
const NICHE_LIBRARY_VERSION = 1;

function niche_library_defaults() {
    return [
        '_version'         => NICHE_LIBRARY_VERSION,
        'niche_id'         => '',
        'label'            => '',
        'service_noun'     => '',
        'customer_noun'    => 'customer',
        'local_angle'      => '',
        'seasonal_calendar' => '',
        'tone'             => 'professional',
        // Starting catalog only — slug/label/keyword_pattern. Real researched
        // volume/CPC/difficulty is fleet-only, fetched later, and deliberately
        // never lives here: it costs money, goes stale, and only matters for
        // the fleet's own "where to buy a domain" decision, which LPC never
        // makes. See docs/lpc-vs-site-factory-boundary.md §4.
        'services'         => [], // [{slug, label, keyword_pattern}]
        'trust_bar_items'  => [],
        // related_topics references OTHER topics by slug, never a URL — each
        // side resolves its own real link from that using its own existing
        // "never link to a page that doesn't exist" rule, so that rule is
        // never duplicated a third time here.
        'blog_topics'      => [], // [{slug, title, focus_keyword, tag, related_topics:[]}]
        // {key, ask} pairs — the exact mechanism generate.py's
        // niche_research_fields() already reads for the fleet. Adopting the
        // SAME shape here means a niche written in the library drops into
        // the fleet's existing research pipeline with no translation step.
        'custom_research_fields' => [], // [{key, ask}]
        // LPC-only consumer (auto-matches a new client's typed industry to a
        // profile) — harmless for the fleet to ignore, kept here anyway so
        // the drafting tool produces a complete LPC profile in one pass.
        'match_keywords'  => [],
        'created_at'      => '',
        'updated_at'      => '',
    ];
}

/** Same deep-merge-with-defaults shape every other entity loader in this
 *  codebase uses (lpc_deep_merge_defaults(), load_data()) — a field missing
 *  from an on-disk file (because it predates that field) gets the default
 *  instead of simply not existing, so every caller can rely on every key
 *  being present. */
function niche_library_with_defaults($data) {
    $defaults = niche_library_defaults();
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }
    return $data;
}

function niche_library_list() {
    if (!is_dir(niche_library_dir())) return [];
    $ids = [];
    foreach (glob(niche_library_dir() . '*.json') as $f) {
        $ids[] = basename($f, '.json');
    }
    sort($ids);
    return $ids;
}

function niche_library_load($niche_id) {
    $file = niche_library_file($niche_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return null;
    return niche_library_with_defaults($data);
}

/** Atomic write — same tmp-file-then-rename pattern niche_brief_save.php
 *  already uses, so a crash mid-write never leaves a half-written niche. */
function niche_library_save($niche_id, $data) {
    $file = niche_library_file($niche_id);
    if ($file === null) return false;
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $data['niche_id']   = $niche_id;
    $data['_version']   = NICHE_LIBRARY_VERSION;
    $data['updated_at'] = gmdate('c');
    if (empty($data['created_at'])) {
        $existing = niche_library_load($niche_id);
        $data['created_at'] = $existing['created_at'] ?? gmdate('c');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) return false;
    return rename($tmp, $file);
}

function niche_library_delete($niche_id) {
    $file = niche_library_file($niche_id);
    if ($file === null || !file_exists($file)) return false;
    return unlink($file);
}

/** A fresh, empty entry with a real niche_id — the shape both the manual
 *  "Add" form and the AI drafting endpoint build on top of. */
function niche_library_blank($niche_id) {
    $d = niche_library_defaults();
    $d['niche_id'] = $niche_id;
    return $d;
}
