<?php
/**
 * admin/sales_export.php — GET, streams the currently-filtered sales_prospects
 * rows as CSV. Read-only, no CSRF needed (same reasoning admin's other GET
 * exports use — nothing is mutated).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sales_prospecting.php';

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    http_response_code(403); exit('Forbidden');
}

$filters = [
    'niche'        => trim((string) ($_GET['niche'] ?? '')),
    'state'        => trim((string) ($_GET['state'] ?? '')),
    'status'       => trim((string) ($_GET['status'] ?? '')),
    'is_wordpress' => trim((string) ($_GET['is_wordpress'] ?? '')),
    'sort'         => trim((string) ($_GET['sort'] ?? '')),
    'dir'          => trim((string) ($_GET['dir'] ?? '')),
];
$rows = sales_list_prospects(array_filter($filters));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="sales_prospects_' . gmdate('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
$columns = ['id', 'business_name', 'phone', 'domain', 'website_url', 'niche', 'city', 'state',
    'office_cities', 'google_rating', 'google_review_count', 'is_wordpress', 'sitemap_url_count', 'domain_rank',
    'status', 'notes', 'found_at', 'last_checked_at'];
fputcsv($out, $columns);
foreach ($rows as $row) {
    fputcsv($out, array_map(fn($c) => $row[$c] ?? '', $columns));
}
fclose($out);
