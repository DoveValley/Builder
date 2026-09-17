<?php
/**
 * Generate Sites batch panel — persisted per-run checkbox defaults (Phase 3 UI).
 *
 * Every checkbox on the panel (the 7 parent steps + every sub-switch beneath them) is
 * PURELY a UI convenience here: what a run actually does is still decided entirely by
 * whatever is checked in the browser at the moment "Generate sites" is clicked (msRun()
 * reads the live DOM and posts the skip list, exactly as before this file existed).
 * This file only controls what those checkboxes are PRE-CHECKED TO the next time the
 * panel loads, so a batch's usual choices survive a reload instead of resetting to
 * "everything on" every time. build_one.php / multisite_api.php never read this file.
 *
 * Saved per BATCH (not per master — two batches off the same master can want different
 * defaults, e.g. a "test" batch vs. the real one) in
 * sites/{master}/batches/{batch}/batch_options.json (admin/batch_options_save.php), via
 * ms_batch_file_read()/ms_batch_file_write() in includes/multisite/batch.php. Separate from
 * section_rotation.json (the four rotation pin-count NUMBERS, same per-batch scope) — this
 * file is booleans only.
 */

/**
 * The full default map — every checkbox that exists in admin/_batch_panels.php's $msTree,
 * all defaulting to true (the original "everything on" behaviour before this file existed).
 * Keep this in sync with $msTree by hand: adding a new checkbox there means adding its key
 * here too, or it will always read back as on (harmless — same as never having been saved)
 * but its state won't persist.
 */
function ms_batch_options_defaults(): array {
    return [
        'steps' => [
            'ai' => true, 'visual' => true, 'structure' => true,
            'images' => true, 'tags' => true, 'landing' => true, 'pagepool' => true,
        ],
        'subs' => [
            'ai.legal_reword' => true, 'ai.disclaimer_reword' => true, 'ai.tagline_reword' => true,
            'ai.popup_reword' => true,
            'visual.palette' => true, 'visual.font' => true, 'visual.jitter' => true,
            'structure.home' => true, 'structure.landing' => true,
            'structure.classvocab' => true, 'structure.schemashape' => true,
            'images.stamp_home' => true, 'images.stamp_landing' => true,
            'images.metadata' => true, 'images.ai_photos' => true,
        ],
    ];
}

/**
 * Normalize to real booleans and fill in anything missing from the defaults — the one
 * place both the save endpoint and the panel read from, so a hand-edited or half-written
 * config file (or a checkbox added/removed from $msTree since it was saved) can't produce
 * something the panel misreads. Unknown keys in $raw (a checkbox that no longer exists)
 * are silently dropped rather than carried forward.
 */
function ms_batch_options_settings(array $raw): array {
    $d = ms_batch_options_defaults();
    $out = ['steps' => [], 'subs' => []];
    foreach (['steps', 'subs'] as $group) {
        foreach ($d[$group] as $k => $default) {
            $out[$group][$k] = array_key_exists($k, $raw[$group] ?? [])
                ? filter_var($raw[$group][$k], FILTER_VALIDATE_BOOLEAN)
                : $default;
        }
    }
    return $out;
}
