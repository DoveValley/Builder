<?php
/**
 * Landing Page Clients — agency grace-period enforcement cron.
 *
 * The other half of the dual-lockout mechanism. Agency Portal access
 * already locks immediately on a failed payment (admin/stripe_webhook.php
 * sets status='suspended', which admin/agency_login.php already blocks —
 * no cron needed for that half). This is the slower half: per the locked
 * "grace period, then pause" offboarding decision, an agency's live CLIENT
 * SITES don't go down immediately — they get GRACE_DAYS to resolve it
 * first. lpc_agency_grace_period_expired()/lpc_pause_agency_clients()
 * (includes/agencies.php) were built and verified
 * this session but never wired to anything automatic — this is that wiring.
 *
 * Usage: php tools/lpc_check_grace_periods.php
 * Cron:  once a day is enough — this is a multi-day window, not a
 *        time-sensitive alert.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

const LPC_GRACE_DAYS = 15;

$pausedAgencies = [];

foreach (lpc_list_agencies() as $agency_id) {
    $agency = lpc_load_agency($agency_id);
    if (!$agency) continue;
    if ($agency['billing']['payment_failed_at'] === '') continue; // not in a failed-payment episode
    if (!lpc_agency_grace_period_expired($agency, LPC_GRACE_DAYS)) continue;

    // lpc_pause_agency_clients() already skips any client whose delivery.active
    // is already false, so re-running this daily against an agency that was
    // paused yesterday is a safe no-op, not a repeated rebuild-every-page cost.
    $paused = lpc_pause_agency_clients($agency_id);
    if ($paused > 0) {
        $pausedAgencies[] = ['agency' => $agency, 'paused' => $paused];
    }
}

if (!$pausedAgencies) {
    exit(0);
}

foreach ($pausedAgencies as $p) {
    echo 'Paused ' . $p['paused'] . ' client site(s) for agency "' . $p['agency']['name']
        . '" (' . $p['agency']['agency_id'] . ') — payment failed '
        . $p['agency']['billing']['payment_failed_at'] . ', grace period (' . LPC_GRACE_DAYS . 'd) expired.' . "\n";
}

if (LPC_ALERT_EMAIL !== '') {
    $body = count($pausedAgencies) . " agency/agencies' client sites were just paused after their "
        . LPC_GRACE_DAYS . "-day payment grace period expired with no resolution:\n\n";
    foreach ($pausedAgencies as $p) {
        $body .= '- ' . $p['agency']['name'] . ' (' . $p['agency']['agency_id'] . ') — '
            . $p['paused'] . " client site(s) paused — agencies.php?edit=" . $p['agency']['agency_id'] . "\n";
    }
    $headers = implode("\r\n", [
        'From: noreply@site-factory.local',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    @mail(LPC_ALERT_EMAIL, count($pausedAgencies) . ' agency grace period(s) expired — client sites paused', $body, $headers);
}
