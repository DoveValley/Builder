<?php
/**
 * includes/pluscities_lifecycle.php — the page/content lifecycle from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 8: one function per verb.
 *
 * status tracks the highest stage ever reached (draft|approved|live|
 * unpublished) and never regresses on its own. approval_stage is the
 * separate, resettable axis — any block mutation invalidates the approval
 * already granted against the old draft_hash, per Section 8's "editing an
 * already-live page follows the exact same rules as a brand-new page" rule,
 * so it resets to 'none' on every edit/regenerate/swap/rollback, requiring
 * Validate -> Approve -> Publish again regardless of how many times before.
 */

/**
 * Which pluscities_clients/*.json ids the current actor may see — Platform
 * Owner sees all, Agency Admin/Editor see only their own agency's. Shared by
 * admin/pluscities_pages.php (listing) and admin/pluscities_pages_save.php
 * (scope-checking a bulk action), so the rule only lives once.
 *
 * @return array client_id => client record
 */
function pluscities_client_ids_for_current_actor(): array
{
    $role     = lpc_current_actor_role();
    $agencyId = lpc_current_actor_agency_id();
    $ids = [];
    foreach (glob(PLUSCITIES_CLIENTS_DIR . '*.json') as $f) {
        $c = json_decode((string) file_get_contents($f), true);
        if (!$c) continue;
        if ($role !== 'super_admin' && ($c['agency_id'] ?? '') !== $agencyId) continue;
        $ids[$c['client_id']] = $c;
    }
    return $ids;
}

/**
 * Whether the current actor may view/act on a given client at all — beyond
 * pluscities_client_ids_for_current_actor()'s agency-level scoping, an
 * Agency Editor with a non-empty assigned_client_ids list is further
 * restricted to just those. Called at a single choke point in
 * admin/pluscities_page.php and admin/pluscities_page_save.php rather than
 * per-action, since every action there already carries page_id (and
 * therefore client_id) regardless of which specific verb it is.
 */
function pluscities_actor_can_access_client(string $client_id): bool
{
    $allowed = pluscities_client_ids_for_current_actor();
    if (!isset($allowed[$client_id])) return false;

    if (lpc_current_actor_role() === 'agency_editor') {
        $userId = $_SESSION['pluscities_user_id'] ?? '';
        $user = $userId ? pluscities_user_load($userId) : null;
        if ($user && !empty($user['assigned_client_ids']) && !in_array($client_id, $user['assigned_client_ids'], true)) {
            return false;
        }
    }
    return true;
}

/** Full service-pool list, spec Section 5 — one shared set for every niche
 *  (a process, cost factors, maintenance tips, etc. apply to any local
 *  service business), unlike the city pool which varies per family. */
const PLUSCITIES_SERVICE_POOL = [
    'common_problems', 'cited_expert_guidance', 'niche_data_table', 'process',
    'cost_factors', 'why_choose_us', 'repair_vs_replace', 'maintenance', 'before_the_tech_arrives',
];

/**
 * Validates a caller's raw city/service block picks against a niche's real
 * pools and caps each at the real count limit (5-6 of 9 city, 3-4 of 9
 * service — here enforced as the upper bound, 6/4, same "agency picks the
 * approved pool" convention the admin picker UI uses). Shared by every real
 * caller that takes human-submitted picks (client creation's first page,
 * the Add Page screen) so the whitelist-and-cap logic lives in exactly one
 * place, not copy-pasted per form. Silently drops anything not in the pool
 * rather than erroring — a stale/crafted pick is simply not honored, same
 * "never trust client input blindly" rule every other save handler in this
 * codebase follows.
 */
function pluscities_validate_block_picks(array $niche, array $cityPicksRaw, array $servicePicksRaw): array
{
    $cityPool    = $niche['city_blocks'] ?? [];
    $servicePool = PLUSCITIES_SERVICE_POOL;

    $cityPicks    = array_values(array_intersect($cityPicksRaw, $cityPool));
    $servicePicks = array_values(array_intersect($servicePicksRaw, $servicePool));

    return [
        'city'    => array_slice($cityPicks, 0, min(6, count($cityPool))),
        'service' => array_slice($servicePicks, 0, min(4, count($servicePool))),
    ];
}

/**
 * Creates a new page with its full real block set — the 4 spine blocks
 * (always present) plus the caller's chosen city/service blocks — and
 * generates real content for every one via the same pluscities_regenerate_block()
 * every edit already uses, so there's no second, parallel content-generation
 * path to keep in sync.
 *
 * $city_block_picks/$service_block_picks are explicit choices, not
 * auto-selected: the real selection rules (spec Section 5 — city blocks
 * ≥50% of body words, ≤50% service-block overlap across a client's cities,
 * varied order) aren't built yet (same gap pluscities_swap_block()'s own
 * comment already flags), so this takes a caller's picks rather than
 * pretending to apply rules that don't exist yet.
 *
 * @return array{ok:bool,msg:string,page_id:?string}
 */
function pluscities_create_page(string $client_id, string $city_id, string $service_key, array $city_block_picks, array $service_block_picks, string $actor): array
{
    $clientFile = PLUSCITIES_CLIENTS_DIR . $client_id . '.json';
    $client = json_decode((string) @file_get_contents($clientFile), true);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found.', 'page_id' => null];
    $niche = pluscities_niche_resolved($client['niche_id'] ?? '');
    if (!$niche) return ['ok' => false, 'msg' => 'Niche not found.', 'page_id' => null];

    foreach ($city_block_picks as $bt) {
        if (!in_array($bt, $niche['city_blocks'] ?? [], true)) {
            return ['ok' => false, 'msg' => "\"$bt\" is not in this niche's city block pool.", 'page_id' => null];
        }
    }
    foreach ($service_block_picks as $bt) {
        if (!in_array($bt, PLUSCITIES_SERVICE_POOL, true)) {
            return ['ok' => false, 'msg' => "\"$bt\" is not a known service block type.", 'page_id' => null];
        }
    }

    $pageId   = 'pg_' . str_replace('-', '', $client_id) . '_' . $city_id . '_' . $service_key;
    $basePath = '/' . trim($client['url_base_path'] ?? 'service-area', '/') . '/';
    $slug     = $basePath . $city_id . '/' . $service_key . '/';

    $db = pluscities_db();
    $existing = $db->prepare('SELECT 1 FROM pages WHERE page_id = ?');
    $existing->execute([$pageId]);
    if ($existing->fetchColumn()) return ['ok' => false, 'msg' => 'This page already exists.', 'page_id' => null];

    $db->prepare('INSERT INTO pages (page_id, client_id, city_id, service_key, slug, status, approval_stage) VALUES (?,?,?,?,?,?,?)')
       ->execute([$pageId, $client_id, $city_id, $service_key, $slug, 'draft', 'none']);

    $ins = $db->prepare('INSERT INTO blocks (page_id, block_type, position, pool, classification, content_json, fact_ids, dataset_refs, locked, origin) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $position  = 0;
    $blockIds  = [];

    // Spine splits front/back — "faq"/"service_area" are a spine concept
    // (always present) but render LAST, after every city/service block, per
    // the design doc's rhythm rule #1 (hero, overview first; FAQ and service
    // area + links last) and matching the fleet's own reference sites
    // (Bailey/Wren/Gann), which answer objections and confirm service area
    // right before the final CTA, not immediately after the opener.
    foreach (['hero', 'overview'] as $bt) {
        $ins->execute([$pageId, $bt, $position++, 'spine', 'editable', '{}', '[]', '[]', 0, 'generated']);
        $blockIds[] = (int) $db->lastInsertId();
    }
    foreach ($city_block_picks as $bt) {
        if ($bt === 'reviews_from_city') {
            // Client-supplied — content is the client's own reviews for this
            // city, never through the AI writer at all.
            $content = ['reviews' => $client['reviews_by_city'][$city_id] ?? []];
            $ins->execute([$pageId, $bt, $position++, 'city', 'client-supplied', json_encode($content), '[]', '[]', 0, 'client']);
            continue;
        }
        $classification = $bt === 'housing_stock' ? 'automatic' : 'editable';
        $ins->execute([$pageId, $bt, $position++, 'city', $classification, '{}', '[]', '[]', 0, 'generated']);
        $blockIds[] = (int) $db->lastInsertId();
    }
    foreach ($service_block_picks as $bt) {
        $classification = $bt === 'niche_data_table' ? 'automatic' : 'editable';
        $ins->execute([$pageId, $bt, $position++, 'service', $classification, '{}', '[]', '[]', 0, 'generated']);
        $blockIds[] = (int) $db->lastInsertId();
    }
    foreach (['faq', 'service_area'] as $bt) {
        $classification = $bt === 'service_area' ? 'automatic' : 'editable';
        $origin = $bt === 'service_area' ? 'data' : 'generated'; // matches the regenerate branch that never rewrites origin for this one type
        $ins->execute([$pageId, $bt, $position++, 'spine', $classification, '{}', '[]', '[]', 0, $origin]);
        $blockIds[] = (int) $db->lastInsertId();
    }

    // housing_stock needs its Census dataset fetched once per city before the
    // automatic-block writer has anything to template — the same real
    // pluscities_dataset_get() every city already uses, not a special path.
    if (in_array('housing_stock', $city_block_picks, true)) {
        if (pluscities_dataset_get($city_id, 'census_acs')) {
            $st = $db->prepare('SELECT id FROM blocks WHERE page_id = ? AND block_type = "housing_stock"');
            $st->execute([$pageId]);
            if ($hsId = $st->fetchColumn()) {
                $db->prepare('UPDATE blocks SET dataset_refs = ? WHERE id = ?')
                   ->execute([json_encode([['city_id' => $city_id, 'provider' => 'census_acs']]), $hsId]);
            }
        }
    }

    foreach ($blockIds as $bid) {
        pluscities_regenerate_block($bid, $actor);
    }

    pluscities_touch_page($pageId);
    pluscities_log_event($actor, 'create_page', 'page:' . $pageId, '');
    return ['ok' => true, 'msg' => '', 'page_id' => $pageId];
}

function pluscities_compute_draft_hash(string $page_id): string
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT content_json FROM blocks WHERE page_id = ? ORDER BY position');
    $st->execute([$page_id]);
    return hash('sha256', implode('', $st->fetchAll(PDO::FETCH_COLUMN)));
}

/** Recomputes and stores the page's draft_hash, and resets approval_stage —
 *  called by every mutation below, so the invalidation rule lives in one place. */
function pluscities_touch_page(string $page_id): void
{
    $hash = pluscities_compute_draft_hash($page_id);
    pluscities_db()->prepare('UPDATE pages SET draft_hash = ?, approval_stage = "none" WHERE page_id = ?')
        ->execute([$hash, $page_id]);
}

/** Edit: saves content, auto-locks the block (protects a human edit from a
 *  future Regenerate silently overwriting it), origin=edited. */
function pluscities_edit_block(int $block_id, array $content, string $actor): bool
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT page_id FROM blocks WHERE id = ?');
    $st->execute([$block_id]);
    $pageId = $st->fetchColumn();
    if (!$pageId) return false;

    // A human edit is implicitly trusted for the Unsupported Claim check —
    // the validator can't mechanically verify freeform human text traces to
    // a fact the way it can an AI-generated [[fact_id]] tag, and the person
    // editing is accountable for what they write in a way raw AI output
    // isn't, so it's treated as grounded rather than falsely failing.
    $db->prepare('UPDATE blocks SET content_json = ?, locked = 1, origin = "edited", client_grounded = 1 WHERE id = ?')
       ->execute([json_encode($content), $block_id]);
    pluscities_touch_page($pageId);
    pluscities_log_event($actor, 'edit_block', 'block:' . $block_id, '');
    return true;
}

/** Regenerate: unlocked blocks only, re-runs the writer against the block's
 *  current fact cards/dataset. */
function pluscities_regenerate_block(int $block_id, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT b.*, p.city_id, p.service_key, p.client_id FROM blocks b JOIN pages p ON p.page_id = b.page_id WHERE b.id = ?');
    $st->execute([$block_id]);
    $block = $st->fetch(PDO::FETCH_ASSOC);
    if (!$block) return ['ok' => false, 'msg' => 'Block not found.'];
    if ((int) $block['locked'] === 1) return ['ok' => false, 'msg' => 'This block is locked — unlock it first.'];
    if ($block['classification'] === 'client-supplied') return ['ok' => false, 'msg' => 'Nothing to regenerate — this block is the client\'s own content.'];

    $client  = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $block['client_id'] . '.json'), true) ?: [];
    $context = ['city_label' => $block['city_id'], 'service_label' => $block['service_key'], 'business_name' => $client['identity']['business_name'] ?? ''];
    $clientFacts = pluscities_client_facts_for_writer($client);

    // Service area + links is pure structural data — no writer call, nothing
    // to regenerate in the AI sense, just rebuilt from the current record.
    if ($block['block_type'] === 'service_area') {
        $pageStmt = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
        $pageStmt->execute([$block['page_id']]);
        $page   = $pageStmt->fetch(PDO::FETCH_ASSOC);
        $niche  = pluscities_niche_resolved($client['niche_id'] ?? '') ?? [];
        $result = pluscities_write_service_area_block($client, $page, $niche);
        $db->prepare('UPDATE blocks SET content_json = ? WHERE id = ?')->execute([json_encode($result['content']), $block_id]);
        pluscities_touch_page($block['page_id']);
        pluscities_log_event($actor, 'regenerate_block', 'block:' . $block_id, '');
        return ['ok' => true, 'msg' => ''];
    }

    if ($block['block_type'] === 'faq') {
        $facts  = pluscities_fact_card_get_active('city', $block['city_id'], 'permits');
        $result = pluscities_write_faq_block($facts, $clientFacts, $context);
        if (!$result['ok']) return ['ok' => false, 'msg' => $result['msg']];
        $db->prepare('UPDATE blocks SET content_json = ?, fact_ids = ?, origin = "generated", client_grounded = 1 WHERE id = ?')
           ->execute([json_encode($result['content']), json_encode($result['fact_ids']), $block_id]);
        pluscities_touch_page($block['page_id']);
        pluscities_log_event($actor, 'regenerate_block', 'block:' . $block_id, '');
        return ['ok' => true, 'msg' => ''];
    }

    if ($block['classification'] === 'editable') {
        // hero/overview make no externally-sourced claims (client facts
        // only); permits-style city blocks are grounded in the matching
        // fact-card topic. Topic name == block_type for those, by convention.
        $facts  = in_array($block['block_type'], ['hero', 'overview'], true)
            ? [] : pluscities_fact_card_get_active('city', $block['city_id'], $block['block_type']);
        $result = pluscities_write_editable_block($block['block_type'], $block['block_type'], $facts, $clientFacts, $context);
        if (!$result['ok']) return ['ok' => false, 'msg' => $result['msg']];
        $db->prepare('UPDATE blocks SET content_json = ?, fact_ids = ?, origin = "generated", client_grounded = 1 WHERE id = ?')
           ->execute([json_encode($result['content']), json_encode($result['fact_ids']), $block_id]);
    } elseif ($block['block_type'] === 'niche_data_table') {
        // Niche-wide, not city-specific — the same reference table every
        // client in this niche shares, so there's no per-city dataset to
        // re-fetch, just the niche blueprint's own current content.
        $niche  = pluscities_niche_resolved($client['niche_id'] ?? '') ?: [];
        $result = pluscities_write_niche_data_table_block($niche);
        if (!$result['ok']) return ['ok' => false, 'msg' => $result['msg']];
        $db->prepare('UPDATE blocks SET content_json = ?, origin = "data" WHERE id = ?')
           ->execute([json_encode($result['content']), $block_id]);
    } else { // automatic
        $refs    = json_decode($block['dataset_refs'], true) ?: [];
        $dataset = $refs ? pluscities_dataset_get($refs[0]['city_id'], $refs[0]['provider']) : null;
        $result  = pluscities_write_automatic_block($block['block_type'], $dataset ?? [], $context);
        $db->prepare('UPDATE blocks SET content_json = ?, origin = "data" WHERE id = ?')
           ->execute([json_encode($result['content']), $block_id]);
    }
    pluscities_touch_page($block['page_id']);
    pluscities_log_event($actor, 'regenerate_block', 'block:' . $block_id, '');
    return ['ok' => true, 'msg' => ''];
}

/** Swap: unlocked blocks only, changes block_type within the same pool.
 *  Real pool-membership validation added 2026-11-22 — this previously had
 *  NONE at all (the doc comment claimed "Phase 1 only checks the pool
 *  matches," but the actual code never did; a real gap, not a stale
 *  comment to just reword). Regenerates content for the new type
 *  immediately so the block is never left showing stale content for a type
 *  it no longer is. */
function pluscities_swap_block(int $block_id, string $new_block_type, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT b.*, p.client_id FROM blocks b JOIN pages p ON p.page_id = b.page_id WHERE b.id = ?');
    $st->execute([$block_id]);
    $block = $st->fetch(PDO::FETCH_ASSOC);
    if (!$block) return ['ok' => false, 'msg' => 'Block not found.'];
    if ((int) $block['locked'] === 1) return ['ok' => false, 'msg' => 'This block is locked — unlock it first.'];

    $client = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $block['client_id'] . '.json'), true) ?: [];
    $niche  = pluscities_niche_resolved($client['niche_id'] ?? '') ?? [];
    $pool   = $block['pool'] === 'service' ? PLUSCITIES_SERVICE_POOL : ($niche['city_blocks'] ?? []);
    if (!in_array($new_block_type, $pool, true)) {
        return ['ok' => false, 'msg' => '"' . $new_block_type . '" is not in this block\'s pool.'];
    }

    $db->prepare('UPDATE blocks SET block_type = ? WHERE id = ?')->execute([$new_block_type, $block_id]);
    pluscities_log_event($actor, 'swap_block', 'block:' . $block_id, 'to ' . $new_block_type);
    return pluscities_regenerate_block($block_id, $actor);
}

/** Lock / unlock — independent of edit, since the UI exposes this as its own
 *  action per spec Section 6's action matrix. */
function pluscities_set_block_lock(int $block_id, bool $locked, string $actor): void
{
    pluscities_db()->prepare('UPDATE blocks SET locked = ? WHERE id = ?')->execute([$locked ? 1 : 0, $block_id]);
    pluscities_log_event($actor, $locked ? 'lock_block' : 'unlock_block', 'block:' . $block_id, '');
}

/**
 * Approve: requires validation current (checked against the page's current
 * draft_hash) and all checks passing. Phase 1 has no agency/approval-gate
 * record yet, so it always takes the no-gate path (approval_stage -> approved
 * directly) — the pending_platform_review/pending_agency_review sequence is
 * Phase 2 scope once a real agency record exists to read the gate from.
 */
function pluscities_approve_page(string $page_id, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];

    $validation = json_decode($page['validation_json'] ?: '[]', true) ?: [];
    if (($validation['hash_checked_against'] ?? null) !== $page['draft_hash']) {
        return ['ok' => false, 'msg' => 'Validation is stale — re-run it before approving.'];
    }
    if (!pluscities_validation_all_pass($validation)) {
        return ['ok' => false, 'msg' => 'One or more checks are still failing.'];
    }

    $db->prepare('UPDATE pages SET status = CASE WHEN status = "draft" THEN "approved" ELSE status END, approval_stage = "approved" WHERE page_id = ?')
       ->execute([$page_id]);
    pluscities_log_event($actor, 'approve_page', 'page:' . $page_id, '');
    return ['ok' => true, 'msg' => ''];
}

/**
 * Publish: only from an approved page. Snapshots the current blocks into a
 * new immutable revision, updates live_revision_id, bumps the client's
 * site_rev (for plugin sync), status -> live.
 */
/**
 * $actor_role gates "Agency Editor can publish only if the agency allows it"
 * (spec Section 10) — checked here, not just at the call site, for the same
 * reason the validator's override function checks role internally: a
 * capability enforced only in the UI is not actually enforced.
 */
function pluscities_publish_page(string $page_id, string $actor, string $actor_role = 'super_admin'): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];
    if ($page['approval_stage'] !== 'approved') return ['ok' => false, 'msg' => 'This page hasn\'t been approved yet.'];

    if ($actor_role === 'agency_editor') {
        $clientFile = PLUSCITIES_CLIENTS_DIR . $page['client_id'] . '.json';
        $client = json_decode((string) @file_get_contents($clientFile), true) ?: [];
        $agency = lpc_load_agency($client['agency_id'] ?? '');
        if (!$agency || empty($agency['editor_can_publish'])) {
            return ['ok' => false, 'msg' => 'This agency hasn\'t enabled Editor publishing — ask an Agency Admin to publish this page.'];
        }
    }

    $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $blocksStmt->execute([$page_id]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

    $numStmt = $db->prepare('SELECT COALESCE(MAX(number), 0) FROM revisions WHERE page_id = ?');
    $numStmt->execute([$page_id]);
    $number = (int) $numStmt->fetchColumn() + 1;

    $snapshot = ['blocks' => $blocks, 'slug' => $page['slug']];
    $db->prepare('INSERT INTO revisions (page_id, number, snapshot_json, hash, author, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$page_id, $number, json_encode($snapshot), $page['draft_hash'], $actor, gmdate('c')]);

    $db->prepare('UPDATE pages SET live_revision_id = ?, status = "live" WHERE page_id = ?')
       ->execute([$number, $page_id]);

    // Bump the client's site_rev — the plugin's manifest check compares against this.
    $clientFile = PLUSCITIES_CLIENTS_DIR . $page['client_id'] . '.json';
    $client = json_decode((string) @file_get_contents($clientFile), true);
    if ($client) {
        $client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;
        pluscities_atomic_write_json($clientFile, $client);
    }

    pluscities_log_event($actor, 'publish_page', 'page:' . $page_id, 'revision ' . $number);
    return ['ok' => true, 'msg' => '', 'revision' => $number];
}

/**
 * Roll back: copies an old revision's blocks into the draft. Never an instant
 * revert — the restored draft still has to go through Validate -> Approve ->
 * Publish again, same as any other edit, so there's no path to live that
 * skips validation, not even through history.
 */
function pluscities_rollback_page(string $page_id, int $revision_number, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT snapshot_json FROM revisions WHERE page_id = ? AND number = ?');
    $st->execute([$page_id, $revision_number]);
    $snapshotJson = $st->fetchColumn();
    if (!$snapshotJson) return ['ok' => false, 'msg' => 'Revision not found.'];

    $snapshot = json_decode($snapshotJson, true);
    $db->prepare('DELETE FROM blocks WHERE page_id = ?')->execute([$page_id]);
    $ins = $db->prepare('INSERT INTO blocks (page_id, block_type, position, pool, classification, content_json, fact_ids, dataset_refs, locked, origin, client_grounded) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($snapshot['blocks'] as $b) {
        $ins->execute([$page_id, $b['block_type'], $b['position'], $b['pool'], $b['classification'],
            $b['content_json'], $b['fact_ids'], $b['dataset_refs'], $b['locked'], 'edited', $b['client_grounded'] ?? 0]);
    }
    pluscities_touch_page($page_id);
    pluscities_log_event($actor, 'rollback_page', 'page:' . $page_id, 'to revision ' . $revision_number);
    return ['ok' => true, 'msg' => ''];
}

/** Unpublish: status -> unpublished, site_rev bumped so the plugin's next
 *  sync stops serving it. Nothing is deleted. */
function pluscities_unpublish_page(string $page_id, string $actor): bool
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT client_id FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $clientId = $st->fetchColumn();
    if (!$clientId) return false;

    $db->prepare('UPDATE pages SET status = "unpublished" WHERE page_id = ?')->execute([$page_id]);
    $clientFile = PLUSCITIES_CLIENTS_DIR . $clientId . '.json';
    $client = json_decode((string) @file_get_contents($clientFile), true);
    if ($client) {
        $client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;
        pluscities_atomic_write_json($clientFile, $client);
    }
    pluscities_log_event($actor, 'unpublish_page', 'page:' . $page_id, '');
    return true;
}
