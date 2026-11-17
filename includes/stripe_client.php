<?php
/**
 * Stripe — one module, reused everywhere, same rule this codebase already
 * applies to every other outside service (see CLAUDE.md's API-modules
 * table). Agency platform billing only: subscription creation, the dual-
 * lockout webhook handler, nothing agency-to-customer (explicitly deferred,
 * no Stripe Connect — see the locked decision in project memory).
 *
 * Reuses admin/infra/lib/http.php's infra_http() rather than hand-rolling a
 * second curl wrapper — that file is explicitly self-contained and callable
 * from outside the infra console for exactly this reason.
 */

require_once __DIR__ . '/../admin/infra/lib/http.php';

function stripe_has_creds() {
    return STRIPE_SECRET_KEY !== '';
}

/**
 * @param string $method GET|POST|DELETE
 * @param string $path   e.g. 'customers', 'subscriptions/sub_123'
 * @param array  $params form fields — Stripe's API takes
 *                        application/x-www-form-urlencoded, NOT JSON, for
 *                        every endpoint used here.
 * @return array{ok:bool, code:int, data:array, error:string}
 */
function stripe_request($method, $path, $params = []) {
    if (!stripe_has_creds()) {
        return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'Stripe secret key not configured'];
    }
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $opts = [
        'headers' => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        'timeout' => 15,
    ];
    if ($method === 'GET' && $params) {
        $url .= '?' . http_build_query($params);
    } elseif ($params) {
        $opts['headers'][] = 'Content-Type: application/x-www-form-urlencoded';
        $opts['body'] = http_build_query($params);
    }
    $r = infra_http($method, $url, $opts);
    $data = $r['json'] ?? [];
    if (!is_array($data)) $data = [];
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    return [
        'ok'    => $ok,
        'code'  => $r['code'],
        'data'  => $data,
        'error' => $ok ? '' : ($data['error']['message'] ?? $r['error'] ?? 'Stripe request failed'),
    ];
}

/**
 * Stripe Checkout, not a raw Subscription — confirmed against the real API
 * that a bare POST /v1/subscriptions 400s with "This customer has no
 * attached payment source," since nothing in this flow collects a card. A
 * hosted Checkout page is the standard way to collect payment + create the
 * subscription in one step without building our own card-entry UI; it also
 * supports inline price_data[product_data] (the Subscriptions endpoint
 * does NOT — a separate real 400 hit and fixed before landing on this).
 * Returns a real URL for Scott to send the agency, or to redirect them to
 * directly. Final customer/subscription ids get attached to the agency
 * record from the webhook's checkout.session.completed event, not here —
 * this only creates the session, it doesn't know yet whether it's been paid.
 */
function stripe_create_checkout_session($agency, $monthlyFeeUsd, $successUrl, $cancelUrl) {
    $params = [
        'mode' => 'subscription',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => 'Platform subscription — ' . $agency['name'],
        'line_items[0][price_data][recurring][interval]' => 'month',
        'line_items[0][price_data][unit_amount]' => (int) round($monthlyFeeUsd * 100),
        'line_items[0][quantity]' => 1,
        'metadata[agency_id]' => $agency['agency_id'],
        'subscription_data[metadata][agency_id]' => $agency['agency_id'],
    ];
    // Reuse the existing Stripe Customer if this agency already has one
    // (e.g. re-subscribing after a cancellation) rather than letting
    // Checkout spawn a duplicate customer record for the same agency.
    if (!empty($agency['billing']['stripe_customer_id'])) {
        $params['customer'] = $agency['billing']['stripe_customer_id'];
    } elseif (!empty($agency['login']['username'])) {
        $params['customer_email'] = $agency['login']['username'];
    }
    return stripe_request('POST', 'checkout/sessions', $params);
}

function stripe_cancel_subscription($subscriptionId) {
    return stripe_request('DELETE', 'subscriptions/' . urlencode($subscriptionId));
}

function stripe_get_customer($customerId) {
    return stripe_request('GET', 'customers/' . urlencode($customerId));
}

function stripe_get_subscription($subscriptionId) {
    return stripe_request('GET', 'subscriptions/' . urlencode($subscriptionId));
}

/**
 * Closes a real, known gap: editing monthly_fee_usd on an agency that
 * already has an active real subscription previously only changed what the
 * NEXT checkout link would charge — the actual live subscription kept
 * billing the old amount forever. Prices are immutable in Stripe once
 * created, so this creates a new Price (reusing the EXISTING subscription
 * item's product, not a new one, so the Stripe dashboard doesn't accumulate
 * a fresh "Platform subscription" product every time a price changes) and
 * swaps the subscription's one line item onto it. Proration is Stripe's own
 * default behavior — not overridden, since there's no reason to deviate
 * from Stripe's standard mid-cycle billing-adjustment math here.
 */
function stripe_update_subscription_price($subscriptionId, $newMonthlyFeeUsd) {
    $sub = stripe_get_subscription($subscriptionId);
    if (!$sub['ok']) return $sub;
    $item = $sub['data']['items']['data'][0] ?? null;
    if (!$item) return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'Subscription has no line item to update'];

    $price = stripe_request('POST', 'prices', [
        'currency' => 'usd',
        'unit_amount' => (int) round($newMonthlyFeeUsd * 100),
        'recurring[interval]' => 'month',
        'product' => $item['price']['product'],
    ]);
    if (!$price['ok']) return $price;

    return stripe_request('POST', 'subscriptions/' . urlencode($subscriptionId), [
        'items[0][id]' => $item['id'],
        'items[0][price]' => $price['data']['id'],
    ]);
}

/**
 * Stripe's documented webhook signature scheme (v1): HMAC-SHA256 of
 * "{timestamp}.{raw_payload}" using the webhook's signing secret, compared
 * against the v1= value(s) in the Stripe-Signature header. Also enforces a
 * 5-minute tolerance on the timestamp — without it, a captured/replayed
 * webhook payload would verify forever.
 */
function stripe_verify_webhook_signature($payload, $sigHeader, $secret) {
    if ($secret === '' || $sigHeader === '') return false;
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 2) $parts[$kv[0]][] = $kv[1];
    }
    $timestamp = $parts['t'][0] ?? '';
    $signatures = $parts['v1'] ?? [];
    if ($timestamp === '' || !$signatures) return false;
    if (abs(time() - (int) $timestamp) > 300) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
