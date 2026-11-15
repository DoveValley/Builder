<?php
/**
 * Phase 3 — AI content generation. Reuses includes/anthropic.php (the one
 * Anthropic client this codebase is supposed to have, per its own
 * "one module per service" rule) directly, rather than shelling out to the
 * niche fleet's generate.py — that script's whole pipeline is built around
 * `content_blocks` (39 flexible block types), a completely different output
 * shape than this feature's 12 fixed section types. The REUSABLE part of the
 * niche fleet's approach is the pattern, not the code: a tone/guardrails
 * system prompt + a `{var}`-filled user prompt + "return JSON only" +
 * defensive markdown-fence stripping (anthropic_message() does not do this
 * automatically, unlike generate.py's call_claude()). That pattern is what's
 * mirrored here, adapted to this feature's own 7 AI-authored section types
 * (related_links, service_area, area_map, trust_bar, and safety_toolkit stay
 * separately auto-computed, never AI-generated).
 *
 * Content Profile inheritance: when content_profile.source is "niche:X", the
 * matching real niche's own niche_brief.json (business_descriptor,
 * guardrails) is pulled in as the base, with the client's own tone/always
 * -never-mention layered on top — "per-niche-or-client uniqueness," the same
 * pattern already decided in the original design conversation.
 */

// Unlike admin/infra/lib/cities.php (which every CALLER requires separately,
// since most admin pages never need it), every single function in this file
// needs anthropic.php unconditionally — so it's required here, once, rather
// than repeating the exact "forgot the dependency" gap already hit twice
// this session (cities.php/keywords.php) at a 3rd call site. Same reasoning
// now extends to the real per-city research data this file reads: cities.php
// (population) and census.php (real housing/income data, 99.7% coverage
// across all 43,901 cities, confirmed) — both niche-agnostic, keyed by the
// exact same city_id slug LPC already uses, deliberately NOT the niche
// fleet's own per-master cities.json research files (that would couple LPC
// to files meant for a different business model and a much smaller, non-
// overlapping set of cities — see lpc_get_city_research()).
require_once __DIR__ . '/anthropic.php';
require_once __DIR__ . '/../admin/infra/lib/keywords.php'; // cities.php's infra_cn_cols() needs infra_kw_types()
require_once __DIR__ . '/../admin/infra/lib/cities.php';
require_once __DIR__ . '/../admin/infra/lib/census.php';

function lpc_ai_niche_brief($source) {
    if (strpos((string) $source, 'niche:') !== 0) return null;
    $niche_id = substr($source, 6);
    return lpc_load_niche_profile($niche_id); // same field names (business_descriptor, guardrails) as before
}

/**
 * Real per-city research — the actual fix for "LPC's AI page generation does
 * ZERO real per-city research." Population from the same `cities` table
 * lpc_city_candidates() already uses; the rest from the Census ACS table
 * (admin/infra/lib/census.php) — real median income, home age, home value,
 * homeownership rate, housing stock size, covering 43,772 of 43,901 cities.
 * Never invented: a field missing from either source is simply omitted from
 * the prompt (lpc_ai_research_block() below), not guessed.
 */
function lpc_get_city_research($city_id) {
    $db = infra_cities_init();
    $st = $db->prepare('SELECT population FROM cities WHERE id = ?');
    $st->execute([$city_id]);
    $population = (int) ($st->fetchColumn() ?: 0);

    $census = infra_census_lookup($city_id) ?? [];

    return [
        'population'          => $population ?: null,
        'income_median'       => $census['income_median'] ?? null,
        'homeownership_pct'   => $census['homeownership_pct'] ?? null,
        'home_value_median'   => $census['home_value_median'] ?? null,
        'year_built_median'   => $census['year_built_median'] ?? null,
        'housing_units_total' => $census['housing_units_total'] ?? null,
    ];
}

/** Formats lpc_get_city_research()'s data as an explicit fact block for the
 *  prompt — every number here is real, so the model is told to use ONLY
 *  these, never invent additional specific stats beyond them. */
function lpc_ai_research_block($city, $state, $research) {
    $lines = [];
    if (!empty($research['population']))          $lines[] = '- Population: ' . number_format($research['population']);
    if (!empty($research['year_built_median']))    $lines[] = '- Median home built: ' . $research['year_built_median'];
    if (!empty($research['home_value_median']))    $lines[] = '- Median home value: $' . number_format($research['home_value_median']);
    if (!empty($research['homeownership_pct']))    $lines[] = '- Homeownership rate: ' . $research['homeownership_pct'] . '%';
    if (!empty($research['income_median']))        $lines[] = '- Median household income: $' . number_format($research['income_median']);
    if (!empty($research['housing_units_total']))  $lines[] = '- Total housing units: ' . number_format($research['housing_units_total']);

    if (!$lines) return "No verified data is available for {$city}, {$state} beyond its name — do not invent specific population, housing, or income figures for it.";

    return "Real data about {$city}, {$state} (use these facts naturally where relevant — never invent "
        . "additional specific numbers beyond what's listed here):\n" . implode("\n", $lines);
}

/** The instruction for one niche-specific content slot — the niche profile's
 *  own guidance if set, else a generic-but-still-reasonable fallback so a
 *  niche that hasn't filled in its content_slots yet doesn't read oddly. */
function lpc_ai_content_slot_prompt($niche_brief, $slotKey) {
    $slots = $niche_brief['content_slots'] ?? [];
    if (!empty($slots[$slotKey])) return $slots[$slotKey];

    $fallbacks = [
        'feature_columns_local' => 'why/how this service matters specifically in this city',
        'local_relevance'       => 'how the real local data provided connects to the need for this service',
        'seasonal_calendar'     => 'when demand for this service tends to peak through the year',
        'why_choose_us'         => 'why a local customer should trust this business specifically',
        'how_it_works'          => 'the real step-by-step process a customer goes through',
    ];
    return $fallbacks[$slotKey] ?? '';
}

function lpc_ai_system_prompt($client, $niche_brief) {
    $business = $client['identity']['business_name'] ?: 'this business';
    $tone = $client['content_profile']['tone'] ?: 'professional';

    $parts   = [];
    $parts[] = "You write landing-page copy for {$business}, a real local-service business.";
    if ($niche_brief) {
        if (!empty($niche_brief['business_descriptor'])) $parts[] = 'What they do: ' . $niche_brief['business_descriptor'] . '.';
        if (!empty($niche_brief['guardrails']))          $parts[] = 'Rules you must follow: ' . $niche_brief['guardrails'];
    }
    $parts[] = "Tone: {$tone}." . (!empty($niche_brief['default_tone']) ? ' Industry tone notes: ' . $niche_brief['default_tone'] . '.' : '');
    if (!empty($client['content_profile']['always_mention'])) {
        $parts[] = 'Always mention, naturally and only where it fits: ' . implode('; ', $client['content_profile']['always_mention']) . '.';
    }
    if (!empty($client['content_profile']['never_mention'])) {
        $parts[] = 'Never mention: ' . implode('; ', $client['content_profile']['never_mention']) . '.';
    }
    $parts[] = 'Never invent a phone number, street address, review count, or statistic — omit rather than fabricate.';
    $parts[] = 'Every page targets one primary keyword, stated in the prompt below. Keep that keyword\'s subject '
             . 'the clear, central focus of every section — secondary keywords get a light natural mention at most, '
             . 'never at the expense of the primary keyword\'s focus, and never stuffed.';
    $parts[] = 'Output ONLY valid JSON matching the exact shape requested. No markdown code fences, no commentary before or after.';

    return implode("\n\n", $parts);
}

/** Never silently blank (see lpc_default_client_data()'s seo.primary_keyword
 *  comment) — the one default every page's primary_keyword falls back to the
 *  first time it's generated, if nothing was set by hand first. $pattern is
 *  a service's own optional keyword_pattern (from a niche's default_services,
 *  see lpc_default_niche_profile()) — {service}/{city}/{state} placeholders,
 *  blank means the standard "{service} in {city}, {state}" shape applies. */
function lpc_default_primary_keyword($serviceLabel, $city, $state, $pattern = '') {
    if (!empty($pattern)) {
        return strtr($pattern, ['{service}' => $serviceLabel, '{city}' => $city, '{state}' => $state]);
    }
    return $serviceLabel . ' in ' . $city . ', ' . $state;
}

/**
 * $niche_brief may be null (a "custom" content_profile source with no
 * matching niche profile) — lpc_ai_content_slot_prompt() already handles
 * that (falls back to its own generic instruction per slot), so this
 * function doesn't need its own null guard for it.
 */
function lpc_ai_user_prompt($business, $city, $state, $serviceLabel, $primaryKeyword, $secondaryKeywords, $research, $niche_brief) {
    $secondaryLine = '';
    if (!empty($secondaryKeywords)) {
        $secondaryLine = "Secondary phrases — weave in naturally ONLY where they truly fit, skip any that don't, "
            . "never force them, never let them compete with the primary keyword for focus: "
            . implode('; ', $secondaryKeywords) . ".\n\n";
    }

    $slot = fn($key) => lpc_ai_content_slot_prompt($niche_brief, $key);

    return "Write landing page copy for {$business}'s {$serviceLabel} service in {$city}, {$state}.\n\n"
        . "Primary keyword: \"{$primaryKeyword}\" — this page exists to rank for it. This phrase's subject must "
        . "read as the clear, dominant focus of every section below, stated naturally (not stuffed).\n\n"
        . $secondaryLine
        . lpc_ai_research_block($city, $state, $research) . "\n\n"
        . "Return this exact JSON shape, every field filled with specific copy that names {$city} where natural "
        . "(not generic boilerplate that could apply to any city). Each section's guidance in parentheses below "
        . "is about CONTENT, not formatting — never print the guidance text itself:\n\n"
        . "{\n"
        . "  \"hero\": {\"headline\": \"...\", \"subheading\": \"...\", \"cta_text\": \"...\"},\n"
        . "  \"meta_description\": \"... (max 155 characters, leads with the primary keyword, reads as a real search-result snippet)\",\n"
        . "  \"city_intro\": {\"heading\": \"...\", \"body\": \"2-3 sentences — what kind of place {$city} is, grounded in the real data above, not generic filler\"},\n"
        . "  \"feature_columns_local\": {\"heading\": \"...\", \"items\": [{\"title\": \"...\", \"text\": \"...\"}, {\"title\": \"...\", \"text\": \"...\"}, "
        . "{\"title\": \"...\", \"text\": \"...\"}] (" . $slot('feature_columns_local') . ")},\n"
        . "  \"local_relevance\": {\"heading\": \"...\", \"body\": \"2-3 sentences (" . $slot('local_relevance') . ")\"},\n"
        . "  \"how_it_works\": {\"heading\": \"...\", \"items\": [{\"title\": \"Step 1 ...\", \"text\": \"...\"}, {\"title\": \"Step 2 ...\", \"text\": \"...\"}, "
        . "{\"title\": \"Step 3 ...\", \"text\": \"...\"}] (" . $slot('how_it_works') . ")},\n"
        . "  \"seasonal_calendar\": {\"heading\": \"...\", \"items\": [{\"title\": \"Spring\", \"text\": \"...\"}, "
        . "{\"title\": \"Summer\", \"text\": \"...\"}, {\"title\": \"Fall\", \"text\": \"...\"}, {\"title\": \"Winter\", \"text\": \"...\"}] "
        . "(exactly these 4 season titles; each 1-2 sentences on how THAT specific season affects " . $slot('seasonal_calendar') . " — "
        . "skip a season only if it genuinely doesn't apply to this climate, e.g. no real winter risk, and say so briefly rather than inventing one)},\n"
        . "  \"why_choose_us\": {\"heading\": \"...\", \"body\": \"2-3 sentences (" . $slot('why_choose_us') . ")\"},\n"
        . "  \"services_list\": {\"heading\": \"...\", \"items\": [{\"title\": \"...\", \"text\": \"...\"}, "
        . "{\"title\": \"...\", \"text\": \"...\"}, {\"title\": \"...\", \"text\": \"...\"}] (what this specific service includes)},\n"
        . "  \"faq\": {\"heading\": \"...\", \"items\": [{\"q\": \"...\", \"a\": \"...\"}, {\"q\": \"...\", \"a\": \"...\"}, "
        . "{\"q\": \"...\", \"a\": \"...\"}]},\n"
        . "  \"cta\": {\"heading\": \"...\", \"body\": \"...\", \"button_text\": \"...\"}\n"
        . "}\n\n"
        . 'Do not include any phone number in any field — that is filled in separately from verified business data.';
}

/** anthropic_message()/anthropic_message_many() don't strip markdown fences
 *  (unlike generate.py's call_claude()) — the model wraps JSON in ```json
 *  fences often enough that this needs handling, not hoping it won't happen. */
function lpc_strip_json_fences($text) {
    $text = trim((string) $text);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) return $m[1];
    return $text;
}

/** Merges AI-authored copy into a page's sections, preserving everything the
 *  AI doesn't own: phone numbers (set from real hub data, see callers below),
 *  images, and related_links (always separately auto-computed at publish).
 *  Matches by each section's 'key' when it has one (the 5 niche-injected
 *  text/highlights/steps slots — several share the same 'type', so 'type'
 *  alone can't disambiguate which AI content belongs to which), falling back
 *  to 'type' for the singular sections (hero/services_list/faq/cta) that
 *  don't need one. The plugin's renderer never reads 'key' — purely an
 *  admin/AI bookkeeping concern. */
function lpc_apply_ai_sections(&$page, $aiData) {
    foreach ($page['sections'] as &$section) {
        $type = $section['type'] ?? '';
        $matchKey = $section['key'] ?? $type;
        if (!isset($aiData[$matchKey]) || !is_array($aiData[$matchKey])) continue;
        $src = $aiData[$matchKey];
        switch ($type) {
            case 'hero':
                $section['headline']   = $src['headline'] ?? $section['headline'];
                $section['subheading'] = $src['subheading'] ?? $section['subheading'];
                $section['cta_text']   = $src['cta_text'] ?? $section['cta_text'];
                break;
            case 'text':
                $section['heading'] = $src['heading'] ?? $section['heading'];
                $section['body']    = $src['body'] ?? $section['body'];
                break;
            case 'highlights':
            case 'steps':
            case 'services_list':
            case 'faq':
                $section['heading'] = $src['heading'] ?? $section['heading'];
                if (!empty($src['items']) && is_array($src['items'])) $section['items'] = $src['items'];
                break;
            case 'cta':
                $section['heading']     = $src['heading'] ?? $section['heading'];
                $section['body']        = $src['body'] ?? $section['body'];
                $section['button_text'] = $src['button_text'] ?? $section['button_text'];
                break;
        }
    }
    unset($section);
}

/** meta_description isn't a section at all (it's a top-level seo field), so
 *  it rides along in the same AI JSON response but gets applied separately.
 *  Never overwrites with blank — same never-clobber-a-manual-value rule as
 *  page_title/primary_keyword. */
function lpc_apply_ai_meta(&$page, $aiData) {
    if (!empty($aiData['meta_description']) && is_string($aiData['meta_description'])) {
        $page['seo']['meta_description'] = $aiData['meta_description'];
    }
}

/** Phone numbers are never AI-generated — filled from the real serving hub's
 *  real phone number, same "never invent verified business data" rule the
 *  system prompt states explicitly. */
function lpc_fill_real_phone(&$page, $hub) {
    $phone = $hub['phone'] ?? '';
    foreach ($page['sections'] as &$section) {
        if (in_array($section['type'] ?? '', ['hero', 'cta'], true)) {
            $section['phone'] = $phone;
        }
    }
    unset($section);
}

/**
 * Regenerates ONE page's content — the editor's "Regenerate" button. Whole
 * page only, not per-section, per the design doc's explicit "kept simple."
 */
function lpc_generate_page_content($client_id, $page_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found'];

    // Checked before anything else — a locked page must never be overwritten,
    // and checking first (rather than after the AI call) also avoids spending
    // real API cost on a result that would just get discarded.
    $existing = lpc_load_page($client_id, $page_id);
    if ($existing && !empty($existing['locked'])) {
        return ['ok' => false, 'msg' => 'This page is locked. Unlock it before regenerating.'];
    }

    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $target  = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $service_id);
    if (!$target || !$service) return ['ok' => false, 'msg' => 'This page is not a confirmed target city/service.'];
    $hub = lpc_find_by_id($client['hubs'] ?? [], 'hub_id', $target['served_by_hub'] ?? null);

    // Computed before the AI call (not after) so the prompt itself targets
    // whatever keyword this page actually has — a manually-set keyword from
    // a prior edit is preserved, never overwritten by Regenerate.
    $page = $existing ?? lpc_default_page_data();
    if (empty($page['seo']['primary_keyword'])) {
        $page['seo']['primary_keyword'] = lpc_default_primary_keyword($service['label'], $target['city'], $target['state'], $service['keyword_pattern'] ?? '');
    }

    $niche_brief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
    $research    = lpc_get_city_research($city_id);
    $system = lpc_ai_system_prompt($client, $niche_brief);
    $user   = lpc_ai_user_prompt(
        $client['identity']['business_name'], $target['city'], $target['state'], $service['label'],
        $page['seo']['primary_keyword'], $page['seo']['secondary_keywords'] ?? [], $research, $niche_brief
    );

    $result = anthropic_message($user, ['model' => ANTHROPIC_SMART, 'max_tokens' => 6000, 'system' => $system, 'no_thinking' => true]);
    if (!$result['ok']) return ['ok' => false, 'msg' => $result['error']];

    $json = json_decode(lpc_strip_json_fences($result['text']), true);
    if (!is_array($json)) return ['ok' => false, 'msg' => 'AI response was not valid JSON.'];

    $wasPublished = ($page['status'] ?? '') === 'published';

    // Regenerate's own confirm dialog already says "replaces all current
    // section content" — so reset to the CURRENT section schema every time,
    // not whatever shape this page happened to be saved with. Without this,
    // a page generated before a schema change (more sections, new types)
    // could never adopt the new structure via Regenerate — it would just
    // keep refilling its old, smaller section set forever. The one thing
    // that isn't AI-owned and must survive the reset: a manually-set hero
    // image/alt (images come from the per-service system, not the AI).
    $oldHero = null;
    foreach ($page['sections'] as $s) {
        if (($s['type'] ?? '') === 'hero') { $oldHero = $s; break; }
    }
    $page['sections'] = lpc_default_page_data()['sections'];
    if ($oldHero) {
        foreach ($page['sections'] as &$s) {
            if ($s['type'] !== 'hero') continue;
            if (!empty($oldHero['image']))     $s['image']     = $oldHero['image'];
            if (!empty($oldHero['image_alt'])) $s['image_alt'] = $oldHero['image_alt'];
            break;
        }
        unset($s);
    }

    lpc_apply_ai_sections($page, $json);
    lpc_apply_ai_meta($page, $json);
    lpc_fill_real_phone($page, $hub);
    lpc_fill_service_image($page, $client_id, $service, $page['seo']['primary_keyword']);

    $page['page_id']    = $page_id;
    $page['created_at'] = $page['created_at'] ?: gmdate('c');
    $page['updated_at'] = gmdate('c');
    $page['status']     = $wasPublished ? 'needs_update' : 'draft';
    // Primary keyword leads the title tag, per the standing SEO rule — not
    // just "contains" it, first in word order.
    if (!$page['seo']['page_title']) {
        $page['seo']['page_title'] = $page['seo']['primary_keyword'] . ' | ' . $client['identity']['business_name'];
    }

    lpc_save_page($client_id, $page_id, $page);
    return ['ok' => true, 'msg' => ''];
}

/**
 * Generates drafts for every non-excluded, not-yet-existing service page at
 * one target city in a single batched call (anthropic_message_many(), the
 * existing concurrency-controlled batch function) — the "confirming a target
 * city auto-generates draft content for every service immediately" behavior
 * from the original design, without a serial loop reinventing what that
 * function already does.
 */
function lpc_generate_drafts_for_target_city($client_id, $city_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found', 'generated' => 0, 'attempted' => 0];
    $target = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    if (!$target) return ['ok' => false, 'msg' => 'City not found', 'generated' => 0, 'attempted' => 0];

    $hub = lpc_find_by_id($client['hubs'] ?? [], 'hub_id', $target['served_by_hub'] ?? null);
    $niche_brief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
    $research    = lpc_get_city_research($city_id);
    $system = lpc_ai_system_prompt($client, $niche_brief);
    $excluded = $client['content_profile']['excluded_services'] ?? [];

    $prompts = [];
    $keywordsByPageId = [];
    $servicesByPageId = [];
    foreach ($target['services'] ?? [] as $serviceId) {
        if (in_array($serviceId, $excluded, true)) continue;
        $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $serviceId);
        if (!$service) continue;
        $pageId = $city_id . '__' . $serviceId;
        if (lpc_load_page($client_id, $pageId)) continue; // never clobber an already-authored page
        $keyword = lpc_default_primary_keyword($service['label'], $target['city'], $target['state'], $service['keyword_pattern'] ?? '');
        $prompts[$pageId] = lpc_ai_user_prompt(
            $client['identity']['business_name'], $target['city'], $target['state'], $service['label'],
            $keyword, [], $research, $niche_brief
        );
        $keywordsByPageId[$pageId] = $keyword;
        $servicesByPageId[$pageId] = $service;
    }
    if (!$prompts) return ['ok' => true, 'msg' => '', 'generated' => 0, 'attempted' => 0];

    $results = anthropic_message_many($prompts, [
        'model' => ANTHROPIC_SMART, 'max_tokens' => 6000, 'system' => $system,
        'no_thinking' => true, 'concurrency' => 4,
    ]);

    $generated = 0;
    foreach ($results as $pageId => $text) {
        if (!$text || $text === ANTHROPIC_RATE) continue;
        $json = json_decode(lpc_strip_json_fences($text), true);
        if (!is_array($json)) continue;

        $page = lpc_default_page_data();
        lpc_apply_ai_sections($page, $json);
        lpc_apply_ai_meta($page, $json);
        lpc_fill_real_phone($page, $hub);
        lpc_fill_service_image($page, $client_id, $servicesByPageId[$pageId], $keywordsByPageId[$pageId]);
        $page['page_id']    = $pageId;
        $page['created_at'] = gmdate('c');
        $page['updated_at'] = $page['created_at'];
        $page['status']     = 'draft';
        $page['seo']['primary_keyword'] = $keywordsByPageId[$pageId];
        $page['seo']['page_title'] = $keywordsByPageId[$pageId] . ' | ' . $client['identity']['business_name'];
        lpc_save_page($client_id, $pageId, $page);
        $generated++;
    }

    $failed = count($prompts) - $generated;
    $msg = $failed > 0 ? ($generated . ' of ' . count($prompts) . ' generated — ' . $failed . ' failed, try Regenerate on those pages') : '';
    return ['ok' => true, 'msg' => $msg, 'generated' => $generated, 'attempted' => count($prompts)];
}

/** Same as above, for every target city on a client at once — used once, by
 *  the wizard's final "Create" step, right after the client itself is
 *  created (no pages can exist before that point to generate drafts for). */
function lpc_generate_drafts_for_client($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found', 'generated' => 0, 'attempted' => 0];

    $generated = 0;
    $attempted = 0;
    foreach ($client['target_cities'] ?? [] as $target) {
        $r = lpc_generate_drafts_for_target_city($client_id, $target['city_id']);
        $generated += $r['generated'];
        $attempted += $r['attempted'];
    }
    return ['ok' => true, 'msg' => '', 'generated' => $generated, 'attempted' => $attempted];
}
