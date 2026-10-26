<?php
/**
 * infra/actions/elocal_library_download.php — stream one eLocal library file back out.
 *
 * GET, not POST: this only reads a file, it changes no state, so it follows the same
 * convention as the Past Runs table's xlsx download links (plain <a href>, no CSRF —
 * CSRF protects state-changing POSTs, not a read-only file fetch). Gated by
 * bootstrap.php's admin-session check like every other page in this console, rather
 * than relying on the file living somewhere Apache happens not to serve directly.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/research.php';

$niche = infra_niche_slug((string) ($_GET['niche'] ?? ''));
$id = (string) ($_GET['id'] ?? '');

if ($niche === '' || !isset(infra_niches()[$niche]) || $id === '') {
    http_response_code(404);
    exit('Not found.');
}

$lib = infra_elocal_library_load($niche);
$found = null;
foreach ($lib['files'] as $f) if ($f['id'] === $id) { $found = $f; break; }

$path = $found ? infra_elocal_library_dir($niche) . '/' . $found['stored_name'] : null;
if (!$found || !$path || !is_file($path)) {
    http_response_code(404);
    exit('File not found — it may have been deleted from the library.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addslashes($found['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
