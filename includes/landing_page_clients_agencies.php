<?php
/**
 * Agencies — the reseller layer above Landing Page Clients' existing
 * client/niche-profile model. Same shape as landing_page_clients_niches.php
 * on purpose: one JSON file per entity, default_*()/load/save/list, same
 * validation pattern lpc_valid_client_id() already uses.
 *
 * An agency buys access to this platform and resells finished pages to its
 * own customers — every existing `client.json` gets an `agency_id` (see
 * lpc_default_client_data()'s new field), defaulting to 'scotts-agency' so
 * current data keeps working unchanged: Scott operates through his own
 * agency row rather than a separate direct-sale code path, same principle
 * this codebase already applies to API modules (one path, reused, never
 * special-cased).
 *
 * New agencies are created by Scott only, via admin/agencies.php — never
 * self-serve. Creating one through that screen IS the approval (there's no
 * external signup request to separately review), so it goes straight to
 * 'active'. Real agency logins go through admin/agency_login.php.
 */

function lpc_agencies_dir() {
    return BASE_DIR . '/client_agencies/';
}

function lpc_valid_agency_id($agency_id) {
    return is_string($agency_id) && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $agency_id) === 1;
}

function lpc_agency_file($agency_id) {
    if (!lpc_valid_agency_id($agency_id)) return null;
    return lpc_agencies_dir() . $agency_id . '.json';
}

// Same pattern as lpc_unique_client_id().
function lpc_unique_agency_id($name) {
    $base = slugify($name) ?: 'agency';
    if (!lpc_valid_agency_id($base)) $base = 'agency';
    $id = $base;
    $i = 2;
    while (file_exists(lpc_agency_file($id) ?? '')) {
        $id = $base . '-' . $i;
        $i++;
    }
    return $id;
}

function lpc_default_agency_data() {
    return [
        'agency_id'   => '',
        'name'        => '',
        // 'pending_approval' | 'active' | 'suspended' — a brand-new agency
        // row starts unapproved; only an explicit admin action moves it to
        // 'active'. 'suspended' is the dual-lockout state (failed payment or
        // manual action) — see billing.spend_cap_usd below for the other
        // half of that mechanism.
        'status'      => 'pending_approval',
        'created_at'  => '',
        'approved_at' => '',
        'billing' => [
            'stripe_customer_id'     => '',
            'stripe_subscription_id' => '',
            'monthly_fee_usd'        => 0.0,
            'plan'                   => '',
            // Flat monthly cap on pass-through paid API calls (rank checks,
            // keyword lookups, climate re-fetches, regenerations) — NOT
            // metered/usage-based billing yet, deliberately deferred until
            // there's real usage data from actual agencies to price it.
            // Scott-settable per agency; 0 means "no cap enforced yet."
            'spend_cap_usd'       => 0.0,
            'spend_this_cycle_usd'=> 0.0,
            'cycle_reset_at'      => '',
            // Set by the Stripe webhook on invoice.payment_failed, cleared on
            // invoice.payment_succeeded. Drives the dual-lockout: Agency
            // Portal access blocks immediately (agency_login.php's existing
            // status!=='active' check), while this timestamp is what a grace-
            // period check (lpc_agency_grace_period_expired()) measures
            // against before actually pausing the agency's live client sites
            // — not immediate, per the locked "grace period, then pause"
            // offboarding decision.
            'payment_failed_at'   => '',
            // Most recent Checkout Session link created for this agency —
            // persisted so Scott can come back and copy it again without
            // regenerating, not just a one-shot flash message.
            'last_checkout_url'   => '',
        ],
        // Visual-only white-labeling, deliberately minimal (logo + name
        // swapped into the shared admin UI) — no color theming, no custom
        // domain. Both are easy later additions (the main product's own
        // theme_css_vars() mechanism could drive color theming with no new
        // plumbing) but not part of this.
        'branding' => [
            'display_name' => '',
            'logo_url'     => '',
        ],
        // Username only — not a secret, fine to live in the tracked JSON
        // (same as ADMIN_USERNAME being a plain constant in config.php
        // today). The password hash is deliberately NOT a field here — see
        // lpc_agency_secret_file() below, same out-of-git treatment this
        // codebase already gives the client API key.
        'login' => [
            'username' => '',
        ],
        // Platform-Admin-only control over whether this agency's pages go
        // through the two-stage approval gate (lpc_requires_approval_gate()
        // in landing_page_clients_approvals.php). Defaults to true so every
        // existing and new agency keeps today's uniform behavior until
        // Scott deliberately trusts a specific agency enough to flip it off
        // — never agency self-service, set on admin/settings.php only.
        'requires_approval_gate' => true,
    ];
}

function lpc_load_agency($agency_id) {
    $file = lpc_agency_file($agency_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        error_log('landing-page-clients-agencies: ' . $file . ' failed to decode as JSON (' . json_last_error_msg() . ')');
        return null;
    }
    return lpc_deep_merge_defaults(lpc_default_agency_data(), $data);
}

function lpc_save_agency($agency_id, $data) {
    $file = lpc_agency_file($agency_id);
    if ($file === null) return false;
    $data['agency_id'] = $agency_id;
    if (empty($data['created_at'])) $data['created_at'] = gmdate('c');
    return lpc_atomic_write_json($file, $data);
}

function lpc_delete_agency($agency_id) {
    $file = lpc_agency_file($agency_id);
    if ($file === null || !file_exists($file)) return false;
    return unlink($file);
}

function lpc_list_agencies() {
    if (!is_dir(lpc_agencies_dir())) return [];
    $ids = [];
    foreach (glob(lpc_agencies_dir() . '*.json') as $f) {
        $ids[] = basename($f, '.json');
    }
    sort($ids);
    return $ids;
}

/**
 * Who's acting right now, for the two scoping functions below. Reads
 * `$_SESSION['actor_role']`/`['agency_id']` — neither is set anywhere yet
 * (no login flow writes them), so every existing request defaults to
 * 'super_admin' with no agency restriction, exactly today's unrestricted
 * single-operator behavior. Safe to introduce now, ahead of the login work
 * that will actually set these for a real agency session.
 */
/**
 * Three separate login flags — admin_logged_in (super_admin, set by
 * login.php), agency_logged_in (agency_login.php), client_logged_in
 * (client_login.php) — NOT one shared flag plus a role tag. The point:
 * every fleet-only/Site-Factory page in this codebase was written (and
 * every new one will be written) checking only `empty($_SESSION
 * ['admin_logged_in'])`, with no reason to think about agencies or end
 * clients at all. With one shared flag, that default was dangerous — see
 * commit 47cbb498, which had to retrofit an explicit actor_role check onto
 * 68 existing pages that got this wrong. With separate flags, that same
 * simplest-possible check is correct BY DEFAULT, forever, with nothing to
 * remember: an agency or end-client session simply never sets
 * admin_logged_in, so it can't satisfy that check no matter what a new
 * page forgets to add. Pages genuinely meant to be shared across roles
 * (clients.php, client.php, etc.) use lpc_actor_is_authenticated() below
 * instead — a deliberate, visible opt-in, not the accidental default.
 */
function lpc_actor_is_authenticated() {
    return !empty($_SESSION['admin_logged_in'])
        || !empty($_SESSION['agency_logged_in'])
        || !empty($_SESSION['client_logged_in']);
}

function lpc_current_actor_role() {
    return $_SESSION['actor_role'] ?? 'super_admin';
}

function lpc_current_actor_agency_id() {
    return $_SESSION['agency_id'] ?? null;
}

function lpc_current_actor_client_id() {
    return $_SESSION['client_id'] ?? null;
}

/**
 * "Log in as" — lets a Super Admin view the product as any agency or any
 * client, and lets an Agency Admin view it as one of its own clients,
 * without ever touching that account's actual password. One hop only, no
 * stacking: starting a new impersonation while already impersonating is
 * rejected (lpc_start_impersonation() checks lpc_is_impersonating() first)
 * — exit back to your real self and start a fresh one instead of nesting.
 *
 * Mechanism: the real login flag (admin_logged_in/agency_logged_in) never
 * changes — impersonation only overlays actor_role/agency_id/client_id,
 * the exact same fields lpc_current_actor_role() etc. already read, with
 * the real values tucked into real_actor_role/real_agency_id/
 * real_client_id so lpc_stop_impersonation() can restore them exactly.
 */
function lpc_is_impersonating() {
    return !empty($_SESSION['impersonating']);
}

function lpc_start_impersonation(string $targetRole, ?string $agencyId, ?string $clientId): bool {
    if (lpc_is_impersonating()) return false; // no stacking

    $_SESSION['real_actor_role']  = lpc_current_actor_role();
    $_SESSION['real_agency_id']   = lpc_current_actor_agency_id();
    $_SESSION['real_client_id']   = lpc_current_actor_client_id();
    $_SESSION['impersonating']    = true;
    $_SESSION['actor_role']       = $targetRole;
    $_SESSION['agency_id']        = $agencyId;
    $_SESSION['client_id']        = $clientId;

    error_log(sprintf(
        'IMPERSONATION START: %s (agency=%s) -> %s (agency=%s client=%s)',
        $_SESSION['real_actor_role'], $_SESSION['real_agency_id'] ?? '-',
        $targetRole, $agencyId ?? '-', $clientId ?? '-'
    ));
    return true;
}

function lpc_stop_impersonation(): void {
    if (!lpc_is_impersonating()) return;
    error_log(sprintf(
        'IMPERSONATION END: %s (agency=%s client=%s) -> %s (agency=%s)',
        lpc_current_actor_role(), lpc_current_actor_agency_id() ?? '-', lpc_current_actor_client_id() ?? '-',
        $_SESSION['real_actor_role'], $_SESSION['real_agency_id'] ?? '-'
    ));
    $_SESSION['actor_role']  = $_SESSION['real_actor_role'];
    $_SESSION['agency_id']   = $_SESSION['real_agency_id'];
    $_SESSION['client_id']   = $_SESSION['real_client_id'];
    unset($_SESSION['impersonating'], $_SESSION['real_actor_role'], $_SESSION['real_agency_id'], $_SESSION['real_client_id']);
}

/** A loud, impossible-to-miss banner on every page while impersonating — names who you're viewing as and gives a one-click way back, never silent. */
function lpc_render_impersonation_banner(): void {
    if (!lpc_is_impersonating()) return;
    $role = lpc_current_actor_role();
    if ($role === 'agency_admin') {
        $agency = lpc_load_agency(lpc_current_actor_agency_id());
        $label = 'agency ' . h($agency['name'] ?? lpc_current_actor_agency_id());
    } else {
        $client = lpc_load_client(lpc_current_actor_client_id());
        $label = 'client ' . h($client['identity']['business_name'] ?? lpc_current_actor_client_id());
    }
    echo '<div style="background:#b45309;color:#fff;padding:10px 24px;text-align:center;font-size:.85rem;font-weight:600;">'
        . 'Viewing as ' . $label . ' &mdash; '
        . '<a href="impersonate.php?action=stop&csrf_token=' . urlencode($_SESSION['csrf_token'] ?? '') . '" style="color:#fff;text-decoration:underline;">Return to your own account</a>'
        . '</div>';
}

/**
 * The name/logo the shared admin topbar should show for whoever's logged
 * in right now — "Site Factory" with no logo for the platform itself,
 * or an agency's own branding.display_name/logo_url when an Agency Admin
 * is logged in (falling back to the platform default for any field an
 * agency left blank, same as every other optional branding field in this
 * codebase). Not used for End Client — client_portal.php already shows the
 * end client's own business name, not the reselling agency's.
 */
function lpc_actor_branding() {
    $default = ['name' => 'Site Factory', 'logo_url' => ''];
    $role = lpc_current_actor_role();

    if ($role === 'agency_admin') {
        $agency = lpc_load_agency(lpc_current_actor_agency_id());
        if ($agency) {
            return [
                'name'     => $agency['branding']['display_name'] ?: $default['name'],
                'logo_url' => $agency['branding']['logo_url'] ?: $default['logo_url'],
            ];
        }
    } elseif ($role === 'end_client') {
        // An end client's portal has always shown their OWN business name here
        // (never "Site Factory" or their reselling agency's name) — preserved
        // as-is when client_portal.php moved onto the shared sidebar, not a
        // new behavior.
        $client = lpc_load_client(lpc_current_actor_client_id());
        if ($client) {
            return ['name' => $client['identity']['business_name'] ?: lpc_current_actor_client_id(), 'logo_url' => ''];
        }
    }

    return $default;
}

/**
 * Who's logged in and what kind of account it is — shown in the sidebar
 * above "Log out" so a Super Admin mid-impersonation (or anyone, really)
 * can tell at a glance which seat they're sitting in. Deliberately reads
 * the CURRENT actor_role/agency_id/client_id, same as lpc_admin_nav_items()
 * and lpc_actor_branding() — during impersonation this reflects the
 * impersonated identity, not the real one (the orange banner above already
 * covers "who you really are and how to get back").
 */
function lpc_current_actor_identity(): array {
    return lpc_actor_identity_label(lpc_current_actor_role(), lpc_current_actor_agency_id(), lpc_current_actor_client_id());
}

/**
 * Same lookup as lpc_current_actor_identity(), but for an arbitrary
 * role/agency_id/client_id rather than the live session — used by the
 * Platform Information page to label historical login records, where the
 * agency/client in question usually isn't the one currently logged in.
 */
function lpc_actor_identity_label(string $role, ?string $agencyId, ?string $clientId): array {
    if ($role === 'agency_admin') {
        $agency = $agencyId ? lpc_load_agency($agencyId) : null;
        $name = $agency ? ($agency['login']['username'] ?: $agency['name']) : $agencyId;
        return ['role_label' => 'Agency', 'name' => $name ?: 'Agency'];
    }

    if ($role === 'end_client') {
        $client = $clientId ? lpc_load_client($clientId) : null;
        $name = $client ? ($client['login']['username'] ?: $client['identity']['business_name']) : $clientId;
        return ['role_label' => 'Client', 'name' => $name ?: 'Client'];
    }

    return ['role_label' => 'Platform Admin', 'name' => defined('ADMIN_USERNAME') ? ADMIN_USERNAME : 'admin'];
}

/** Echoes the shared admin topbar's branding mark — an agency's own logo when it set one, else the stock hamburger glyph, next to lpc_actor_branding()'s name. One place so all 4 topbar call sites stay in sync. */
function lpc_render_actor_branding() {
    $b = lpc_actor_branding();
    echo '<div style="font-weight:700; font-size:1rem; letter-spacing:.01em; display:flex; align-items:center; gap:8px;">';
    if ($b['logo_url'] !== '') {
        echo '<img src="' . h($b['logo_url']) . '" alt="" style="height:22px;width:auto;display:block;">';
    } else {
        echo '&#9776;';
    }
    echo ' ' . h($b['name']) . '</div>';
}

/**
 * Same as lpc_load_client(), scoped to whoever's actually asking. An
 * Agency Admin gets null (same "not found" shape every existing call site
 * already handles) for a client belonging to a different agency; an End
 * Client gets null for anything but their own single client record — a
 * Platform Super Admin (the default today) is unrestricted, same as before
 * this function existed.
 *
 * Deliberately a drop-in replacement, not a new pattern: every one of the
 * 14+ existing `lpc_load_client($client_id); if (!$client) { ...die... }`
 * call sites found in the redundancy audit can switch to this by name alone
 * — the die-handling logic underneath needs no change. Same reasoning
 * applies to the end_client branch added here, exactly as planned when the
 * agency scoping was first built — same mechanism, not a parallel one.
 */
function lpc_load_client_for_current_actor($client_id) {
    $client = lpc_load_client($client_id);
    if ($client === null) return null;
    $role = lpc_current_actor_role();
    if ($role === 'agency_admin') {
        if (($client['agency_id'] ?? '') !== lpc_current_actor_agency_id()) return null;
    } elseif ($role === 'end_client') {
        if ($client_id !== lpc_current_actor_client_id()) return null;
    }
    return $client;
}

/**
 * Same as lpc_list_clients(), scoped to whoever's actually asking. Loads
 * each client to check its agency_id (same cost class as other places in
 * this codebase that filter a list by loading each item — e.g. CTM's
 * label-prefix filtering), since the id list alone doesn't carry agency_id.
 */
function lpc_list_clients_for_current_actor() {
    $ids = lpc_list_clients();
    $role = lpc_current_actor_role();
    if ($role === 'end_client') {
        $myId = lpc_current_actor_client_id();
        return in_array($myId, $ids, true) ? [$myId] : [];
    }
    if ($role !== 'agency_admin') return $ids;
    $agencyId = lpc_current_actor_agency_id();
    return array_values(array_filter($ids, function ($id) use ($agencyId) {
        $client = lpc_load_client($id);
        return $client !== null && ($client['agency_id'] ?? '') === $agencyId;
    }));
}

/**
 * Agency login credential — same split this codebase already uses for the
 * client API key (lpc_client_secret_file()): the hash never lives inside
 * client_agencies/*.json, which is git-tracked. Agencies are flat JSON
 * files (no per-agency directory like clients have), so this is a sibling
 * flat tree rather than a secrets/ subdirectory — same principle (secret
 * outside the tracked JSON, in a gitignored location), different shape
 * because the entity itself is flat.
 */
function lpc_agency_secrets_dir() {
    return BASE_DIR . '/client_agencies_secrets/';
}

function lpc_agency_secret_file($agency_id) {
    if (!lpc_valid_agency_id($agency_id)) return null;
    return lpc_agency_secrets_dir() . $agency_id . '.txt';
}

function lpc_set_agency_password($agency_id, $plainPassword) {
    $file = lpc_agency_secret_file($agency_id);
    if ($file === null) return false;
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return file_put_contents($file, password_hash($plainPassword, PASSWORD_DEFAULT), LOCK_EX) !== false;
}

function lpc_verify_agency_password($agency_id, $plainPassword) {
    $file = lpc_agency_secret_file($agency_id);
    if ($file === null || !file_exists($file)) return false;
    return password_verify($plainPassword, trim(file_get_contents($file)));
}

/**
 * Finds the agency whose login.username matches (case-sensitive — same as
 * ADMIN_USERNAME's own comparison in admin/login.php), for the login form's
 * single username field. Scans every agency, same cost class as
 * lpc_match_niche_profile() scanning every niche profile — fine at this
 * scale, no index needed.
 */
function lpc_find_agency_by_username($username) {
    if ($username === '') return null;
    foreach (lpc_list_agencies() as $agency_id) {
        $agency = lpc_load_agency($agency_id);
        if ($agency && $agency['login']['username'] === $username) return $agency;
    }
    return null;
}

/**
 * Maps a Stripe subscription id back to the agency it belongs to — the
 * webhook handler's only lookup need, so no agency_id has to round-trip
 * through Stripe's own customer/subscription metadata on every event; the
 * local record IS the source of truth once checkout.session.completed has
 * written it there once.
 */
function lpc_find_agency_by_stripe_subscription_id($subscriptionId) {
    if ($subscriptionId === '') return null;
    foreach (lpc_list_agencies() as $agency_id) {
        $agency = lpc_load_agency($agency_id);
        if ($agency && ($agency['billing']['stripe_subscription_id'] ?? '') === $subscriptionId) return $agency;
    }
    return null;
}

/**
 * Half of the dual-lockout mechanism — the OTHER half (Agency Portal access)
 * is already covered for free by agency_login.php's existing status!=='active'
 * check, since the webhook sets status to 'suspended' on payment failure. This
 * is the grace-period half: a failed payment does NOT immediately pause the
 * agency's live client sites — per the locked "grace period, then pause"
 * offboarding decision, they get $graceDays to resolve it first. Returns
 * true only once that window has actually elapsed with no resolution
 * (payment_failed_at still set — a successful retry clears it, see the
 * webhook handler).
 */
function lpc_agency_grace_period_expired($agency, $graceDays = 15) {
    $failedAt = $agency['billing']['payment_failed_at'] ?? '';
    if ($failedAt === '') return false;
    $failedTs = strtotime($failedAt);
    if ($failedTs === false) return false;
    return (time() - $failedTs) >= ($graceDays * 86400);
}

/**
 * The actual pause — reuses the EXACT same mechanism `client_save.php`'s
 * existing toggle_active action already uses for a single client (flip
 * delivery.active, re-issue the key check, rebuild the manifest), just
 * applied across every client this agency owns instead of one at a time.
 * Not wired to any automatic scheduler yet (no cron exists for this product)
 * — see project memory for what's deliberately deferred.
 */
function lpc_pause_agency_clients($agency_id) {
    $paused = 0;
    foreach (lpc_list_clients() as $client_id) {
        $client = lpc_load_client($client_id);
        if (!$client || ($client['agency_id'] ?? '') !== $agency_id) continue;
        if (!$client['delivery']['active']) continue; // already paused
        $client['delivery']['active'] = false;
        lpc_save_client($client_id, $client);
        lpc_ensure_delivery_key($client_id);
        lpc_rebuild_manifest($client_id);
        $paused++;
    }
    return $paused;
}

/**
 * Same .pluscities_turnstile.json this app's PlusCities profile already
 * reads (includes/wp_builder_profiles/pluscities.php's pc_turnstile_keys())
 * — one shared Site Key/Secret Key pair, since app.pluscities.com (where
 * these two logins live) is one of the domains that key is already scoped
 * to. Blank = Turnstile skipped, same "not configured yet" convention as
 * every other credential-shaped value in this codebase.
 */
function lpc_turnstile_keys(): array {
    $file = BASE_DIR . '/.pluscities_turnstile.json';
    $data = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    return [
        'site_key'   => trim((string) ($data['site_key'] ?? '')),
        'secret_key' => trim((string) ($data['secret_key'] ?? '')),
    ];
}

/** Same verification rule as pc_verify_turnstile() in the PlusCities mu-plugin: no secret key configured = skip (today's behavior unchanged); secret key set = a missing/invalid token is a hard reject. */
function lpc_verify_turnstile(string $token): bool {
    $secret = lpc_turnstile_keys()['secret_key'];
    if ($secret === '') return true;
    if ($token === '') return false;

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return false;

    $body = json_decode($raw, true);
    return !empty($body['success']);
}
