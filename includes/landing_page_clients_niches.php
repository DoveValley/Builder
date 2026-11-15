<?php
/**
 * Reusable niche content profiles for Landing Page Clients — a library
 * separate from the niche-fleet's own sites/{id}/multisite/niche_brief.json
 * files on purpose. Those briefs are written for the fleet's own referral
 * -network business model ("never write 'our team' doing the repair — this
 * is a referral/advertising network") — a real Landing Page Clients customer
 * typically DOES perform the work themselves, so inheriting that framing
 * verbatim would produce actively wrong content, not just unpolished content.
 *
 * Seeded with the 4 existing niche-fleet verticals (water/mold/pest/
 * appliance), keeping only the genuinely industry-general safety/accuracy
 * guardrails from each real niche_brief.json and dropping every
 * referral-network-specific sentence. Scott can add a 5th/6th/Nth niche
 * (law, HVAC, dental, anything) as a form on the Niche Profiles admin
 * screen — no code change needed, same "everything in the panel" principle
 * as the rest of this feature.
 */

function lpc_niche_profiles_dir() {
    return BASE_DIR . '/client_niche_profiles/';
}

function lpc_valid_niche_id($niche_id) {
    return is_string($niche_id) && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $niche_id) === 1;
}

function lpc_niche_profile_file($niche_id) {
    if (!lpc_valid_niche_id($niche_id)) return null;
    return lpc_niche_profiles_dir() . $niche_id . '.json';
}

function lpc_default_niche_profile() {
    return [
        'niche_id'            => '',
        'label'                => '',
        'match_keywords'       => [], // plain substring match against industry_category — see lpc_match_niche_profile()
        'business_descriptor'  => '',
        'default_tone'         => 'professional',
        'guardrails'           => '',
    ];
}

function lpc_load_niche_profile($niche_id) {
    $file = lpc_niche_profile_file($niche_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;
    return array_merge(lpc_default_niche_profile(), $data);
}

function lpc_save_niche_profile($niche_id, $data) {
    $file = lpc_niche_profile_file($niche_id);
    if ($file === null) return false;
    $data['niche_id'] = $niche_id;
    return lpc_atomic_write_json($file, $data);
}

function lpc_delete_niche_profile($niche_id) {
    $file = lpc_niche_profile_file($niche_id);
    if ($file === null || !file_exists($file)) return false;
    return unlink($file);
}

function lpc_list_niche_profiles() {
    if (!is_dir(lpc_niche_profiles_dir())) return [];
    $ids = [];
    foreach (glob(lpc_niche_profiles_dir() . '*.json') as $f) {
        $ids[] = basename($f, '.json');
    }
    sort($ids);
    return $ids;
}

/**
 * Plain substring match of industry_category against each profile's own
 * match_keywords — data-driven (a new niche's keywords come from the admin
 * form, not a code change), unlike this function's first version which had
 * the 4 niches' keywords hardcoded directly in PHP.
 */
function lpc_match_niche_profile($industry_category) {
    $category = strtolower(trim((string) $industry_category));
    if ($category === '') return 'custom';

    foreach (lpc_list_niche_profiles() as $niche_id) {
        $profile = lpc_load_niche_profile($niche_id);
        if (!$profile) continue;
        foreach ($profile['match_keywords'] as $kw) {
            if ($kw !== '' && strpos($category, strtolower($kw)) !== false) {
                return 'niche:' . $niche_id;
            }
        }
    }
    return 'custom';
}
