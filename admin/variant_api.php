<?php
/**
 * Variant Engine admin API — variant assignment (propose/reroll/approve) and launching/
 * polling the content + build passes. Mirrors admin/multisite_api.php's auth/CSRF/active-
 * batch conventions exactly; kept in its own file (not bolted onto multisite_api.php) so the
 * blast radius of this whole feature stays visible as one file, not scattered edits.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch.php';
require_once __DIR__ . '/../includes/multisite/variant_engine.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); echo json_encode(['error' => 'No active site selected.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$action   = $_REQUEST['action'] ?? '';
$masterId = ACTIVE_SITE_ID;

$active = ms_active_batch();
if (!$active) { http_response_code(400); echo json_encode(['error' => 'No batch open. Open a batch from the Site Factory panel first.']); exit; }
$batchId = $active['batch_id'];

/** Shapes a plan for the browser: real pool descriptions alongside each row's picks, so the
 * review table can show "Split — hero right, text left..." not just the id "02-split". */
function ms_variant_plan_payload(string $masterId, string $batchId): array
{
    $plan = ms_variant_read_plan($masterId, $batchId);
    $dims = ms_variant_dimensions();
    $poolsById = [];
    foreach ($dims as $d) {
        foreach (ms_variant_pool($masterId, $d) as $opt) {
            $poolsById[$d][$opt['id']] = $opt;
        }
    }
    $rows = [];
    foreach ($plan['rows'] ?? [] as $domain => $row) {
        $picks = [];
        foreach ($dims as $d) {
            $id = $row['variant'][$d] ?? null;
            $picks[$d] = $id === null ? null : ($poolsById[$d][$id] ?? ['id' => $id, 'name' => $id, 'description' => '']);
        }
        $rows[] = [
            'domain' => $domain, 'picks' => $picks,
            'approved' => !empty($row['approved']),
            'content_generated' => !empty($row['content_generated']),
            'content_needs_review' => !empty($row['content_needs_review']),
            'content_approved' => !empty($row['content_approved']),
            'built' => !empty($row['built']),
        ];
    }
    return ['approved' => !empty($plan['approved']), 'proposed_at' => $plan['proposed_at'] ?? null,
            'dimensions' => $dims, 'rows' => $rows];
}

switch ($action) {

    case 'variant_propose':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!is_file(ms_batch_dir($masterId, $batchId) . '/params.csv')) {
            echo json_encode(['error' => 'No target list stored — upload it first (step 1).']); break;
        }
        ms_variant_propose_plan($masterId, $batchId);
        echo json_encode(ms_variant_plan_payload($masterId, $batchId));
        break;

    case 'variant_plan_status':
        echo json_encode(ms_variant_plan_payload($masterId, $batchId));
        break;

    case 'variant_reroll':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $domain = trim((string) ($_POST['domain'] ?? ''));
        $dimension = trim((string) ($_POST['dimension'] ?? ''));
        try {
            ms_variant_reroll_row($masterId, $batchId, $domain, $dimension);
            echo json_encode(ms_variant_plan_payload($masterId, $batchId));
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'variant_approve':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        ms_variant_approve_plan($masterId, $batchId);
        echo json_encode(ms_variant_plan_payload($masterId, $batchId));
        break;

    case 'run_content':
    case 'run_build':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $passName = $action === 'run_content' ? 'content' : 'build';
        $latest = ms_variant_latest_run($masterId, $batchId);
        if ($latest && ($latest['state'] ?? '') === 'running') {
            echo json_encode(['error' => 'A variant-engine run is already in progress for this batch.', 'run_id' => $latest['run_id']]);
            break;
        }
        $opts = ['only' => trim((string) ($_POST['only'] ?? '')), 'force' => !empty($_POST['force']),
                 'dry_run' => !empty($_POST['dry_run'])];
        try {
            $runId = ms_variant_launch_pass($masterId, $batchId, $passName, $opts);
            echo json_encode(['started' => true, 'run_id' => $runId]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'run_status':
        $runId = trim((string) ($_GET['run_id'] ?? ''));
        if ($runId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $runId)) {
            $status = ms_variant_latest_run($masterId, $batchId);
        } else {
            $status = ms_variant_run_status($masterId, $batchId, $runId);
        }
        echo json_encode($status ?: ['none' => true]);
        break;

    case 'content_review_list':
        $plan = ms_variant_read_plan($masterId, $batchId);
        $out = [];
        foreach ($plan['rows'] ?? [] as $domain => $row) {
            if (empty($row['content_generated'])) continue;
            $slug = ms_batch_output_dir($masterId, $batchId, $domain); // reuse the exact slug helper
            $slug = basename($slug);
            $contentFile = ms_batch_dir($masterId, $batchId) . '/variant_sites/' . $slug . '/content.json';
            $content = is_file($contentFile) ? json_decode((string) file_get_contents($contentFile), true) : null;
            $out[] = [
                'domain' => $domain,
                'content_approved' => !empty($row['content_approved']),
                'needs_review' => !empty($row['content_needs_review']),
                'hero' => $content['hero'] ?? null,
                'legal_status' => array_map(fn($k) => $content['legal'][$k]['meaning_lock']['status'] ?? 'unknown',
                                             ['privacy', 'terms', 'disclaimer', 'about']),
                'faq_count' => count($content['faqs'] ?? []),
                'guide_count' => count($content['guides'] ?? []),
            ];
        }
        echo json_encode(['rows' => $out]);
        break;

    case 'content_row_approve':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $domain = trim((string) ($_POST['domain'] ?? ''));
        $approved = !empty($_POST['approved']);
        try {
            ms_variant_approve_content($masterId, $batchId, $domain, $approved);
            echo json_encode(ms_variant_plan_payload($masterId, $batchId));
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'seo_report':
        $domain = trim((string) ($_GET['domain'] ?? ''));
        $slug = basename(ms_batch_output_dir($masterId, $batchId, $domain));
        $file = ms_batch_dir($masterId, $batchId) . '/variant_sites/' . $slug . '/seo_report.json';
        echo json_encode(is_file($file) ? json_decode((string) file_get_contents($file), true) : ['none' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
