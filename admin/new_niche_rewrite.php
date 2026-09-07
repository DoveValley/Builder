<?php
// Rewrite this niche's inherited homepage content — the cross-niche counterpart to
// bulk_generate's per-service rewrite (Tier 2). A new niche is born by cloning an
// existing one (clone_site.py), which carries every static (non-AI) block forward
// verbatim from the source niche. This is a MANUAL, one-time action — not automatic —
// because it needs the operator's own real brief (service noun, tone, guardrails)
// already filled in on the Niche Brief tab; running it at clone time would rewrite
// using the OLD niche's still-copied brief data, which is exactly the content it's
// supposed to replace.
// POST only. CSRF protected. Reuses generate.py's --rewrite-template (same engine,
// same field-scoping and {token} safety checks as the Templates tab's AI rewrite).

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=new_niche'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=new_niche&msg=error:Invalid+request+token');
    exit;
}
if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

function _nnr_redirect(string $type, string $text): void {
    header('Location: index.php?tab=new_niche&msg=' . $type . ':' . rawurlencode($text));
    exit;
}

$baseService = trim($_POST['base_service'] ?? '');
if ($baseService === '') {
    _nnr_redirect('error', 'Enter the niche this master was cloned from (its service, e.g. "Pest Control").');
}

$brief = json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/niche_brief.json'), true) ?: [];
$newService = trim($brief['service_noun'] ?? '');
if ($newService === '') {
    _nnr_redirect('error', 'Fill in this niche\'s own Service noun on the Niche Brief tab before rewriting.');
}

// A niche-level rewrite has no real city yet — {city}/{SS}/{business} must survive as
// literal tokens for Pass B to resolve later, per domain. Same reason bulk_generate's
// row keyword column carries a literal {city} token instead of a resolved one.
$keyword = $newService . ' {city}';

set_time_limit(0);
$siteJsonPath = DATA_FILE;
$out = tempnam(sys_get_temp_dir(), 'hprw_out_');
$cmd = 'python3 ' . escapeshellarg(BASE_DIR . '/generate.py')
     . ' --site ' . escapeshellarg(ACTIVE_SITE_ID)
     . ' --rewrite-template ' . escapeshellarg($siteJsonPath)
     . ' --rewrite-out ' . escapeshellarg($out)
     . ' --service ' . escapeshellarg($newService)
     . ' --base-service ' . escapeshellarg($baseService)
     . ' --keyword ' . escapeshellarg($keyword)
     . ' 2>&1';
$env = 'ANTHROPIC_API_KEY=' . escapeshellarg(ANTHROPIC_API_KEY) . ' ';
$stdout = shell_exec($env . $cmd);

$rewritten = is_file($out) ? json_decode((string)file_get_contents($out), true) : null;
$lines = array_filter(array_map('trim', explode("\n", (string)$stdout)));
$stats = json_decode(end($lines) ?: '', true);
@unlink($out);

if (!is_array($rewritten) || !is_array($stats) || (int)($stats['rewritten'] ?? 0) === 0) {
    _nnr_redirect('error', 'Rewrite failed or changed nothing: ' . (trim((string)$stdout) ?: 'no output'));
}

// Field-scoped merge — only the two subtrees the rewrite ever touches, exactly like the
// Templates tab's AI rewrite. Everything else in site.json (theme, header, footer,
// site_vars, pages, posts, …) is read fresh and left completely alone.
$live = json_decode((string)@file_get_contents($siteJsonPath), true);
if (!is_array($live)) { _nnr_redirect('error', 'Could not re-read site.json to merge the result.'); }
$live['content_blocks'] = $rewritten['content_blocks'] ?? $live['content_blocks'] ?? [];
$live['seo']            = $rewritten['seo']            ?? $live['seo']            ?? [];
if (file_put_contents($siteJsonPath, json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
    _nnr_redirect('error', 'Rewrite succeeded but could not save site.json.');
}

$msg = 'Rewrote ' . (int)$stats['rewritten'] . ' field(s) on the homepage for "' . $newService . '".';
if (!empty($stats['reverted'])) {
    $msg .= ' ' . (int)$stats['reverted'] . ' field(s) kept their original text (a {token} would have been lost).';
}
_nnr_redirect('success', $msg);
