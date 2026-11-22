<?php
/**
 * Bridges the City Service Page picker (admin/client.php) into real content:
 * takes a client's approved city-pool/service-pool picks for one page and
 * produces real, grounded content for them, reusing PlusCities' proven
 * writer functions (includes/pluscities_writer.php) and fact-card research
 * (includes/pluscities_research.php) — without touching PlusCities' own
 * client-file or `pages`-table orchestration (pluscities_create_page() /
 * pluscities_regenerate_block()), which hard-require a SEPARATE client
 * record (pluscities_clients/{id}.json) that can — and in production
 * already has, for the real Meade Restoration test client — drift from
 * LPC's actual client.json. Building a sync layer to keep two client
 * records aligned would be exactly the duplicated-state problem this
 * project exists to avoid elsewhere; investigating showed it's unnecessary,
 * since the only things those two functions pull from the separate client
 * file are a few trivial facts (business name, phone, licenses, years in
 * business, price ranges) that LPC's own client.json either already has or
 * can simply omit (the writer already treats a missing fact as "not
 * supplied, stay generic," not an error) — and the actually valuable part,
 * fact-card research, is scoped by city + topic, never by client at all.
 *
 * This file's functions still use PlusCities' real `blocks` SQL table (same
 * schema, same real persistent per-block identity for the future per-block-
 * lock phase) — just keyed by LPC's own existing page_id
 * (`{city_id}__{service_id}`), with no corresponding row in PlusCities'
 * `pages` table at all (that table's slug/url_base_path machinery belongs to
 * a separate PlusCities-native publishing product LPC doesn't use).
 *
 * Scope: ONLY the city-pool/service-pool picks. The spine (hero, overview,
 * FAQ, service_area) stays entirely on LPC's existing single-AI-call path
 * (lpc_ai_user_prompt()) — it's always-on, already proven, and rerouting it
 * through here would mean a second AI call for zero behavioral benefit.
 */

require_once __DIR__ . '/pluscities_block_catalog.php';

/** Lazily-built slug -> internal classification ('editable'/'automatic'/
 *  'client') map, read from the one shared catalog (includes/
 *  pluscities_block_catalog.php) so this never drifts from the agency-facing
 *  picker's own data. */
function lpc_pluscities_block_classification($block_type) {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (platform_info_city_page_blocks_data() as $section) {
            foreach ($section['rows'] as $row) {
                if (!empty($row['slug'])) $map[$row['slug']] = $row['class'];
            }
        }
    }
    return $map[$block_type] ?? 'editable';
}

/**
 * The real fact-card topic for a block type — matches block_type by
 * convention EXCEPT two known slug-mismatch bugs already flagged in
 * admin/platform_info.php's own catalog notes (hoa_rules/seasonal_guide
 * don't match their real topics 'hoa'/'seasons'). Fixed here rather than in
 * PlusCities' own pluscities_regenerate_block() since this bridge doesn't
 * call that function at all — fixing it in the shared PlusCities code is a
 * separate, out-of-scope change that would also need to touch its existing
 * callers; this just means the NEW pathway doesn't repeat a known bug for
 * free, while the old pathway's bug (unrelated to LPC) is untouched.
 */
function lpc_pluscities_fact_topic($block_type) {
    $fixes = ['hoa_rules' => 'hoa', 'seasonal_guide' => 'seasons'];
    return $fixes[$block_type] ?? $block_type;
}

/**
 * Inserts any `blocks` rows this page_id doesn't already have for the given
 * picks — existing rows (from a prior generate) are left untouched here;
 * lpc_regenerate_picked_block() is what actually (re)writes their content.
 * $picks is ['city' => [...slugs], 'service' => [...slugs]].
 */
function lpc_ensure_picked_blocks($page_id, $picks, $niche) {
    $db = pluscities_db();
    $existing = $db->prepare('SELECT block_type FROM blocks WHERE page_id = ?');
    $existing->execute([$page_id]);
    $have = $existing->fetchAll(PDO::FETCH_COLUMN);

    $ins = $db->prepare(
        'INSERT INTO blocks (page_id, block_type, position, pool, classification, content_json, fact_ids, dataset_refs, locked, origin)
         VALUES (?,?,?,?,?,?,?,?,0,"")'
    );
    $position = 0;
    foreach (['city', 'service'] as $pool) {
        foreach ($picks[$pool] ?? [] as $blockType) {
            $position++;
            if (in_array($blockType, $have, true)) continue;
            $class = lpc_pluscities_block_classification($blockType);
            $datasetRefs = ($blockType === 'housing_stock')
                ? json_encode([['city_id' => '', 'provider' => 'census_acs']]) // city_id filled at insert time below
                : '[]';
            $ins->execute([
                $page_id, $blockType, $position, $pool,
                $class === 'client' ? 'client-supplied' : $class,
                '', '[]', $datasetRefs,
            ]);
        }
    }
}

/**
 * Regenerates ONE block row's content in place. Mirrors
 * pluscities_regenerate_block()'s dispatch (block_type/classification ->
 * the matching writer function) but builds $context/$clientFacts straight
 * from LPC's own in-memory $client array — never a second file read.
 * Returns ['ok' => bool, 'msg' => string].
 */
function lpc_regenerate_picked_block($block, $client, $niche, $city_id, $service_label, $actor) {
    $db = pluscities_db();
    if ((int) ($block['locked'] ?? 0) === 1) {
        return ['ok' => true, 'msg' => 'skipped (locked)'];
    }

    $context = [
        'city_label'    => $city_id,
        'service_label' => $service_label,
        'business_name' => $client['identity']['business_name'] ?? '',
    ];
    $clientFacts = pluscities_client_facts_for_writer($client);
    $blockType = $block['block_type'];

    if ($blockType === 'reviews_from_city') {
        // LPC's client schema has no stored-reviews field today (unlike
        // PlusCities' own client record, which carries reviews_by_city) —
        // nothing invented, nothing to regenerate yet. Graceful no-op, not
        // an error: the block row still exists (future-compatible once a
        // reviews data entry point exists), it just renders nothing until
        // real reviews are actually stored somewhere for this client.
        return ['ok' => true, 'msg' => 'skipped (no reviews data on this client yet)'];
    }

    if ($blockType === 'niche_data_table') {
        // Same graceful-skip reasoning as reviews_from_city above: this
        // table is the NICHE's own pre-authored content, and real blueprints
        // only exist for 2 of LPC's 7 real niches today. No niche data is a
        // real, expected state right now, not an error to surface as one.
        if (empty($niche)) {
            return ['ok' => true, 'msg' => 'skipped (no niche data table authored for this niche yet)'];
        }
        $result = pluscities_write_niche_data_table_block($niche);
        if (!empty($result['ok'])) {
            $db->prepare('UPDATE blocks SET content_json = ?, origin = "data" WHERE id = ?')
               ->execute([json_encode($result['content']), $block['id']]);
        }
        return ['ok' => !empty($result['ok']), 'msg' => $result['msg'] ?? ''];
    }

    if (lpc_pluscities_block_classification($blockType) === 'automatic') {
        // housing_stock today — real Census data, city-scoped, not client-
        // scoped, so no client file is needed for this lookup either.
        $dataset = pluscities_dataset_get($city_id, 'census_acs');
        $result  = pluscities_write_automatic_block($blockType, $dataset ?? [], $context);
        if (!empty($result['ok'] ?? true)) {
            $db->prepare('UPDATE blocks SET content_json = ?, origin = "data" WHERE id = ?')
               ->execute([json_encode($result['content']), $block['id']]);
        }
        return ['ok' => true, 'msg' => ''];
    }

    // Generic editable — the 14 city/service-pool types with no bespoke
    // writer, same path hero/overview/permits/etc. already use.
    $facts  = pluscities_fact_card_get_active('city', $city_id, lpc_pluscities_fact_topic($blockType));
    $result = pluscities_write_editable_block($blockType, $blockType, $facts, $clientFacts, $context);
    if (empty($result['ok'])) {
        return ['ok' => false, 'msg' => $result['msg'] ?? 'Generation failed'];
    }
    $db->prepare('UPDATE blocks SET content_json = ?, fact_ids = ?, origin = "generated", client_grounded = 1 WHERE id = ?')
       ->execute([json_encode($result['content']), json_encode($result['fact_ids'] ?? []), $block['id']]);
    pluscities_log_event($actor, 'regenerate_block', 'block:' . $block['id'], 'via LPC bridge');
    return ['ok' => true, 'msg' => ''];
}

/**
 * Top-level entry point lpc_generate_page_content() calls: ensures blocks
 * exist for the resolved picks, regenerates each, and returns the LPC
 * section array to splice onto the page — plus any per-block failures so
 * the caller can surface a clear partial-failure message instead of
 * silently dropping content.
 */
function lpc_sync_and_generate_picked_blocks($client, $page_id, $city_id, $service_label, $niche, $picks, $actor) {
    lpc_ensure_picked_blocks($page_id, $picks, $niche);

    $db = pluscities_db();
    $rows = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $rows->execute([$page_id]);
    $blocks = $rows->fetchAll(PDO::FETCH_ASSOC);

    $errors = [];
    foreach ($blocks as $block) {
        $result = lpc_regenerate_picked_block($block, $client, $niche, $city_id, $service_label, $actor);
        if (!$result['ok']) $errors[] = $block['block_type'] . ': ' . $result['msg'];
    }

    return ['sections' => lpc_build_picked_sections_from_pluscities($page_id), 'errors' => $errors];
}

/**
 * Converts a page's current `blocks` rows into LPC section array entries.
 * Per the Phase 1 adapter finding: PlusCities' generic editable writer only
 * ever produces flat {heading, body} — never an item list or two-column
 * structure — so every generic pick renders as LPC's plain `text` type here,
 * not the richer highlights/steps/comparison shapes a first pass at this
 * mapping assumed. niche_data_table/housing_stock/reviews_from_city get
 * their real dedicated presentations (styled table, stat cards, carousel) in
 * a later phase; for now they flatten into the same plain `text` shape too,
 * so they're selectable and functional today, just plainer.
 */
/**
 * Flattens one block's stored content_json into a plain {heading, body} pair
 * — the one place this shaping logic lives, shared by the real bridge
 * (below) and the Phase 2 preview (lpc_build_preview_sections()) so they
 * can never drift into showing different text for the same stored content.
 */
function lpc_pluscities_flatten_content($blockType, $content) {
    $heading = $content['heading'] ?? ucwords(str_replace('_', ' ', $blockType));

    if ($blockType === 'niche_data_table' && !empty($content['rows'])) {
        // Flattened list-of-rows summary for Phase 1 — a real styled
        // <table> is Phase 3 scope (confirmed: no live renderer case for
        // it exists yet, per the Phase 1 plan's adapter mapping).
        $lines = array_map(fn($r) => implode(' — ', (array) $r), $content['rows']);
        return ['heading' => $content['title'] ?? $heading, 'body' => implode("\n", $lines)];
    }
    if ($blockType === 'housing_stock' && isset($content['dataset_values'])) {
        $lines = [];
        foreach ((array) $content['dataset_values'] as $label => $value) {
            $lines[] = ucwords(str_replace('_', ' ', (string) $label)) . ': ' . $value;
        }
        return ['heading' => $heading, 'body' => trim(($content['intro'] ?? '') . "\n" . implode("\n", $lines))];
    }

    // Generic editable shape — the common case for 14 of the 18
    // city/service-pool types, a direct field copy.
    return ['heading' => $heading, 'body' => $content['body'] ?? ''];
}

function lpc_build_picked_sections_from_pluscities($page_id) {
    $db = pluscities_db();
    $rows = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $rows->execute([$page_id]);

    $sections = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $block) {
        $content = json_decode($block['content_json'] ?? '', true);
        if (!is_array($content)) continue; // not yet generated, or the graceful reviews_from_city no-op

        $flat = lpc_pluscities_flatten_content($block['block_type'], $content);
        $sections[] = ['type' => 'text', 'key' => $block['block_type'], 'heading' => $flat['heading'], 'body' => $flat['body']];
    }
    return $sections;
}

/**
 * Phase 2 — builds a preview of what a CURRENTLY-CHECKED (possibly unsaved)
 * set of picks would look like, for the City Service Page tab's live preview
 * pane. Deliberately reuses lpc_preview_render() (includes/landing_page_
 * clients_publish.php) unchanged rather than writing new rendering logic —
 * it already handles the exact `text` shape every picked topic produces
 * (confirmed against lpc_build_picked_sections_from_pluscities() above), is
 * WordPress-independent, and is the same function page_editor.php's own
 * preview and admin/preview_site.php already use, so there's no second
 * rendering implementation to drift out of sync.
 *
 * Shows real content when this client already has a generated page with
 * that topic's real block content; otherwise a clearly-labeled placeholder
 * built from the shared catalog, so a brand-new client's preview is still
 * informative rather than blank. No AI call, no external HTTP, no cost —
 * this only ever reads what's already in the `blocks` table or renders
 * static catalog text.
 */
function lpc_build_preview_sections($client_id, $cityPicks, $servicePicks) {
    $cspCatalog = [];
    foreach (platform_info_city_page_blocks_data() as $cspCatSection) {
        foreach ($cspCatSection['rows'] as $cspCatRow) {
            if (!empty($cspCatRow['slug'])) $cspCatalog[$cspCatRow['slug']] = $cspCatRow;
        }
    }

    // Real content for a given topic can live on ANY of this client's pages
    // (lpc_list_pages() sorts alphabetically, so "just check the first one"
    // would mean arbitrarily missing real content that exists elsewhere —
    // confirmed live: Meade's alphabetically-first page has no generated
    // bridge content at all, a different one does). One query across every
    // real page_id rather than N round trips; whichever page's row is read
    // last for a given block_type wins, which is fine since this is "an
    // example of this topic," not a claim about any specific city.
    $realBlocksByType = [];
    $pages = lpc_list_pages($client_id);
    if ($pages) {
        $db = pluscities_db();
        $placeholders = implode(',', array_fill(0, count($pages), '?'));
        $rows = $db->prepare("SELECT block_type, content_json FROM blocks WHERE page_id IN ($placeholders) AND content_json != ''");
        $rows->execute($pages);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $content = json_decode($row['content_json'] ?? '', true);
            if (is_array($content) && !empty($content)) $realBlocksByType[$row['block_type']] = $content;
        }
    }

    $sections = [];
    foreach (array_merge((array) $cityPicks, (array) $servicePicks) as $blockType) {
        $catalogRow = $cspCatalog[$blockType] ?? null;
        $displayName = $catalogRow['block'] ?? ucwords(str_replace('_', ' ', $blockType));

        if (isset($realBlocksByType[$blockType])) {
            // Same flattening lpc_build_picked_sections_from_pluscities()
            // uses for the real page — so housing_stock/niche_data_table
            // show the same labeled format here as on the live page, not a
            // cruder preview-only version.
            $flat = lpc_pluscities_flatten_content($blockType, $realBlocksByType[$blockType]);
            $sections[] = ['type' => 'text', 'heading' => $flat['heading'] ?: $displayName, 'body' => $flat['body']];
            continue;
        }

        // No real content yet (brand-new client, or this topic hasn't been
        // generated for the representative page) — a clearly-labeled
        // example, not invented real-looking copy.
        $sections[] = [
            'type' => 'text',
            'heading' => $displayName,
            'body' => '(Example — real content appears here once you run Generate/Refresh on the Cities & Services tab.) '
                . ($catalogRow['what'] ?? ''),
        ];
    }
    return $sections;
}
