<?php
// Schema-generator prompt library + per-site override storage.
//
// Four "areas" (matching the SEO editor's $context) each get their own default
// prompt. Core pages ('page') additionally split into a few page TYPES, since a
// Contact page, an About page and a legal page need different schema shapes.
//
//  - Defaults live in code (schema_prompt_defaults()) so they improve for every
//    site when we improve them here.
//  - Per-site edits are saved as OVERRIDES to sites/{id}/data/schema_prompts.json
//    (only the keys that differ from the default), so the operator can tune a
//    prompt once and have it persist for all pages of that area on that site.
//
// The effective prompt for a key = override[key] ?? default[key].

if (!function_exists('load_data')) require_once __DIR__ . '/../includes/functions.php';

/**
 * The prompt keys. 'homepage', 'template' (landing) and 'post' (blog) are 1:1
 * with the SEO editor context. Core pages fan out into core_* type presets.
 */
function schema_prompt_keys(): array {
    return [
        'homepage',
        'core_contact', 'core_about', 'core_service', 'core_collection', 'core_general',
        'template',
        'post',
    ];
}

/** Human labels for the core page-type picker (value => label). */
function schema_core_types(): array {
    return [
        'core_general'    => 'General / Legal',
        'core_contact'    => 'Contact',
        'core_about'      => 'About',
        'core_service'    => 'Service / Course',
        'core_collection' => 'Collection / Listing',
    ];
}

/** Map an SEO-editor $context to its prompt key (core resolves to a default type). */
function schema_scope_key(string $context, string $coreType = ''): string {
    if ($context === 'homepage') return 'homepage';
    if ($context === 'template') return 'template';
    if ($context === 'post')     return 'post';
    // 'page' (core): use the supplied type if valid, else the general preset.
    $keys = schema_prompt_keys();
    if ($coreType !== '' && in_array($coreType, $keys, true) && strpos($coreType, 'core_') === 0) return $coreType;
    return 'core_general';
}

/**
 * Shared rules appended (server-side) to every prompt so the operator's editable
 * prompt stays readable while the hard constraints are always enforced.
 */
function schema_prompt_shared_rules(): string {
    return "\n\nHARD RULES (always follow, even if the instructions above are edited):\n"
        . "- Use the shortcodes {website}, {business}, {tel}, {phone}, {address} LITERALLY for all business-identity values — never hardcode them. This schema is reused across many generated sites, so the shortcodes must survive.\n"
        . "- The business node is always referenced by @id \"{website}/#localbusiness\" (defined on the homepage). Other pages reference that @id; they do not redefine the business.\n"
        . "- Never invent addresses, geo coordinates, ratings, review counts, prices, dates, or authors. Omit a property rather than fabricate it.\n"
        . "- Output ONLY a single JSON object with \"@context\": \"https://schema.org\" and a \"@graph\" array. No markdown, no ``` code fences, no commentary before or after.";
}

/** The built-in default prompts, keyed by schema_prompt_keys(). */
function schema_prompt_defaults(): array {
    return [
        'homepage' =>
            "Generate the foundational JSON-LD @graph for this business's HOMEPAGE — the entity definitions every other page will reference. Output three nodes:\n"
          . "1. The business — @type \"LocalBusiness\" (or the most specific subtype that fits the niche; use \"Organization\" only if it is not a local/physical business). @id \"{website}/#localbusiness\". Include name {business}, url {website}, telephone {tel}. Include a postalAddress using {address} ONLY if the context says the site has a real address.\n"
          . "2. A WebSite — @id \"{website}/#website\", name {business}, url {website}, publisher referencing {website}/#localbusiness.\n"
          . "3. A WebPage — @id \"{website}/#webpage\", url {website}, isPartOf {website}/#website, about {website}/#localbusiness.\n"
          . "Do NOT use city shortcodes ({city}, {SS}) — they do not resolve on the homepage.",

        'core_contact' =>
            "Generate JSON-LD for a CONTACT page. Output one ContactPage node (url, name, description) plus a contactPoint (telephone {tel}, contactType \"customer service\"). Reference the business by @id \"{website}/#localbusiness\" rather than redefining it. Use literal (non-city) values.",

        'core_about' =>
            "Generate JSON-LD for an ABOUT page. Output one AboutPage node (url, name, description) whose mainEntity references the business by @id \"{website}/#localbusiness\". Do not redefine the full business — reference the homepage @id. Use literal (non-city) values.",

        'core_service' =>
            "Generate JSON-LD for a SERVICE or COURSE page (a non-city offering page). Choose the most appropriate node: \"Service\" for a service, or \"Course\" for a training course (Course requires name, description, and a provider). Reference the business as provider/provider-of-course by @id \"{website}/#localbusiness\". Use the page title/description from the context. Use literal (non-city) values.",

        'core_collection' =>
            "Generate JSON-LD for a LISTING / HUB page (e.g. \"All Services\", \"All Courses\", \"Locations\"). Output a CollectionPage node, and include an ItemList only if the listed item names/URLs are provided in the context. Reference the business by @id \"{website}/#localbusiness\". Use literal (non-city) values.",

        'core_general' =>
            "Generate MINIMAL JSON-LD for a GENERAL / LEGAL page (e.g. Privacy Policy, Terms). Output a single WebPage node (url, name, description, isPartOf \"{website}/#website\"). Keep it minimal — these pages have no rich-result value. Use literal (non-city) values.",

        'template' =>
            "Generate JSON-LD for a CITY LANDING PAGE targeting the service given in the context. Output ONE Service node:\n"
          . "- @type \"Service\"; serviceType = the service; name includes the service and \"{city_state}\"; a 1-2 sentence description.\n"
          . "- areaServed: {\"@type\": \"City\", \"name\": \"{city}\"}.\n"
          . "- provider: {\"@type\": \"LocalBusiness\", \"@id\": \"{website}/#localbusiness\", \"name\": \"{business}\", \"telephone\": \"{tel}\", \"url\": \"{website}\"}.\n"
          . "- url built from {website} and the slug in the context (use {city_slug}), with a trailing slash.\n"
          . "Use {city}, {SS}, {city_state}, {city_slug} LITERALLY — they resolve per generated city. Do NOT include a FAQPage node — it is injected automatically from the page's FAQ block.",

        'post' =>
            "Generate JSON-LD for a BLOG POST. Output one BlogPosting node: headline (the post title from context), description (the excerpt), image (the featured image URL if provided), datePublished/dateModified if provided, author (Person or Organization) if provided, mainEntityOfPage referencing the post URL, and publisher referencing the business by @id \"{website}/#localbusiness\". Use literal values from the context — no city shortcodes. Omit any field not provided in the context.",
    ];
}

/** Path to this site's override file. */
function schema_prompts_file(): string {
    $dir = defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR !== '' ? ACTIVE_SITE_DIR : BASE_DIR;
    return $dir . '/data/schema_prompts.json';
}

/** Load saved overrides (key => prompt). Returns [] if none / unreadable. */
function schema_prompts_overrides(): array {
    $f = schema_prompts_file();
    if (!is_file($f)) return [];
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

/** Effective prompts (defaults with overrides merged on top). */
function schema_prompts_effective(): array {
    return array_merge(schema_prompt_defaults(), schema_prompts_overrides());
}

/** Effective prompt for one key (override ?? default ?? ''). */
function schema_prompt_get(string $key): string {
    $eff = schema_prompts_effective();
    return $eff[$key] ?? '';
}

/**
 * Save one override. Blank/identical-to-default prompt deletes the override
 * (so "reset to default" is just saving blank). Returns true on success.
 */
function schema_prompt_save_override(string $key, string $prompt): bool {
    if (!in_array($key, schema_prompt_keys(), true)) return false;
    $overrides = schema_prompts_overrides();
    $defaults  = schema_prompt_defaults();
    $prompt    = trim($prompt);
    if ($prompt === '' || $prompt === trim($defaults[$key] ?? '')) {
        unset($overrides[$key]);           // reset to default
    } else {
        $overrides[$key] = $prompt;
    }
    $f   = schema_prompts_file();
    $dir = dirname($f);
    if (!is_dir($dir)) return false;
    $tmp = $f . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) return false;
    return rename($tmp, $f);
}
