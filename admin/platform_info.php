<?php
/**
 * Platform Information — super_admin-only diagnostics about the platform
 * itself, starting with "Who's Logged In" (live sessions + a daily login
 * history, both sourced from includes/platform_sessions.php). More tabs can
 * be added to $TABS below as they're needed; the tab-bar scaffold already
 * supports it.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_sessions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Platform+information+is+for+the+platform+operator+only');
    exit;
}

$TABS = [
    'who_logged_in' => "Who's Logged In",
];
$tab = $_GET['tab'] ?? 'who_logged_in';
if (!isset($TABS[$tab])) $tab = 'who_logged_in';

function platform_info_fmt_duration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform Information — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-status-unknown { background:#f1f5f9; color:#64748b; }
</style>
</head>
<?php lpc_admin_open('platform_info', 'Platform Information'); ?>
<div class="pcadmin-subtabs">
    <?php foreach ($TABS as $key => $label): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="platform_info.php?tab=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'who_logged_in'):
    $sessions = platform_sessions_load();
    $now = time();

    $rows = [];
    foreach ($sessions as $sid => $s) {
        $online = empty($s['logout_at']) && ($now - ($s['last_seen'] ?? 0)) <= PLATFORM_SESSION_ONLINE_WINDOW;
        $identity = lpc_actor_identity_label($s['role'] ?? 'super_admin', $s['agency_id'] ?? null, $s['client_id'] ?? null);
        $endAt = $s['logout_at'] ?? $s['last_seen'] ?? $s['login_at'];
        $rows[] = [
            'sid'      => $sid,
            'online'   => $online,
            'role'     => $identity['role_label'],
            'name'     => $identity['name'],
            'ip'       => $s['ip'] ?? '',
            'login_at' => (int) ($s['login_at'] ?? 0),
            'last_seen'=> (int) ($s['last_seen'] ?? 0),
            'end_at'   => (int) $endAt,
            'status'   => $online ? 'Online' : (!empty($s['logout_at']) ? 'Logged out' : 'Timed out'),
            'duration' => max(0, (int) $endAt - (int) ($s['login_at'] ?? 0)),
        ];
    }
    usort($rows, fn($a, $b) => $b['login_at'] <=> $a['login_at']);
    $online = array_values(array_filter($rows, fn($r) => $r['online']));
    ?>

    <h3 style="margin-top:0;">Currently Logged In</h3>
    <?php if (!$online): ?>
        <p class="meta">Nobody's actively logged in right now.</p>
    <?php else: ?>
        <table class="lpc-table" style="margin-bottom:28px;">
            <thead><tr><th>Who</th><th>Role</th><th>Logged In Since</th><th>Last Active</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($online as $r): ?>
                <tr>
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><?= h($r['role']) ?></td>
                    <td><?= h(date('M j, g:i a', $r['login_at'])) ?></td>
                    <td><?= h(platform_info_fmt_duration($now - $r['last_seen'])) ?> ago</td>
                    <td><?= h($r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3>Login History</h3>
    <p class="meta" style="margin-bottom:12px;">
        Grouped by day, newest first. "Timed out" means the session went quiet without an explicit
        logout — its end time (and duration) is its last recorded activity, not an exact sign-off.
        Kept for 180 days.
    </p>
    <?php
    $byDay = [];
    foreach ($rows as $r) {
        $day = date('Y-m-d', $r['login_at']);
        $byDay[$day][] = $r;
    }
    krsort($byDay);
    if (!$byDay): ?>
        <p class="meta">No login history recorded yet.</p>
    <?php else: foreach ($byDay as $day => $dayRows): ?>
        <h4 style="margin:20px 0 8px;font-family:'Space Grotesk',sans-serif;"><?= h(date('l, F j, Y', strtotime($day))) ?></h4>
        <table class="lpc-table" style="margin-bottom:8px;">
            <thead><tr><th>Who</th><th>Role</th><th>Logged In</th><th>Ended</th><th>Duration</th><th>Status</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($dayRows as $r): ?>
                <tr>
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><?= h($r['role']) ?></td>
                    <td><?= h(date('g:i a', $r['login_at'])) ?></td>
                    <td><?= $r['online'] ? '&mdash;' : h(date('g:i a', $r['end_at'])) ?></td>
                    <td><?= h(platform_info_fmt_duration($r['duration'])) ?></td>
                    <td>
                        <span class="lpc-status lpc-status-<?= $r['online'] ? 'running' : ($r['status'] === 'Logged out' ? 'stopped' : 'unknown') ?>">
                            <?= $r['online'] ? 'Online' : h($r['status']) ?>
                        </span>
                    </td>
                    <td><?= h($r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; endif; ?>

<?php endif; ?>

<?php lpc_admin_close(); ?>
