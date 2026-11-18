<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:The+niche+library+is+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: niche_library.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: niche_library.php?msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    $niche_id = trim($_POST['niche_id'] ?? '');
    niche_library_delete($niche_id);
    header('Location: niche_library.php?msg=success:Deleted');
    exit;
}

if ($action !== 'save') { header('Location: niche_library.php'); exit; }

$niche_id = trim($_POST['niche_id'] ?? '');
if (!niche_library_valid_id($niche_id)) {
    header('Location: niche_library.php?new=1&msg=error:Niche+ID+must+be+lowercase+letters%2C+numbers+and+hyphens');
    exit;
}
$existing_niche_id = trim($_POST['existing_niche_id'] ?? '');
if ($existing_niche_id !== '' && $existing_niche_id !== $niche_id) {
    // Niche ID is the filename — same "the id is the real identity, not
    // relabelable" rule this codebase already applies to a fleet domain.
    header('Location: niche_library.php?edit=' . urlencode($existing_niche_id) . '&msg=error:Niche+ID+cannot+be+changed+after+creating');
    exit;
}

$services = [];
$labels = (array) ($_POST['svc_label'] ?? []);
$slugs = (array) ($_POST['svc_slug'] ?? []);
$patterns = (array) ($_POST['svc_pattern'] ?? []);
foreach ($labels as $i => $label) {
    $label = trim($label);
    if ($label === '') continue;
    $slug = trim($slugs[$i] ?? '');
    if ($slug === '') $slug = slugify($label);
    $services[] = ['slug' => $slug, 'label' => $label, 'keyword_pattern' => trim($patterns[$i] ?? '')];
}

$researchFields = [];
$rKeys = (array) ($_POST['research_key'] ?? []);
$rAsks = (array) ($_POST['research_ask'] ?? []);
foreach ($rKeys as $i => $key) {
    $key = trim((string) preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($key))), '_');
    $ask = trim($rAsks[$i] ?? '');
    if ($key === '' || $ask === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) continue;
    $researchFields[] = ['key' => $key, 'ask' => $ask];
}

$blogTopics = [];
$bTitles = (array) ($_POST['blog_title'] ?? []);
$bSlugs = (array) ($_POST['blog_slug'] ?? []);
$bKeywords = (array) ($_POST['blog_keyword'] ?? []);
$bTags = (array) ($_POST['blog_tag'] ?? []);
$bRelated = (array) ($_POST['blog_related'] ?? []);
foreach ($bTitles as $i => $title) {
    $title = trim($title);
    if ($title === '') continue;
    $slug = trim($bSlugs[$i] ?? '');
    if ($slug === '') $slug = slugify($title);
    $related = array_values(array_filter(array_map('trim', explode(',', $bRelated[$i] ?? ''))));
    $blogTopics[] = [
        'slug' => $slug, 'title' => $title,
        'focus_keyword' => trim($bKeywords[$i] ?? ''), 'tag' => trim($bTags[$i] ?? ''),
        'related_topics' => $related,
    ];
}

$data = [
    'label'             => trim($_POST['label'] ?? ''),
    'service_noun'      => trim($_POST['service_noun'] ?? ''),
    'customer_noun'     => trim($_POST['customer_noun'] ?? 'customer'),
    'tone'              => trim($_POST['tone'] ?? 'professional'),
    'local_angle'       => trim($_POST['local_angle'] ?? ''),
    'seasonal_calendar' => trim($_POST['seasonal_calendar'] ?? ''),
    'services'          => $services,
    'trust_bar_items'   => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['trust_bar_items'] ?? '')), 'strlen')),
    'match_keywords'    => array_values(array_filter(array_map('trim', explode(',', $_POST['match_keywords'] ?? '')))),
    'custom_research_fields' => $researchFields,
    'blog_topics'       => $blogTopics,
];

if ($data['label'] === '') {
    header('Location: niche_library.php?' . ($existing_niche_id ? 'edit=' . urlencode($existing_niche_id) : 'new=1') . '&msg=error:Label+is+required');
    exit;
}

if (!niche_library_save($niche_id, $data)) {
    header('Location: niche_library.php?msg=error:Could+not+write+the+niche+library+file');
    exit;
}
header('Location: niche_library.php?edit=' . urlencode($niche_id) . '&msg=success:Saved');
