<?php
/**
 * Landing Page Clients — stale-plugin alert cron.
 *
 * There was no existing "uptime-check-and-alert" module to reuse for this —
 * checked first rather than assuming (admin/infra/lib/uptime.php only
 * checks, nothing in this project dispatches an alert for anything). This
 * is new, minimal, standalone infrastructure: scan every client's plugin
 * status, email once per stale episode (not once per cron tick), reset the
 * moment a client recovers so a future episode alerts fresh.
 *
 * Usage: php tools/lpc_check_alerts.php
 * Cron:  every 30 min — frequent enough to catch staleness reasonably
 *        promptly without being its own cost/log-noise concern.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

if (LPC_ALERT_EMAIL === '') {
    fwrite(STDERR, "lpc_check_alerts: LPC_ALERT_EMAIL not configured in config.php — skipping, nothing sent.\n");
    exit(0);
}

const LPC_ALERT_REPEAT_HOURS = 24; // re-remind once a day while still unresolved, not every 30 min

$staleClients = [];

foreach (lpc_list_clients() as $client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) continue;

    $status = lpc_plugin_status($client_id);

    if ($status !== 'Stale') {
        // Recovered (or never connected at all, which isn't "stale" — it's a
        // separate, expected state during onboarding, not an alert condition).
        if ($client['delivery']['last_stale_alert_at'] !== '') {
            $client['delivery']['last_stale_alert_at'] = '';
            lpc_save_client($client_id, $client);
        }
        continue;
    }

    $lastAlert = $client['delivery']['last_stale_alert_at'];
    $dueForAlert = $lastAlert === '' || (strtotime($lastAlert) < time() - LPC_ALERT_REPEAT_HOURS * 3600);
    if (!$dueForAlert) continue;

    $staleClients[] = $client;
    $client['delivery']['last_stale_alert_at'] = gmdate('c');
    lpc_save_client($client_id, $client);
}

if (!$staleClients) {
    exit(0);
}

$body = count($staleClients) . " Landing Page Client(s) have a stale plugin connection "
    . "(no check-in within twice their refresh interval):\n\n";
foreach ($staleClients as $c) {
    $body .= '- ' . $c['identity']['business_name'] . ' (' . $c['identity']['domain'] . ') — '
        . 'client.php?id=' . $c['client_id'] . "\n";
}
$body .= "\nThis repeats once every " . LPC_ALERT_REPEAT_HOURS . "h per client while still unresolved, "
    . "and resets the moment a client's status recovers.\n";

$headers = implode("\r\n", [
    'From: noreply@site-factory.local',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

$subject = count($staleClients) . ' Landing Page Client(s) need attention';
if (!mail(LPC_ALERT_EMAIL, $subject, $body, $headers)) {
    fwrite(STDERR, "lpc_check_alerts: mail() failed — check this server's mail transport.\n");
    exit(1);
}

echo "Alerted on " . count($staleClients) . " client(s): "
    . implode(', ', array_column($staleClients, 'client_id')) . "\n";
