<?php
/**
 * Phase 3 — AI content generation. Reuses includes/anthropic.php (the one
 * Anthropic client this codebase is supposed to have, per its own
 * "one module per service" rule) directly, rather than shelling out to the
 * niche fleet's generate.py — that script's whole pipeline is built around
 * `content_blocks` (39 flexible block types), a completely different output
 * shape than this feature's 6 fixed section types. The REUSABLE part of the
 * niche fleet's approach is the pattern, not the code: a tone/guardrails
 * system prompt + a `{var}`-filled user prompt + "return JSON only" +
 * defensive markdown-fence stripping (anthropic_message() does not do this
 * automatically, unlike generate.py's call_claude()). That pattern is what's
 * mirrored here, adapted to this feature's own 5 AI-authored section types
 * (related_links stays separately auto-computed, never AI-generated).
 *
 * Content Profile inheritance: when content_profile.source is "niche:X", the
 * matching real niche's own niche_brief.json (business_descriptor,
 * guardrails) is pulled in as the base, with the client's own tone/always
 * -never-mention layered on top — "per-niche-or-client uniqueness," the same
 * pattern already decided in the original design conversation.
 */

function lpc_ai_niche_brief($source) {
    if (strpos((string) $source, 'niche:') !== 0) return null;
    $niche_id = substr($source, 6);
    return lpc_load_niche_profile($niche_id); // same field names (business_descriptor, guardrails) as before
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
    $parts[] = 'Output ONLY valid JSON matching the exact shape requested. No markdown code fences, no commentary before or after.';

    return implode("\n\n", $parts);
}

function lpc_ai_user_prompt($business, $city, $state, $serviceLabel) {
    return "Write landing page copy for {$business}'s {$serviceLabel} service in {$city}, {$state}.\n\n"
        . "Return this exact JSON shape, every field filled with specific copy that names {$city} where natural "
        . "(not generic boilerplate that could apply to any city):\n\n"
        . "{\n"
        . "  \"hero\": {\"headline\": \"...\", \"subheading\": \"...\", \"cta_text\": \"...\"},\n"
        . "  \"text\": {\"heading\": \"...\", \"body\": \"2-4 sentences\"},\n"
        . "  \"services_list\": {\"heading\": \"...\", \"items\": [{\"title\": \"...\", \"text\": \"...\"}, "
        . "{\"title\": \"...\", \"text\": \"...\"}, {\"title\": \"...\", \"text\": \"...\"}]},\n"
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
 *  images, and related_links (always separately auto-computed at publish). */
function lpc_apply_ai_sections(&$page, $aiData) {
    foreach ($page['sections'] as &$section) {
        $type = $section['type'] ?? '';
        if (!isset($aiData[$type]) || !is_array($aiData[$type])) continue;
        $src = $aiData[$type];
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

    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $target  = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $service_id);
    if (!$target || !$service) return ['ok' => false, 'msg' => 'This page is not a confirmed target city/service.'];
    $hub = lpc_find_by_id($client['hubs'] ?? [], 'hub_id', $target['served_by_hub'] ?? null);

    $niche_brief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
    $system = lpc_ai_system_prompt($client, $niche_brief);
    $user   = lpc_ai_user_prompt($client['identity']['business_name'], $target['city'], $target['state'], $service['label']);

    $result = anthropic_message($user, ['model' => ANTHROPIC_SMART, 'max_tokens' => 3000, 'system' => $system, 'no_thinking' => true]);
    if (!$result['ok']) return ['ok' => false, 'msg' => $result['error']];

    $json = json_decode(lpc_strip_json_fences($result['text']), true);
    if (!is_array($json)) return ['ok' => false, 'msg' => 'AI response was not valid JSON.'];

    $page = lpc_load_page($client_id, $page_id) ?? lpc_default_page_data();
    $wasPublished = ($page['status'] ?? '') === 'published';

    lpc_apply_ai_sections($page, $json);
    lpc_fill_real_phone($page, $hub);

    $page['page_id']    = $page_id;
    $page['created_at'] = $page['created_at'] ?: gmdate('c');
    $page['updated_at'] = gmdate('c');
    $page['status']     = $wasPublished ? 'needs_update' : 'draft';
    if (!$page['seo']['page_title']) {
        $page['seo']['page_title'] = $service['label'] . ' in ' . $target['city'] . ', ' . $target['state'] . ' | ' . $client['identity']['business_name'];
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
    $system = lpc_ai_system_prompt($client, $niche_brief);
    $excluded = $client['content_profile']['excluded_services'] ?? [];

    $prompts = [];
    $servicesByPageId = [];
    foreach ($target['services'] ?? [] as $serviceId) {
        if (in_array($serviceId, $excluded, true)) continue;
        $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $serviceId);
        if (!$service) continue;
        $pageId = $city_id . '__' . $serviceId;
        if (lpc_load_page($client_id, $pageId)) continue; // never clobber an already-authored page
        $prompts[$pageId] = lpc_ai_user_prompt($client['identity']['business_name'], $target['city'], $target['state'], $service['label']);
        $servicesByPageId[$pageId] = $service;
    }
    if (!$prompts) return ['ok' => true, 'msg' => '', 'generated' => 0, 'attempted' => 0];

    $results = anthropic_message_many($prompts, [
        'model' => ANTHROPIC_SMART, 'max_tokens' => 3000, 'system' => $system,
        'no_thinking' => true, 'concurrency' => 4,
    ]);

    $generated = 0;
    foreach ($results as $pageId => $text) {
        if (!$text || $text === ANTHROPIC_RATE) continue;
        $json = json_decode(lpc_strip_json_fences($text), true);
        if (!is_array($json)) continue;

        $service = $servicesByPageId[$pageId];
        $page = lpc_default_page_data();
        lpc_apply_ai_sections($page, $json);
        lpc_fill_real_phone($page, $hub);
        $page['page_id']    = $pageId;
        $page['created_at'] = gmdate('c');
        $page['updated_at'] = $page['created_at'];
        $page['status']     = 'draft';
        $page['seo']['page_title'] = $service['label'] . ' in ' . $target['city'] . ', ' . $target['state'] . ' | ' . $client['identity']['business_name'];
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
