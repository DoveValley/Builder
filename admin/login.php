<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// If already logged in, go straight to the admin panel
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$error = '';

// Brute-force protection: max 10 attempts per 15 min per IP
$ipKey   = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
$attempts = $_SESSION[$ipKey] ?? ['count' => 0, 'first' => time()];
if (time() - $attempts['first'] > 900) {
    $attempts = ['count' => 0, 'first' => time()];
}
$locked = $attempts['count'] >= 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($locked) {
        $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION[$ipKey] = ['count' => 0, 'first' => time()]; // reset on success
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: index.php');
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
    <title>Admin Login - <?= h(SITE_TITLE) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-body">
    <div class="login-wrapper">
        <div class="card login-card">
            <h1>Admin Login</h1>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($locked): ?>
                <p style="text-align:center;color:#888;font-size:0.9rem;">Login is temporarily locked.</p>
            <?php else: ?>
            <form method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn" style="width:100%;">Log In</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
