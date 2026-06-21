<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=schedule'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=schedule&msg=error:Invalid+request+token');
    exit;
}
if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$action = $_POST['action'] ?? '';

function _sched_load(): array {
    if (!file_exists(COURSES_FILE)) return [];
    $raw = json_decode(file_get_contents(COURSES_FILE), true);
    return is_array($raw) ? $raw : [];
}

function _sched_save(array $courses): bool {
    $dir = dirname(COURSES_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $json = json_encode(array_values($courses), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = COURSES_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) !== false && rename($tmp, COURSES_FILE)) {
        return true;
    }
    @unlink($tmp);
    return false;
}

function _sched_next_id(array $courses): int {
    $max = 0;
    foreach ($courses as $c) { $max = max($max, (int)($c['id'] ?? 0)); }
    return $max + 1;
}

switch ($action) {
    case 'save': {
        $id = (int)($_POST['id'] ?? 0);

        if (!empty($_POST['self_paced'])) {
            $timeEst = 'Self-paced';
        } else {
            $h1   = max(1, min(12, (int)($_POST['time_hour']     ?? 8)));
            $m1   = in_array($_POST['time_min']     ?? '00', ['00','15','30','45'], true) ? ($_POST['time_min']     ?? '00') : '00';
            $ap1  = ($_POST['time_ampm']     ?? 'am') === 'pm' ? 'pm' : 'am';
            $h2   = max(1, min(12, (int)($_POST['time_end_hour'] ?? 5)));
            $m2   = in_array($_POST['time_end_min'] ?? '00', ['00','15','30','45'], true) ? ($_POST['time_end_min'] ?? '00') : '00';
            $ap2  = ($_POST['time_end_ampm'] ?? 'pm') === 'pm' ? 'pm' : 'am';
            $timeEst = $h1 . ':' . $m1 . $ap1 . '-' . $h2 . ':' . $m2 . $ap2;
        }

        $courseType = trim($_POST['course_type'] ?? '');
        if ($courseType === '') {
            header('Location: index.php?tab=schedule&msg=error:Course+type+is+required');
            exit;
        }

        $delivery = in_array($_POST['delivery'] ?? '', ['Live-Virtual', 'On-Demand'], true)
            ? $_POST['delivery']
            : 'Live-Virtual';

        $course = [
            'id'                => $id ?: 0,
            'course_type'       => $courseType,
            'delivery'          => $delivery,
            'dates'             => trim($_POST['dates'] ?? ''),
            'time_est'          => $timeEst,
            'price'             => max(0, (float)($_POST['price']     ?? 0)),
            'old_price'         => max(0, (float)($_POST['old_price'] ?? 0)),
            'register_url'      => sanitize_url(trim($_POST['register_url'] ?? '')),
            'availability_note' => trim($_POST['availability_note'] ?? ''),
            'guaranteed'        => !empty($_POST['guaranteed']),
            'sort_order'        => (int)($_POST['sort_order'] ?? 0),
        ];

        $courses = _sched_load();

        if ($id > 0) {
            $course['id'] = $id;
            $found = false;
            foreach ($courses as &$c) {
                if ((int)($c['id'] ?? 0) === $id) { $c = $course; $found = true; break; }
            }
            unset($c);
            if (!$found) { $course['id'] = _sched_next_id($courses); $courses[] = $course; }
        } else {
            $course['id'] = _sched_next_id($courses);
            $courses[] = $course;
        }

        usort($courses, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        if (!_sched_save($courses)) {
            header('Location: index.php?tab=schedule&msg=error:Could+not+save+-+check+file+permissions');
            exit;
        }

        header('Location: index.php?tab=schedule&msg=success:Course+saved');
        exit;
    }

    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { header('Location: index.php?tab=schedule&msg=error:Invalid+ID'); exit; }
        $courses  = _sched_load();
        $filtered = array_values(array_filter($courses, fn($c) => (int)($c['id'] ?? 0) !== $id));
        if (count($filtered) === count($courses)) {
            header('Location: index.php?tab=schedule&msg=error:Course+not+found'); exit;
        }
        if (!_sched_save($filtered)) {
            header('Location: index.php?tab=schedule&msg=error:Could+not+save+-+check+file+permissions');
            exit;
        }
        header('Location: index.php?tab=schedule&msg=success:Course+deleted');
        exit;
    }

    case 'duplicate': {
        $id = (int)($_POST['id'] ?? 0);
        $courses = _sched_load();
        $found = null;
        foreach ($courses as $c) {
            if ((int)($c['id'] ?? 0) === $id) { $found = $c; break; }
        }
        if (!$found) { header('Location: index.php?tab=schedule&msg=error:Course+not+found'); exit; }
        $found['id'] = _sched_next_id($courses);
        $courses[] = $found;
        usort($courses, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        if (!_sched_save($courses)) {
            header('Location: index.php?tab=schedule&msg=error:Could+not+save+-+check+file+permissions');
            exit;
        }
        header('Location: index.php?tab=schedule&msg=success:Course+duplicated');
        exit;
    }

    default:
        header('Location: index.php?tab=schedule');
        exit;
}
