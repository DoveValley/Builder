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
 * Load one pluscities_clients/*.json record by id, with no actor-scoping —
 * callers that already know which client they want (impersonation, the
 * portal the impersonated session lands on) just need the record, not the
 * filtered list. Every other reader of this directory inlines this same
 * json_decode(file_get_contents(...)) one-off; pulled out here so the two
 * new 2026-11-24 callers (admin/impersonate.php, admin/client_portal.php)
 * share one copy instead of adding a third inline version.
 */
/**
 * Platform-wide settings (grace period before a cancelled client's pages
 * actually redirect; how long the WP plugin's cache lives) — Platform-Admin-
 * only, not per-agency or per-client, added 2026-11-24 for real client
 * cancellation. Defaults apply whenever the file doesn't exist yet or is
 * missing a key, same "never crash on a field that isn't there yet" pattern
 * as every other optional setting in this engine.
 */
function pluscities_platform_settings(): array
{
    $defaults = ['cancellation_grace_days' => 14, 'plugin_cache_ttl_hours' => 24];
    if (!file_exists(PLUSCITIES_PLATFORM_SETTINGS_FILE)) return $defaults;
    $saved = json_decode((string) file_get_contents(PLUSCITIES_PLATFORM_SETTINGS_FILE), true);
    return array_merge($defaults, is_array($saved) ? $saved : []);
}

function pluscities_client_load(string $client_id): ?array
{
    $file = PLUSCITIES_CLIENTS_DIR . $client_id . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/**
 * Permanently removes a client from every list/access-check — but soft,
 * not a true wipe (added 2026-11-26, closing the gap found during the
 * panel-coverage walkthrough's Session 5). Moves pluscities_clients/{id}.json
 * and its API secret into PLUSCITIES_CLIENTS_DELETED_DIR rather than
 * destroying them: pluscities_client_ids_for_current_actor() and
 * pluscities_actor_can_access_client() both work by globbing/checking
 * PLUSCITIES_CLIENTS_DIR, so pulling the file out is sufficient on its own to
 * make the client vanish everywhere — no second "is_deleted" flag to keep in
 * sync. Page/block rows in pluscities.db are deliberately left untouched:
 * they become unreachable the moment the client file is gone, and moving the
 * file back out of quarantine restores full access to them with nothing to
 * regenerate or re-create.
 *
 * @return array{ok:bool,msg:string}
 */
function pluscities_delete_client(string $client_id, string $confirm_name, string $actor): array
{
    $client = pluscities_client_load($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found.'];

    $realName = trim((string) ($client['identity']['business_name'] ?? ''));
    if ($realName === '' || trim($confirm_name) !== $realName) {
        return ['ok' => false, 'msg' => 'Typed name did not match this client\'s exact business name — nothing was deleted.'];
    }

    if (!is_dir(PLUSCITIES_CLIENTS_DELETED_DIR) && !mkdir(PLUSCITIES_CLIENTS_DELETED_DIR, 0775, true) && !is_dir(PLUSCITIES_CLIENTS_DELETED_DIR)) {
        return ['ok' => false, 'msg' => 'Could not create the quarantine directory.'];
    }

    // Timestamped, not overwritten — deleting, recreating, and deleting the
    // same client_id again must never clobber an earlier quarantined copy.
    $stamp = gmdate('Ymd-His');
    $clientFile = PLUSCITIES_CLIENTS_DIR . $client_id . '.json';
    $destFile   = PLUSCITIES_CLIENTS_DELETED_DIR . $client_id . '.' . $stamp . '.json';
    if (!rename($clientFile, $destFile)) {
        return ['ok' => false, 'msg' => 'Could not move the client record — check file permissions.'];
    }

    $secretFile = PLUSCITIES_CLIENTS_SECRETS_DIR . $client_id . '.txt';
    if (file_exists($secretFile)) {
        @rename($secretFile, PLUSCITIES_CLIENTS_DELETED_DIR . $client_id . '.' . $stamp . '.secret.txt');
    }

    pluscities_log_event($actor, 'delete_client', 'client:' . $client_id, 'business_name=' . $realName);
    return ['ok' => true, 'msg' => '"' . $realName . '" was deleted.'];
}

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
    // Both pools are already the correct pickable subset — pluscities_niche_
    // resolved() subtracts each family's always-on blocks before this ever
    // sees them (city_blocks since 2026-11-26's always-blocks mechanism,
    // service_blocks the same way since the service pool became
    // family-customizable the same day) — a block is either always-on or
    // pickable, never both, and never double-counted in the "choose up to N
    // of M" math.
    $cityPool    = $niche['city_blocks'] ?? [];
    $servicePool = $niche['service_blocks'] ?? [];

    $cityPicks    = array_values(array_intersect($cityPicksRaw, $cityPool));
    $servicePicks = array_values(array_intersect($servicePicksRaw, $servicePool));

    return [
        'city'    => array_slice($cityPicks, 0, min(6, count($cityPool))),
        'service' => array_slice($servicePicks, 0, min(4, count($servicePool))),
    ];
}

/**
 * city_id is always "{city-slug}-{2-letter state}" (e.g. "corpus-christi-tx")
 * per the cities table convention — splitting the state off the end is what
 * lets a slug pattern use {city} and {state} as two separate tokens rather
 * than just re-exposing the raw city_id.
 */
function pluscities_split_city_state(string $city_id): array
{
    $lastDash = strrpos($city_id, '-');
    if ($lastDash === false) return ['city' => $city_id, 'state' => ''];
    $maybeState = substr($city_id, $lastDash + 1);
    if (strlen($maybeState) !== 2 || !ctype_alpha($maybeState)) return ['city' => $city_id, 'state' => ''];
    return ['city' => substr($city_id, 0, $lastDash), 'state' => $maybeState];
}

/**
 * Safe to put directly into a URL path: lowercase letters/digits/hyphens,
 * '/' kept as a real segment separator (a slug pattern is allowed to nest
 * into real sub-paths; a flat pattern just never produces one). Collapses
 * whatever a token substitution could leave behind (an empty
 * {state} leaving a trailing "-", two tokens colliding into "--" or "//")
 * rather than storing a visibly broken URL.
 */
function pluscities_sanitize_slug(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9\-\/]+/', '-', $s);  // anything unsafe -> hyphen
    $s = preg_replace('/[\-\/]*\/[\-\/]*/', '/', $s); // any run touching a slash (incl. "//") -> one slash
    $s = preg_replace('/-{2,}/', '-', $s);           // remaining repeated hyphens -> one
    return trim($s, '-/');
}

/**
 * Pattern -> real slug, e.g. "{primary_keyword}-{city}-{state}" with
 * city_id "corpus-christi-tx" and the botox service -> "botox-corpus-
 * christi-tx". Default pattern ("{city}-{state}/{service}") reproduces
 * exactly what every client got before this existed (city_id's full
 * "corpus-christi-tx" form, not the split-off city alone), so a client
 * that never touches the new Slug Pattern field keeps today's URLs
 * unchanged.
 */
function pluscities_resolve_slug(array $client, array $niche, string $city_id, string $service_key): string
{
    $pattern = trim((string) ($client['slug_pattern'] ?? '')) ?: '{city}-{state}/{service}';
    $split = pluscities_split_city_state($city_id);

    $service = $service_key;
    $primaryKeyword = $service_key;
    foreach ($niche['services'] ?? [] as $svc) {
        if (($svc['slug'] ?? '') === $service_key) {
            $service = $svc['slug'];
            $primaryKeyword = trim((string) ($svc['primary_keyword'] ?? '')) ?: $svc['slug'];
            break;
        }
    }

    $resolved = strtr($pattern, [
        '{primary_keyword}' => $primaryKeyword,
        '{city}'            => $split['city'],
        '{state}'           => $split['state'],
        '{service}'         => $service,
    ]);

    // Off by default (every existing client keeps today's behavior). The hub
    // page's own URL is a SEPARATE concept and always keeps url_base_path —
    // this only controls whether a SERVICE page's slug is nested under it.
    $prefixed = $client['prefix_service_pages'] ?? true;
    $basePath = $prefixed ? '/' . trim($client['url_base_path'] ?? 'service-area', '/') . '/' : '/';
    return $basePath . pluscities_sanitize_slug($resolved) . '/';
}

/** Two pages for the SAME client landing on the identical final slug would
 *  have one silently shadow the other at request time — checked wherever a
 *  slug is about to be written, not just at creation. */
function pluscities_slug_is_unique(string $client_id, string $slug, string $excludePageId = ''): bool
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT 1 FROM pages WHERE client_id = ? AND slug = ? AND page_id != ?');
    $st->execute([$client_id, $slug, $excludePageId]);
    return !$st->fetchColumn();
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
        if (!in_array($bt, $niche['service_blocks'] ?? [], true)) {
            return ['ok' => false, 'msg' => "\"$bt\" is not in this niche's service block pool.", 'page_id' => null];
        }
    }

    // Always-on blocks join the validated picks AFTER validation (they're
    // deliberately NOT in the pickable pool any more — pluscities_niche_
    // resolved() already subtracted them — so validating them against that
    // same pool would wrongly reject them). Merged in, not a separate
    // insertion path, so each type's existing special-casing below
    // (housing_stock's 'automatic' classification, reviews_from_city's
    // client-supplied content) still applies for free.
    $city_block_picks = array_values(array_unique(array_merge($city_block_picks, $niche['always_city_blocks'] ?? [])));
    $service_block_picks = array_values(array_unique(array_merge($service_block_picks, $niche['always_service_blocks'] ?? [])));

    $pageId = 'pg_' . str_replace('-', '', $client_id) . '_' . $city_id . '_' . $service_key;
    $slug   = pluscities_resolve_slug($client, $niche, $city_id, $service_key);

    $db = pluscities_db();
    $existing = $db->prepare('SELECT 1 FROM pages WHERE page_id = ?');
    $existing->execute([$pageId]);
    if ($existing->fetchColumn()) return ['ok' => false, 'msg' => 'This page already exists.', 'page_id' => null];
    if (!pluscities_slug_is_unique($client_id, $slug)) {
        return ['ok' => false, 'msg' => "Another page already has the slug \"$slug\" — check the client's Slug Pattern.", 'page_id' => null];
    }

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
            // Housing Snapshot chart (includes/pluscities_data_chart.php) —
            // same dataset, no new fetch. City-level furniture like Area
            // Map: best-effort, never blocks page creation.
            pluscities_ensure_housing_chart($city_id);
        }
    }

    foreach ($blockIds as $bid) {
        pluscities_regenerate_block($bid, $actor);
    }

    // Area Map — city-level furniture, drawn once per city_id and shared by
    // every client's pages for that city (includes/pluscities_area_map.php).
    // Best-effort: a city with no coordinates/nearby towns on record, or no
    // SVG converter on this box, just means no map renders later — never
    // blocks page creation.
    pluscities_ensure_city_map($city_id);

    // City Info — real Wikipedia photo + AI-written narrative, city-level
    // furniture (includes/pluscities_city_info.php). Best-effort: a city
    // Wikipedia has no lead image for still gets its narrative (and vice
    // versa) — never blocks page creation.
    pluscities_ensure_city_info($city_id);

    // Climate chart (includes/pluscities_climate_chart.php) — real NOAA
    // data, city+niche-level furniture (different niches may pick different
    // charts for the same city). Best-effort: a niche with no
    // climate_chart_id configured, or a city with no NOAA station data,
    // just means no chart renders later — never blocks page creation.
    pluscities_ensure_climate_chart($city_id, $client['niche_id'] ?? '');

    pluscities_touch_page($pageId);
    pluscities_log_event($actor, 'create_page', 'page:' . $pageId, '');
    return ['ok' => true, 'msg' => '', 'page_id' => $pageId];
}

/**
 * Launches a detached "multiple cities x multiple services" page-creation
 * job for one client — the New Client wizard's "Multiple pages" mode
 * (2026-11-26). Reuses the multisite fleet's own proven background-job
 * mechanism (includes/multisite/batch.php: ms_launch_job_now(), ms_with_
 * launch_lock(), ms_active_run()) rather than rebuilding a second detach/
 * liveness mechanism — it already solved the hard bugs (surviving an
 * apache2 restart that would otherwise SIGKILL a plain setsid child; not
 * misreading a recycled PID as "still running"). Required lazily so
 * multisite's globals/constants aren't pulled into every ordinary PlusCities
 * page view, only when a bulk job is actually launched or checked.
 *
 * The actual work (looping combos, calling pluscities_create_page() per one
 * with each service's family happy-path blocks, writing structured progress)
 * happens in the detached script, includes/pluscities_bulk_pages_cli.php —
 * this function only launches it and hands back the run_id to poll.
 *
 * @return array{run_id:string,started:bool,msg:string}
 */
function pluscities_bulk_create_pages_launch(string $client_id, array $cities, array $services, int $drive_minutes, string $actor): array
{
    require_once __DIR__ . '/multisite/batch.php';

    $dir = rtrim(PLUSCITIES_BULK_RUNS_DIR, '/') . '/' . $client_id;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['run_id' => '', 'started' => false, 'msg' => 'Could not create the job directory.'];
    }

    return ms_with_launch_lock($dir . '/.lock', function () use ($dir, $client_id, $cities, $services, $drive_minutes, $actor) {
        // One bulk job at a time per client — same "is one already running"
        // guard every other detached job in this codebase uses, scoped to
        // this client's own runs dir rather than a shared one.
        $active = ms_active_run($dir . '/runs');
        if ($active) {
            return ['run_id' => $active['run_id'], 'started' => false, 'msg' => 'A bulk job is already running for this client.'];
        }
        $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $script = __DIR__ . '/pluscities_bulk_pages_cli.php';
        $args = [
            $client_id,
            '--run-id=' . $runId,
            '--cities=' . implode(',', $cities),
            '--services=' . implode(',', $services),
            '--drive-minutes=' . $drive_minutes,
            '--actor=' . $actor,
        ];
        ms_launch_job_now($dir, '__PC_BULK_DONE__', $script, $args);
        return ['run_id' => $runId, 'started' => true, 'msg' => ''];
    });
}

/**
 * One bulk job's current status, liveness-corrected (ms_read_run() downgrades
 * a dead process still marked 'running' to 'stale' rather than leaving a
 * poller believing a killed job is still working — the exact bug this
 * project already hit once on the multisite side).
 */
function pluscities_bulk_job_status(string $client_id, string $run_id): ?array
{
    require_once __DIR__ . '/multisite/batch.php';
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $run_id)) return null;
    $file = rtrim(PLUSCITIES_BULK_RUNS_DIR, '/') . '/' . $client_id . '/runs/' . $run_id . '.json';
    return ms_read_run($file);
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

/**
 * Changes a page's own slug/URL. Draft pages change freely — nothing's
 * indexed yet, nothing to protect. A LIVE page needs $confirmed=true, since
 * there's deliberately no redirect from the old URL to the new one (see
 * project discussion, 2026-11-27) — changing a live page's slug without
 * confirming would silently 404 whatever Google already has indexed.
 */
function pluscities_set_page_slug(string $page_id, string $new_slug, string $actor, bool $confirmed): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT client_id, slug, status FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.', 'needs_confirm' => false];

    $new_slug = pluscities_sanitize_slug($new_slug);
    $new_slug = '/' . trim($new_slug, '/') . '/';
    if ($new_slug === $page['slug']) return ['ok' => true, 'msg' => '', 'needs_confirm' => false];

    if (!pluscities_slug_is_unique($page['client_id'], $new_slug, $page_id)) {
        return ['ok' => false, 'msg' => "Another page already has the slug \"$new_slug\".", 'needs_confirm' => false];
    }
    if ($page['status'] === 'live' && !$confirmed) {
        return [
            'ok' => false, 'needs_confirm' => true,
            'msg' => "This page is already live at {$page['slug']} — changing this will break that exact URL "
                . "(no redirect gets created). Confirm to change it anyway.",
        ];
    }

    $db->prepare('UPDATE pages SET slug = ? WHERE page_id = ?')->execute([$new_slug, $page_id]);
    pluscities_log_event($actor, 'edit_slug', 'page:' . $page_id, "{$page['slug']} -> $new_slug");
    return ['ok' => true, 'msg' => '', 'needs_confirm' => false];
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
    // Real "Frisco, TX" label, not the internal city_id slug — every AI
    // writer prompt below says "City: {$context['city_label']}" and a model
    // fed "bedford-tx" verbatim sometimes echoed it back uncorrected (e.g.
    // "Bedford-TX" in generated body text) instead of reliably normalizing
    // it itself. Same function pluscities_sync.php/pluscities_schema.php
    // already use for the real visible city name.
    $context = ['city_label' => pluscities_city_label($block['city_id']), 'service_label' => $block['service_key'], 'business_name' => $client['identity']['business_name'] ?? ''];
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

    // Items-shaped blocks (Tier 2, 2026-11-25/26): a list of {heading, body}
    // items instead of one heading+body — why_choose_us was the pilot;
    // process (numbered steps) and common_problems (an icon grid) are the
    // same shape/validation pattern, just a different instruction to
    // pluscities_write_items_block(). Same fact-sourcing call every other
    // editable city block already uses — only the writer and the returned
    // shape change, not where its facts come from.
    $itemsInstructions = [
        'why_choose_us' => 'Write 3-4 short, distinct reasons a customer should choose this business, for a '
            . 'local-service landing page\'s "Why choose us" section. Each reason needs its own short '
            . 'heading (2-5 words) and a 1-2 sentence body.',
        'process' => 'Write 3-5 sequential steps describing what actually happens when this business comes '
            . 'to do the work, in the order they happen (e.g. inspection, then the work itself, then '
            . 'follow-up), for a local-service landing page\'s "Our Process" section. Each step needs its '
            . 'own short heading (2-4 words, e.g. "Initial Inspection") and a 1-2 sentence body.',
        'common_problems' => 'Write 3-4 distinct, common problems or issues this service addresses, for a '
            . 'local-service landing page\'s "Common Problems" section. Each problem needs its own short '
            . 'heading (2-5 words naming the problem) and a 1-2 sentence body describing it.',
        'seasonal_guide' => 'Write exactly 4 items, one for each season (Spring, Summer, Fall, Winter in that '
            . 'order), describing what this service actually looks like in that season for this city, for a '
            . 'local-service landing page\'s "Seasonal Guide" section. Each item\'s heading must be the '
            . 'season name (e.g. "Spring") and the body is a 1-2 sentence description of that season\'s '
            . 'specific risk/need and how this business addresses it.',
    ];
    // Fact-topic lookups are the block's own type name by convention — except
    // seasonal_guide, a pre-existing documented mismatch (includes/pluscities_
    // block_catalog.php's own doc comment): the topic PLUSCITIES_TOPIC_EXPIRY_
    // MONTHS actually defines is "seasons", not "seasonal_guide". Fixed here
    // rather than left to silently never find a fact card this block's
    // research was always meant to attach to.
    $factTopicOverrides = ['seasonal_guide' => 'seasons'];
    if (isset($itemsInstructions[$block['block_type']])) {
        $factTopic = $factTopicOverrides[$block['block_type']] ?? $block['block_type'];
        $facts  = pluscities_fact_card_get_active('city', $block['city_id'], $factTopic);
        $result = pluscities_write_items_block($itemsInstructions[$block['block_type']], $facts, $clientFacts, $context);
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
    // Pickable subsets only — swapping INTO an always-on type (whatever a
    // niche's family marks always_city_blocks/always_service_blocks) would
    // create a duplicate block_type on the page, since pluscities_create_
    // page() already always-includes them. Both $niche['city_blocks'] and
    // $niche['service_blocks'] already have their family's always-on blocks
    // subtracted by pluscities_niche_resolved().
    $pool = $block['pool'] === 'service'
        ? ($niche['service_blocks'] ?? [])
        : ($niche['city_blocks'] ?? []);
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
 * draft_hash) and all checks passing. Two independent, optional gates, added
 * 2026-11-24 — each off by default so no existing agency/client's workflow
 * changes unless explicitly turned on:
 *
 * - Agency-level (agency.requires_approval_gate — the switch already existed
 *   on Settings but was never actually read anywhere until now): when on, an
 *   agency_editor cannot approve their own page, same shape as the existing
 *   editor_can_publish gate on pluscities_publish_page() below.
 * - Client-level (client.requires_client_approval, per-client not
 *   per-agency, since different clients of the same agency can have
 *   different trust levels): when on, approving doesn't go straight to
 *   "approved" — it goes to the new pending_client_review stage first, which
 *   pluscities_publish_page() already refuses to publish from (it only
 *   checks for the literal string "approved"), so this blocks publish for
 *   free without touching that function. The client approves their own way,
 *   via pluscities_client_approve_page() below.
 */
function pluscities_approve_page(string $page_id, string $actor, string $actor_role = 'super_admin'): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];

    $client = pluscities_client_load($page['client_id']) ?? [];

    if ($actor_role === 'agency_editor') {
        $agency = lpc_load_agency($client['agency_id'] ?? '');
        if ($agency && !empty($agency['requires_approval_gate'])) {
            return ['ok' => false, 'msg' => 'This agency requires an Agency Admin to approve — ask one to approve this page.'];
        }
    }

    $validation = json_decode($page['validation_json'] ?: '[]', true) ?: [];
    if (($validation['hash_checked_against'] ?? null) !== $page['draft_hash']) {
        return ['ok' => false, 'msg' => 'Validation is stale — re-run it before approving.'];
    }
    if (!pluscities_validation_all_pass($validation)) {
        return ['ok' => false, 'msg' => 'One or more checks are still failing.'];
    }

    $nextStage = !empty($client['requires_client_approval']) ? 'pending_client_review' : 'approved';
    $db->prepare('UPDATE pages SET status = CASE WHEN status = "draft" THEN "approved" ELSE status END, approval_stage = ? WHERE page_id = ?')
       ->execute([$nextStage, $page_id]);
    pluscities_log_event($actor, 'approve_page', 'page:' . $page_id, $nextStage === 'pending_client_review' ? 'sent to client for review' : '');
    return ['ok' => true, 'msg' => '', 'stage' => $nextStage];
}

/**
 * Client approves their own page — the one write action on the otherwise
 * read-only End Client portal. Only moves a page that's genuinely waiting
 * on this specific client (pending_client_review AND the right client_id,
 * checked by the caller before this is reached) — a client can't approve a
 * page that isn't theirs or that isn't actually waiting on them.
 */
function pluscities_client_approve_page(string $page_id, string $client_id, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ? AND client_id = ?');
    $st->execute([$page_id, $client_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];
    if ($page['approval_stage'] !== 'pending_client_review') {
        return ['ok' => false, 'msg' => 'This page isn\'t waiting on your approval.'];
    }

    $db->prepare('UPDATE pages SET approval_stage = "approved" WHERE page_id = ?')->execute([$page_id]);
    pluscities_log_event($actor, 'client_approve_page', 'page:' . $page_id, '');
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

    // Checked for every role, including super_admin — "suspended" means
    // nothing goes live until a Platform Admin explicitly un-suspends, not a
    // soft block an agency (or the same admin, mid-incident) can route
    // around by publishing again. Added 2026-11-24 alongside "Suspend Site".
    $client = pluscities_client_load($page['client_id']);
    if ($client && !empty($client['site_suspended'])) {
        return ['ok' => false, 'msg' => 'This client\'s site has been suspended by the platform admin — un-suspend it before publishing.'];
    }

    if ($actor_role === 'agency_editor') {
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
