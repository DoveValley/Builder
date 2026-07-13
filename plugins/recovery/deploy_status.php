<?php
/**
 * Recovery plugin — deploy status poll endpoint (for the panel progress meter).
 * Auth-required GET. Returns the current deploy_status.json for the active recovery site.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo '{"error":"auth"}'; exit; }

$dir = (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR !== '') ? ACTIVE_SITE_DIR : (BASE_DIR . '/sites/recovery-site');
$f   = $dir . '/deploy_status.json';
if (!is_file($f)) { echo '{"phase":"idle","running":false}'; exit; }
echo file_get_contents($f) ?: '{"phase":"idle","running":false}';
