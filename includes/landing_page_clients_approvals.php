<?php
/**
 * Two-stage content approval queue — the Platform Admin reviews first, then
 * the owning agency, before a page actually goes live. Defaults to applying
 * uniformly to every agency, including the platform's own house agency
 * (agency_id 'scotts-agency') — but is a real per-agency on/off switch
 * (agency.requires_approval_gate, admin/settings.php), not a hardcoded
 * exemption for one agency. Platform-Admin-only; an agency can never flip
 * its own switch off.
 *
 * The mechanical state-advance functions below (platform_approve/
 * agency_approve/reject) do NOT check who's calling — same boundary
 * principle as lpc_load_client_for_current_actor() vs. the internal
 * library functions it wraps: the role check belongs in the admin handler
 * (admin/approvals_save.php), not duplicated inside every function here.
 */

function lpc_requires_approval_gate($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return false;
    $agencyId = $client['agency_id'] ?? '';
    if ($agencyId === '') return false;
    $agency = lpc_load_agency($agencyId);
    // Unknown agency (e.g. a bad/stale reference) fails safe to gated,
    // rather than silently publishing with no review.
    if (!$agency) return true;
    return !empty($agency['requires_approval_gate']);
}

/**
 * Drop-in replacement for lpc_publish_page() at every admin-triggered
 * publish call site. For a client the gate doesn't apply to, this IS
 * lpc_publish_page() — zero behavior change. For a gated client, this
 * submits into the queue instead of going live; the real lpc_publish_page()
 * only runs once lpc_agency_approve_page() clears the second stage.
 *
 * Re-submitting an already in-flight page (e.g. editing while it's sitting
 * at pending_platform_review) is a no-op on the stage itself — never skips
 * ahead, never rewinds a page that's already past platform review back to
 * stage 1 just because someone clicked Publish again.
 */
function lpc_publish_page_gated($client_id, $page_id) {
    if (!lpc_requires_approval_gate($client_id)) {
        return lpc_publish_page($client_id, $page_id);
    }
    $page = lpc_load_page($client_id, $page_id);
    if (!$page) return false;
    if ($page['approval_stage'] !== 'pending_agency_review') {
        $page['approval_stage'] = 'pending_platform_review';
        $page['approval_note']  = '';
    }
    return lpc_save_page($client_id, $page_id, $page);
}

function lpc_platform_approve_page($client_id, $page_id) {
    $page = lpc_load_page($client_id, $page_id);
    if (!$page || $page['approval_stage'] !== 'pending_platform_review') return false;
    $page['approval_stage'] = 'pending_agency_review';
    return lpc_save_page($client_id, $page_id, $page);
}

/**
 * Clears the second stage AND actually publishes — this is the one place
 * a gated page goes live. lpc_publish_page() reloads the page fresh from
 * disk itself, so the 'approved' stage saved here is already on record by
 * the time it runs; two writes, both idempotent, simpler than threading the
 * stage change through lpc_publish_page()'s own save.
 */
function lpc_agency_approve_page($client_id, $page_id) {
    $page = lpc_load_page($client_id, $page_id);
    if (!$page || $page['approval_stage'] !== 'pending_agency_review') return false;
    $page['approval_stage'] = 'approved';
    if (!lpc_save_page($client_id, $page_id, $page)) return false;
    return lpc_publish_page($client_id, $page_id);
}

/**
 * Either stage can reject — sends it back to square one with a note so
 * whoever submitted it knows why, rather than a silent disappearance from
 * the queue. Does not touch the page's own content/status; a rejected page
 * stays exactly as drafted, just not live, until resubmitted.
 */
function lpc_reject_page($client_id, $page_id, $note = '') {
    $page = lpc_load_page($client_id, $page_id);
    if (!$page || !in_array($page['approval_stage'], ['pending_platform_review', 'pending_agency_review'], true)) return false;
    $page['approval_stage'] = 'rejected';
    $page['approval_note']  = trim($note);
    return lpc_save_page($client_id, $page_id, $page);
}

/**
 * Every page across the given client ids currently sitting at $stage, with
 * enough context (client name/id, page id) to render a queue row without
 * the caller re-loading anything. Caller decides which client ids to scan —
 * lpc_list_clients() (every agency, Scott's own queue) or lpc_list_clients_
 * for_current_actor() (one agency's own queue) — this function stays
 * role-agnostic, same split as the scoping functions it composes with.
 */
function lpc_list_pages_by_approval_stage($stage, $client_ids) {
    $rows = [];
    foreach ($client_ids as $client_id) {
        $client = lpc_load_client($client_id);
        if (!$client) continue;
        foreach (lpc_list_pages($client_id) as $page_id) {
            $page = lpc_load_page($client_id, $page_id);
            if ($page && $page['approval_stage'] === $stage) {
                $rows[] = [
                    'client_id'     => $client_id,
                    'business_name' => $client['identity']['business_name'] ?: $client_id,
                    'page_id'       => $page_id,
                    'page_title'    => $page['seo']['page_title'] ?: $page_id,
                    'updated_at'    => $page['updated_at'],
                ];
            }
        }
    }
    return $rows;
}
