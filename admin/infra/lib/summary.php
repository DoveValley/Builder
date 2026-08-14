<?php
/**
 * infra/lib/summary.php — a cheap, read-only headline of the fleet.
 *
 * Used by the Site Factory panel (admin/sites.php) so the domain fleet's state is
 * visible on the front page instead of three clicks deep. Deliberately standalone:
 * it opens fleet.db itself rather than going through lib/state.php, so loading it
 * runs no schema migrations and has no side effects. Every failure mode returns
 * ['ok' => false] — a missing or unreadable database must never break the panel.
 */

/**
 * @return array{ok:bool,total:int,owned:int,ready:int,begin:int,renew_unconfirmed:int}
 */
function infra_fleet_summary(): array
{
    $out = ['ok' => false, 'total' => 0, 'owned' => 0, 'ready' => 0, 'begin' => 0, 'renew_unconfirmed' => 0];

    $file = __DIR__ . '/../state/fleet.db';
    if (!is_file($file) || !is_readable($file)) return $out;

    try {
        $db = new PDO('sqlite:' . $file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($db->query('SELECT status, COUNT(*) FROM domains GROUP BY status') as $r) {
            $n = (int) $r[1];
            $out['total'] += $n;
            $k = (string) $r[0];
            if (isset($out[$k])) $out[$k] = $n;
        }

        // Owned domains whose auto-renew we have not positively confirmed as "yes".
        // These are the ones that can quietly expire, so they get the warning line.
        $q = $db->query("SELECT COUNT(*) FROM domains WHERE status = 'owned' AND COALESCE(auto_renew, '') <> 'yes'");
        $out['renew_unconfirmed'] = (int) $q->fetchColumn();

        $out['ok'] = true;
    } catch (Throwable $e) {
        return ['ok' => false, 'total' => 0, 'owned' => 0, 'ready' => 0, 'begin' => 0, 'renew_unconfirmed' => 0];
    }

    return $out;
}
