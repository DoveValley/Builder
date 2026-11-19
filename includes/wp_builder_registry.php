<?php
/**
 * includes/wp_builder_registry.php — WP Builder's block/profile registry.
 *
 * A "profile" is the full recipe for one kind of site: its input fields, its
 * page types (each an ordered list of blocks), which wp_options to sync, and
 * an optional extra mu-plugin folder for a custom header/footer. "Local
 * Business" (the original 5-page tool) is the first profile, registered from
 * includes/wp_builder_profiles/local_business.php.
 *
 * Blocks are registered per-profile, not shared globally — a block only
 * becomes available to a second profile once it's deliberately reused there
 * (call wpb_register_block() again under the new profile key, pointing at the
 * same render function). Nothing is "promoted to standard" automatically.
 */

/** @return array<string,mixed> by reference — internal, do not call from outside this file. */
function &wpb_registry_store(string $name): array
{
    static $stores = [];
    if (!isset($stores[$name])) {
        $stores[$name] = [];
    }
    return $stores[$name];
}

/**
 * Register a block type for one profile.
 * $render signature: function(array $normalizedData, array $ctx): string
 *   — returns Gutenberg block markup. $ctx carries at least ['photos' => [...], 'page' => $pageKey].
 */
function wpb_register_block(string $profileKey, string $blockKey, callable $render): void
{
    $store = &wpb_registry_store('blocks');
    $store[$profileKey][$blockKey] = $render;
}

function wpb_get_block(string $profileKey, string $blockKey): ?callable
{
    $store = wpb_registry_store('blocks');
    return $store[$profileKey][$blockKey] ?? null;
}

/**
 * Register a profile. $def keys:
 *   label        string — shown in the admin dropdown
 *   description  string — shown under the dropdown when this profile is selected
 *   fields       [fieldKey => ['required'=>bool,'default'=>?,'label'=>,'type'=>'text|textarea|color','hint'=>,'placeholder'=>,'maxlength'=>,'rows'=>]]
 *   photos       [shortKey => ['field'=>$_FILES key,'label'=>,'required_ui'=>bool,'hint'=>]]
 *   tabs         [['label'=>, 'fields'=>[fieldKey or photo shortKey, ...]], ...] — admin form grouping
 *   normalize    callable(array $rawPost): array — returns normalized data used by every block/seo/site_options call
 *   site_title   callable(array $d): string — becomes the WP `blogname` option
 *   page_types   [pageKey => ['slug'=>, 'title'=>, 'blocks'=>[blockKey,...], 'seo'=>callable(array $d): [title, metaDescription]]]
 *   home_page    pageKey used as the WP static front page
 *   site_options callable(array $d): array — option_name => scalar|array (arrays are written with --format=json)
 *   chrome_dir   optional absolute path to an extra mu-plugin folder (custom header/footer), deployed on top of the shared chrome
 */
function wpb_register_profile(string $profileKey, array $def): void
{
    $store = &wpb_registry_store('profiles');
    $store[$profileKey] = $def;
}

function wpb_get_profile(string $profileKey): ?array
{
    $store = wpb_registry_store('profiles');
    return $store[$profileKey] ?? null;
}

/** @return array<string,string> profileKey => label, in registration order — for the admin dropdown. */
function wpb_profiles(): array
{
    $store = wpb_registry_store('profiles');
    $out = [];
    foreach ($store as $key => $def) {
        $out[$key] = $def['label'] ?? $key;
    }
    return $out;
}
