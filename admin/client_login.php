<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in as an end client -> straight to their own portal.
if (!empty($_SESSION['client_logged_in'])) {
    header('Location: client_portal.php');
    exit;
}

$error = '';

if (empty($_SESSION['client_login_csrf_token'])) {
    $_SESSION['client_login_csrf_token'] = bin2hex(random_bytes(32));
}
$loginCsrfToken = $_SESSION['client_login_csrf_token'];

// Same brute-force protection as admin/login.php and agency_login.php.
$ipKey    = 'client_login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
$attempts = $_SESSION[$ipKey] ?? ['count' => 0, 'first' => time()];
if (time() - $attempts['first'] > 900) {
    $attempts = ['count' => 0, 'first' => time()];
}
$locked = $attempts['count'] >= 10;
$turnstileSiteKey = lpc_turnstile_keys()['site_key'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['client_login_csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired — please try again.';
        $_SESSION['client_login_csrf_token'] = bin2hex(random_bytes(32));
        $loginCsrfToken = $_SESSION['client_login_csrf_token'];
    } elseif ($locked) {
        $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    } elseif (!lpc_verify_turnstile((string) ($_POST['cf-turnstile-response'] ?? ''))) {
        $error = 'Verification failed — please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $client   = lpc_find_client_by_username($username);

        if ($client && lpc_verify_client_password($client['client_id'], $password)) {
            session_regenerate_id(true);
            $_SESSION[$ipKey] = ['count' => 0, 'first' => time()];
            // Deliberately NOT admin_logged_in — see lpc_actor_is_authenticated()'s
            // doc comment for why end clients get their own flag.
            $_SESSION['client_logged_in'] = true;
            $_SESSION['actor_role']      = 'end_client';
            $_SESSION['client_id']       = $client['client_id'];
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
            header('Location: client_portal.php');
            exit;
        } else {
            $attempts['count']++;
            $_SESSION[$ipKey] = $attempts;
            $remaining = 10 - $attempts['count'];
            $error = $remaining > 0
                ? 'Invalid username or password. (' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining)'
                : 'Too many failed attempts. Please wait 15 minutes.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Login - <?= h(SITE_TITLE) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
    <?php if ($turnstileSiteKey !== ''): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="admin-body">
    <div class="login-wrapper">
        <div class="card login-card">
            <h1>Client Login</h1>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($locked): ?>
                <p style="text-align:center;color:#888;font-size:0.9rem;">Login is temporarily locked.</p>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($loginCsrfToken) ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <?php if ($turnstileSiteKey !== ''): ?>
                <div class="cf-turnstile" data-sitekey="<?= h($turnstileSiteKey) ?>" style="margin-bottom:14px;"></div>
                <?php endif; ?>
                <button type="submit" class="btn" style="width:100%;">Log In</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
