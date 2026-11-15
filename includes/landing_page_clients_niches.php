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

/**
 * What each of a page's 6 flexible AI text/list slots means FOR THIS NICHE —
 * same slot shape every vertical uses (page structure stays universal), but
 * the topic/framing per slot is niche-specific: a restoration niche's
 * `local_relevance` is "climate/flood-risk"; a legal niche's version of the
 * same slot might be "local court/regulatory relevance" instead. Blank =
 * the AI falls back to a generic, still-reasonable instruction for that slot
 * (see lpc_ai_content_slot_prompt()) rather than failing or reading oddly.
 */
function lpc_default_content_slots() {
    return [
        'feature_columns_local' => '', // why/how this service matters in this specific city
        'local_relevance'       => '', // real local data this niche should reference (climate, housing stock, etc.)
        'seasonal_calendar'     => '', // when demand for this service peaks through the year, for this niche
        'why_choose_us'         => '', // local trust/credibility framing specific to this niche
        'how_it_works'          => '', // the real step-by-step process a customer goes through
        // The educational two-column comparison block (e.g. water restoration's
        // "what survives a flood vs what doesn't", appliance repair's "worth
        // repairing vs worth replacing") — what the two columns represent is
        // genuinely different per niche, unlike the other slots above which
        // just differ in framing/emphasis.
        'comparison'            => '',
    ];
}

function lpc_default_niche_profile() {
    return [
        'niche_id'            => '',
        'label'                => '',
        'match_keywords'       => [], // plain substring match against industry_category — see lpc_match_niche_profile()
        'business_descriptor'  => '',
        'default_tone'         => 'professional',
        'guardrails'           => '',
        'content_slots'        => lpc_default_content_slots(),
        // The niche-level template a new client's wizard pulls from once a
        // niche is picked: a typical service list for this vertical, each
        // with an optional keyword pattern override ({service}/{city}/{state}
        // placeholders — blank means lpc_default_primary_keyword()'s standard
        // "{service} in {city}, {state}" applies). Seeds a new client's
        // Services list once, only if it's still empty — never silently
        // overwrites services someone already customized.
        'default_services'    => [],
        // Short checkmark phrases shown in a trust bar right under the hero
        // (e.g. "24/7 Response Line", "Licensed & Insured") — static per-niche
        // text, never AI-generated or per-city. Blank uses
        // lpc_default_trust_bar_items()'s generic fallback rather than
        // showing nothing, since every niche benefits from SOME version of
        // this even before anyone's filled it in.
        'trust_bar_items'    => [],
    ];
}

function lpc_default_trust_bar_items() {
    // 'No-Cost To Connect' (the reference page's own phrasing) is deliberately
    // NOT used here — that's referral-network language (it literally runs a
    // "we connect you with independent providers" business), while LPC
    // clients do their own work directly, same guardrail already enforced
    // elsewhere (see niche_profiles.php's Guardrails field).
    return ['Fast Local Response', '24/7 Response Line', 'Licensed & Insured', 'Free Estimates'];
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
