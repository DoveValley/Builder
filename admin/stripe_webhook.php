<?php
/**
 * Stripe webhook — public, unauthenticated by session (Stripe isn't an
 * admin), validated by signature instead (same "public but validated"
 * shape as contact_send.php's CSRF+honeypot, just a different mechanism
 * suited to a server-to-server caller instead of a browser).
 *
 * Drives the dual-lockout mechanism's "Agency Portal access" half for
 * free — agency_login.php already blocks any non-'active' status, so
 * setting status='suspended' here is the whole enforcement, no separate
 * lockout code needed. The "client sites" half is NOT immediate — see
 * lpc_agency_grace_period_expired()/lpc_pause_agency_clients(), not
 * called from here, per the locked "grace period, then pause" decision.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_webhook_signature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_payload']);
    exit;
}

$type = $event['type'];
$obj  = $event['data']['object'] ?? [];

if ($type === 'checkout.session.completed') {
    $agencyId = $obj['metadata']['agency_id'] ?? '';
    $agency = $agencyId !== '' ? lpc_load_agency($agencyId) : null;
    if ($agency) {
        // A completed checkout is a deliberate, successful new payment —
        // unlike invoice.payment_succeeded below (a routine recurring
        // auto-charge), this always activates regardless of prior status,
        // since it means someone just went through onboarding/re-onboarding.
        $agency['billing']['stripe_customer_id']     = $obj['customer'] ?? $agency['billing']['stripe_customer_id'];
        $agency['billing']['stripe_subscription_id'] = $obj['subscription'] ?? $agency['billing']['stripe_subscription_id'];
        $agency['billing']['payment_failed_at']       = '';
        $agency['status'] = 'active';
        lpc_save_agency($agencyId, $agency);
    }
} elseif ($type === 'invoice.payment_failed') {
    $subId = $obj['subscription'] ?? '';
    $agency = lpc_find_agency_by_stripe_subscription_id($subId);
    if ($agency) {
        $agency['status'] = 'suspended';
        $agency['billing']['payment_failed_at'] = gmdate('c');
        lpc_save_agency($agency['agency_id'], $agency);
    }
} elseif ($type === 'invoice.payment_succeeded') {
    $subId = $obj['subscription'] ?? '';
    $agency = lpc_find_agency_by_stripe_subscription_id($subId);
    // Only auto-reactivate a suspension THIS webhook caused — a manual
    // Scott suspension never sets payment_failed_at, so it's untouched by
    // an unrelated successful charge.
    if ($agency && $agency['billing']['payment_failed_at'] !== '') {
        $agency['status'] = 'active';
        $agency['billing']['payment_failed_at'] = '';
        lpc_save_agency($agency['agency_id'], $agency);
    }
} elseif ($type === 'customer.subscription.deleted') {
    $agency = lpc_find_agency_by_stripe_subscription_id($obj['id'] ?? '');
    if ($agency) {
        $agency['status'] = 'suspended';
        lpc_save_agency($agency['agency_id'], $agency);
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
