<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

// CSRF check
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?msg=error:Invalid+request+token');
    exit;
}

if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$data    = load_data();
$section = $_POST['section'] ?? '';
$message = '';
$activeTab = 'header';

switch ($section) {

    /* ---- HEADER ---- */
    case 'header':
        require __DIR__ . '/save/header.php';
        break;

    /* ---- THEME ---- */
    case 'theme':
        require __DIR__ . '/save/theme.php';
        break;

    case 'theme_reset':
        require __DIR__ . '/save/theme_reset.php';
        break;

    /* ---- CONTENT (home page + landing pages) ---- */
    case 'content':
        require __DIR__ . '/save/content.php';
        break;

    /* ---- HOMEPAGE LOAD STARTER ---- */
    case 'homepage_load_starter':
        require __DIR__ . '/save/homepage_load_starter.php';
        break;

    /* ---- PAGE ADD ---- */
    case 'page_add':
        require __DIR__ . '/save/page_add.php';
        break;

    /* ---- PAGE CLONE ---- */
    case 'page_clone':
        require __DIR__ . '/save/page_clone.php';
        break;

    /* ---- PAGE DELETE ---- */
    case 'page_delete':
        require __DIR__ . '/save/page_delete.php';
        break;

    /* ---- POST ADD ---- */
    case 'post_add':
        require __DIR__ . '/save/post_add.php';
        break;

    /* ---- POST DELETE ---- */
    case 'post_delete':
        require __DIR__ . '/save/post_delete.php';
        break;

    /* ---- BLOG SETTINGS ---- */
    case 'blog_settings':
        require __DIR__ . '/save/blog_settings.php';
        break;

    /* ---- FOOTER ---- */
    case 'footer':
        require __DIR__ . '/save/footer.php';
        break;

    /* ---- POPUPS ---- */
    case 'popups':
        require __DIR__ . '/save/popups.php';
        break;

    /* ---- LOCAL BUSINESS ---- */
    case 'local_business':
        require __DIR__ . '/save/local_business.php';
        break;

    default:
        header('Location: index.php');
        exit;
}

$saved = save_data($data);
if (!$saved) {
    $message = 'error:Could not save changes — the data file could not be written. Check server disk space and folder permissions.';
} elseif ($message === '') {
    $message = 'success:Changes saved successfully.';
}
$redirect = 'index.php?tab=' . urlencode($activeTab);
if (!empty($pageId) && isset($data['pages'][$pageId])) $redirect .= '&page=' . urlencode($pageId);
if (!empty($postId) && isset($data['posts'][$postId])) $redirect .= '&post=' . urlencode($postId);
$redirect .= '&msg=' . urlencode($message);
header('Location: ' . $redirect);
exit;
