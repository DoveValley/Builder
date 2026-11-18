<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Demo+sites+are+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: lpc_demo_sites.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: lpc_demo_sites.php?msg=error:Invalid+request+token');
    exit;
}

const LPC_DEMO_BASE = '/opt/landing-page-clients';
const LPC_PLUGIN_SRC = __DIR__ . '/../wp-plugin/landing-page-client';
const LPC_PROVISION_SCRIPT = LPC_DEMO_BASE . '/new_demo_site.sh';

$action = $_POST['action'] ?? '';
$niche  = trim($_POST['niche'] ?? '');

// Same validation as the niche slug anywhere else in this codebase — also
// doubles as a path-traversal guard, since $niche feeds directly into a
// shell command and a filesystem path below.
function lpc_demo_valid_niche(string $n): bool {
    return $n !== '' && preg_match('/^[a-z0-9\-]+$/', $n) === 1;
}

if (!lpc_demo_valid_niche($niche)) {
    header('Location: lpc_demo_sites.php?msg=error:Invalid+niche+slug');
    exit;
}

$dir = LPC_DEMO_BASE . '/demo-' . $niche;

if ($action === 'create') {
    $theme = trim($_POST['theme'] ?? '');
    if ($theme !== '' && !preg_match('/^[a-z0-9\-]+$/', $theme)) {
        header('Location: lpc_demo_sites.php?msg=error:Invalid+theme+slug');
        exit;
    }
    $cmd = 'bash ' . escapeshellarg(LPC_PROVISION_SCRIPT) . ' ' . escapeshellarg($niche);
    if ($theme !== '') $cmd .= ' --theme=' . escapeshellarg($theme);
    $cmd .= ' 2>&1';
    set_time_limit(120);
    $output = shell_exec($cmd);
    $ok = $output !== null && str_contains((string) $output, '✓ demo-');
    header('Location: lpc_demo_sites.php?msg=' . ($ok ? 'success' : 'error') . ':' . rawurlencode(trim((string) $output)));
    exit;
}

if (!is_dir($dir)) {
    header('Location: lpc_demo_sites.php?msg=error:That+demo+site+does+not+exist');
    exit;
}

if ($action === 'redeploy_plugin') {
    $dst = $dir . '/wp-content/plugins/landing-page-client';
    $cmd = 'rsync -a --delete ' . escapeshellarg(rtrim(LPC_PLUGIN_SRC, '/') . '/') . ' ' . escapeshellarg($dst . '/')
         . ' && chown -R www-data:www-data ' . escapeshellarg($dst) . ' 2>&1';
    $output = shell_exec($cmd);
    $ok = $output === null || trim((string) $output) === '';
    $msg = $ok ? 'Plugin redeployed from the repo' : ('Redeploy may have failed: ' . trim((string) $output));
    header('Location: lpc_demo_sites.php?msg=' . ($ok ? 'success' : 'error') . ':' . rawurlencode($msg));
    exit;
}

if ($action === 'clear_cache') {
    $cli = $dir . '/wp-cli.phar';
    $cmd = 'sudo -u www-data php ' . escapeshellarg($cli) . ' plugin deactivate wp-super-cache --path=' . escapeshellarg($dir) . ' 2>&1';
    shell_exec($cmd);
    @unlink($dir . '/wp-content/advanced-cache.php');
    // Belt-and-braces: make sure WP_CACHE is off even if a prior edit re-enabled it.
    $cfg = $dir . '/wp-config.php';
    $contents = @file_get_contents($cfg);
    if ($contents !== false) {
        $contents = preg_replace("/define\\('WP_CACHE',\\s*true\\);/", "define('WP_CACHE', false);", $contents);
        @file_put_contents($cfg, $contents);
    }
    header('Location: lpc_demo_sites.php?msg=success:' . rawurlencode('Cache cleared and disabled'));
    exit;
}

if ($action === 'delete') {
    $port = trim((string) @file_get_contents($dir . '/.port'));
    if ($port !== '' && ctype_digit($port)) {
        // Kill whatever php -S is bound to this site's port — pkill -f matches
        // the router.php path, which is unique per demo site.
        shell_exec('pkill -f ' . escapeshellarg('php -S 0.0.0.0:' . $port . ' ' . $dir . '/router.php') . ' 2>/dev/null');
    }
    shell_exec('rm -rf ' . escapeshellarg($dir));
    header('Location: lpc_demo_sites.php?msg=success:' . rawurlencode('Deleted demo-' . $niche));
    exit;
}

header('Location: lpc_demo_sites.php?msg=error:Unknown+action');
