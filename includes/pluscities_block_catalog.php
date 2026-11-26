<?php
/**
 * Every block type a City Page can be built from, and its real classification
 * (Editable / Automatic / Client-supplied) — verified by reading
 * includes/pluscities_lifecycle.php's pluscities_create_page() (the actual
 * per-block-type branching, not the spec's prose) plus the matching writer
 * functions in includes/pluscities_writer.php and the fact-topic expiry table
 * in includes/pluscities_research.php, 2026-11-22. Two real spec-vs-code
 * disagreements found this way are flagged inline in their own row rather
 * than silently resolved one way or the other — see 'note' on climate_data
 * and reviews_from_city below.
 *
 * Factored out of admin/platform_info.php (2026-11-22) so the real agency-
 * facing City Service Page picker (admin/client.php) and this internal
 * read-only documentation page read from one source instead of two copies
 * that can drift apart — the exact anti-pattern this whole file's own
 * surrounding project exists to avoid, applied to itself. Added 'slug' (the
 * real block_type string PlusCities/the niche pool actually use — confirmed
 * against a real niche family file's default_city_blocks/
 * default_service_blocks, not guessed) and 'class_label' (plain-language
 * translation of 'class' for the agency-facing picker, which must not
 * surface internal dev vocabulary like "editable"/"automatic"/"client-
 * supplied" directly — Scott's call, 2026-11-22) to every row, on top of the
 * original internal fields this function already had.
 */
function pluscities_block_class_label(string $class): string {
    switch ($class) {
        case 'editable':  return 'AI-written';
        case 'automatic': return 'Pulled from real data';
        case 'client':    return 'Your own content';
        default:          return $class;
    }
}

function platform_info_city_page_blocks_data(): array {
    return [
        [
            'title' => 'Spine — always present, 4 of 4, never swapped',
            'rows' => [
                ['block' => 'Hero', 'slug' => 'hero', 'class' => 'editable',
                 'what' => "The top banner: business name/photo and the service + city headline with a call-to-action button.",
                 'how' => "AI-written from the client's own profile facts only (name, phone, hub location) — nothing in it is a checkable claim, so no outside citation is needed.",
                 'note' => ''],
                ['block' => '"[Service] in [City]" overview', 'slug' => 'overview', 'class' => 'editable',
                 'what' => "A short intro paragraph tying the specific service to the specific city, linking back to the client's main service page.",
                 'how' => 'Same generic AI-written path as Hero — drafted from client profile facts.',
                 'note' => ''],
                ['block' => 'FAQ', 'slug' => 'faq', 'class' => 'editable',
                 'what' => 'Question/answer pairs addressing what a prospective customer would actually ask, rendered as an accordion.',
                 'how' => "Written from a real researched fact card (the city's \"permits\" research topic), not generic knowledge.",
                 'note' => 'One of the 12 validator checks is FAQ completeness.'],
                ['block' => 'Service area + links', 'slug' => 'service_area', 'class' => 'automatic',
                 'what' => "A list of nearby cities / related service links for that hub, so visitors (and Google) can find a client's other pages.",
                 'how' => "Built from a template using the client record + niche — no AI call at all, so there's nothing to \"regenerate\" beyond re-running the template.",
                 'note' => 'Rendered as an accent-colored band with pill-shaped links (2026-11-22 visual upgrade).'],
            ],
        ],
        [
            'title' => 'City pool — pick 5 of 9, or 6 of 9',
            'rows' => [
                ['block' => 'Climate data', 'slug' => 'climate_data', 'class' => 'editable',
                 'what' => "The city's climate/weather patterns relevant to the service (e.g. humidity driving mold, freeze-thaw driving plumbing issues).",
                 'how' => 'AI-written from a researched "climate_data" fact card, cached 60 months since climate normals barely move year to year.',
                 'note' => '⚠ Spec/code disagree: the written spec\'s own worked example classifies this block Automatic, pulled from a NOAA dataset. No NOAA dataset provider is actually wired up for PlusCities — the shipped code treats it as a normal fact-card-grounded Editable block instead.'],
                ['block' => 'Housing stock', 'slug' => 'housing_stock', 'class' => 'automatic',
                 'what' => "Real stats about the city's homes — age, size, value — relevant to the service.",
                 'how' => 'Pulled directly from Census ACS data via the shared dataset fetcher; carries a "Source: US Census ACS 5-Year Estimates" line. Only its intro, not the data, is ever rewritten.',
                 'note' => ''],
                ['block' => 'Neighborhoods guide', 'slug' => 'neighborhoods', 'class' => 'editable',
                 'what' => "A rundown of the city's notable neighborhoods/areas relevant to the service.",
                 'how' => 'AI-written from a researched "neighborhoods" fact card, refreshed every 24 months.',
                 'note' => ''],
                ['block' => 'Permits / local rules', 'slug' => 'permits', 'class' => 'editable',
                 'what' => 'Local permitting or regulatory info relevant to the service in that city.',
                 'how' => 'AI-written from a researched "permits" fact card, refreshed every 6 months — the fastest-expiring topic, since rules change most often.',
                 'note' => 'Same fact topic the FAQ block also draws on.'],
                ['block' => 'Utilities', 'slug' => 'utilities', 'class' => 'editable',
                 'what' => "Info on the city's utility providers/setup relevant to the service.",
                 'how' => 'AI-written from a researched "utilities" fact card, refreshed every 12 months.',
                 'note' => ''],
                ['block' => 'HOA rules', 'slug' => 'hoa_rules', 'class' => 'editable',
                 'what' => 'Homeowner-association rules relevant to the service in that city.',
                 'how' => 'Meant to draw on a dedicated "hoa" fact topic (12-month refresh).',
                 'note' => '⚠ A real slug-mismatch bug: the generic fact lookup uses the block\'s own type name as the topic key, which doesn\'t actually match "hoa" — flagged here, not yet fixed. Doesn\'t change the classification, just the fact it draws on.'],
                ['block' => 'Seasonal guide', 'slug' => 'seasonal_guide', 'class' => 'editable',
                 'what' => 'Season-by-season advice relevant to the service in that city.',
                 'how' => 'Meant to draw on a dedicated "seasons" fact topic (24-month refresh).',
                 'note' => 'Same slug-mismatch caveat as HOA rules above.'],
                ['block' => 'Local hazards', 'slug' => 'hazards', 'class' => 'editable',
                 'what' => 'Real local risk factors relevant to the service (flooding, wildfire, etc.).',
                 'how' => 'AI-written from a researched "hazards" fact card, refreshed every 12 months.',
                 'note' => ''],
                ['block' => 'Reviews from city', 'slug' => 'reviews_from_city', 'class' => 'client',
                 'what' => 'Real reviews the client has actually received from customers in that specific city.',
                 'how' => "Pulled directly from the client's own stored reviews for that city — never touches the AI writer at all.",
                 'note' => '⚠ Spec/code disagree: the spec\'s worked example shows this block locked by default (so a real review can\'t be accidentally overwritten). The shipped code does NOT lock it by default.'],
            ],
        ],
        [
            'title' => 'Service pool — pick 3 of 9, or 4 of 9',
            'rows' => [
                ['block' => 'Common problems', 'slug' => 'common_problems', 'class' => 'editable',
                 'what' => 'The typical problems customers in this service line actually run into.',
                 'how' => 'AI-written from service-level research.', 'note' => ''],
                ['block' => 'Cited expert guidance', 'slug' => 'cited_expert_guidance', 'class' => 'editable',
                 'what' => 'Advice attributed to a real, citable expert source.',
                 'how' => 'AI-written, grounded in a real cited source rather than general knowledge.',
                 'note' => 'One of the places the "misquoted sources" validator check matters most.'],
                ['block' => 'Niche data table', 'slug' => 'niche_data_table', 'class' => 'automatic',
                 'what' => 'A styled data table relevant to the niche (e.g. typical repair costs, timelines).',
                 'how' => "Not AI-written at all — it's the niche's own pre-authored table, shared across every client and city in that niche.",
                 'note' => 'The one block type that needed real new render code (a styled table), not just the generic text pipeline.'],
                ['block' => 'Process', 'slug' => 'process', 'class' => 'editable',
                 'what' => 'A step-by-step explanation of how the service is actually performed.',
                 'how' => 'AI-written from service-level research.', 'note' => ''],
                ['block' => 'Cost factors', 'slug' => 'cost_factors', 'class' => 'editable',
                 'what' => "What drives price up or down for this service — not a specific quote.",
                 'how' => "AI is explicitly restricted to the client's own supplied price ranges and qualitative language — never allowed to invent a dollar figure.",
                 'note' => 'Checked by the "invented prices" validator rule.'],
                ['block' => 'Why choose us', 'slug' => 'why_choose_us', 'class' => 'editable',
                 'what' => "The client's own differentiators / value proposition for this service.",
                 'how' => "AI-written from the client's own profile facts.", 'note' => ''],
                ['block' => 'Repair vs. replace', 'slug' => 'repair_vs_replace', 'class' => 'editable',
                 'what' => "Guidance on when to repair vs. fully replace, for services where that's a real decision.",
                 'how' => 'AI-written from service-level research.', 'note' => ''],
                ['block' => 'Maintenance', 'slug' => 'maintenance', 'class' => 'editable',
                 'what' => 'Upkeep/maintenance tips relevant to the service.',
                 'how' => 'AI-written from service-level research.', 'note' => ''],
                ['block' => 'Before the tech arrives', 'slug' => 'before_the_tech_arrives', 'class' => 'editable',
                 'what' => 'What a customer should do/expect before the appointment.',
                 'how' => 'AI-written from service-level research.', 'note' => ''],
            ],
        ],
    ];
}
