<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated()) {
    header('Location: login.php');
    exit;
}

$client_id = $_GET['client_id'] ?? '';
$client = lpc_load_client_for_current_actor($client_id);
if (!$client) {
    header('Location: clients.php?msg=error:Client+not+found');
    exit;
}

$page_id = $_GET['page'] ?? '';
$hubUrl  = 'preview_site.php?client_id=' . urlencode($client_id);
$selfUrl = $hubUrl . ($page_id !== '' ? '&page=' . urlencode($page_id) : '');

function lpc_preview_status_label($status) {
    return $status === 'published' ? 'Published'
        : ($status === 'needs_update' ? 'Needs Update'
        : ($status === 'draft' ? 'Draft' : 'Not Drafted Yet'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Preview Site — <?= h($client['identity']['business_name']) ?></title>
</head>
<body>

<div style="background:#1e293b;color:#fff;padding:10px 18px;font-size:.85rem;display:flex;justify-content:space-between;align-items:center;">
    <span>&#128065; <strong>Preview Site</strong> — <?= h($client['identity']['business_name']) ?> — not live, internal review only</span>
    <a href="client.php?id=<?= h($client_id) ?>&tab=locations" style="color:#94a3b8;">&larr; Back to workspace</a>
</div>

<?php if ($page_id === ''): ?>

    <div style="max-width:700px;margin:40px auto;padding:0 20px;">
        <h1>Cities</h1>
        <ul>
            <?php foreach ($client['target_cities'] as $tc): foreach ($tc['services'] as $sid):
                $pid = $tc['city_id'] . '__' . $sid;
                $service = lpc_find_by_id($client['services'], 'service_id', $sid);
                $page = lpc_load_page($client_id, $pid);
                $status = $page['status'] ?? 'not_created';
            ?>
                <li>
                    <a href="<?= h($hubUrl) ?>&page=<?= h($pid) ?>"><?= h($service['label'] ?? $sid) ?> in <?= h($tc['city']) ?>, <?= h($tc['state']) ?></a>
                    — <em><?= h(lpc_preview_status_label($status)) ?></em>
                </li>
            <?php endforeach; endforeach; ?>
        </ul>
        <?php if (empty($client['target_cities'])): ?>
            <p>No target cities confirmed yet.</p>
        <?php endif; ?>
    </div>

<?php else:
    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $target = lpc_find_by_id($client['target_cities'], 'city_id', $city_id);
    $service = $target ? lpc_find_by_id($client['services'], 'service_id', $service_id) : null;

    if (!$target || !$service) {
        echo '<div style="max-width:700px;margin:40px auto;padding:0 20px;"><p>Not a confirmed target city/service. <a href="' . h($hubUrl) . '">&larr; Back to Cities</a></p></div>';
    } else {
        $page = lpc_load_page($client_id, $page_id);
        $status = $page['status'] ?? 'not_created';
        $siblings = lpc_preview_sibling_links($client, $page_id, $hubUrl);
        ?>
        <p style="max-width:700px;margin:16px auto 0;padding:0 20px;"><a href="<?= h($hubUrl) ?>">&larr; Back to Cities</a> &middot; Status: <em><?= h(lpc_preview_status_label($status)) ?></em></p>

        <?php if (!$page): ?>
            <div style="max-width:700px;margin:20px auto;padding:0 20px;">
                <p>This page hasn't been drafted yet. <a href="page_editor.php?client_id=<?= h($client_id) ?>&page_id=<?= h($page_id) ?>">Open the editor</a> to write or generate it.</p>
            </div>
        <?php else: ?>
            <main style="max-width:700px;margin:0 auto;padding:20px;"><?= lpc_preview_render($page['sections']) ?></main>

            <?php if ($siblings): ?>
            <div style="max-width:700px;margin:0 auto 40px;padding:0 20px;">
                <h2>Other Services in <?= h($target['city']) ?></h2>
                <ul>
                    <?php foreach ($siblings as $link): ?>
                        <li><a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a> — <em><?= h(lpc_preview_status_label($link['status'])) ?></em></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endif;
    }
endif; ?>

</body>
</html>
