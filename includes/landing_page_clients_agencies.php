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
    if (lpc_current_actor_role() !== 'agency_admin') {
        return $default;
    }
    $agency = lpc_load_agency(lpc_current_actor_agency_id());
    if (!$agency) {
        return $default;
    }
    return [
        'name'     => $agency['branding']['display_name'] ?: $default['name'],
        'logo_url' => $agency['branding']['logo_url'] ?: $default['logo_url'],
    ];
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
