<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Resolve the return path — must be a relative URL on this server
// Strip query string and fragment; block backslash second char (browser normalises /\host → //host)
$rawReturn  = trim($_POST['return_url'] ?? '/');
$_noFrag    = explode('#', $rawReturn, 2)[0];
$_noQuery   = explode('?', $_noFrag, 2)[0];
$returnPath = preg_match('#^/[^/\\\\]#', $rawReturn) ? $_noQuery : '/';
unset($_noFrag, $_noQuery);

function cf_redirect(string $path, string $msg): void {
    header('Location: ' . $path . '?cf_msg=' . $msg);
    exit;
}

// CSRF
$token = $_POST['cf_csrf'] ?? '';
if (!hash_equals($_SESSION['cf_csrf_token'] ?? '', $token)) {
    cf_redirect($returnPath, 'error');
}
// Rotate token after use
$_SESSION['cf_csrf_token'] = bin2hex(random_bytes(32));

// Honeypot — bots fill this, humans don't
if (($_POST['cf_hp'] ?? '') !== '') {
    cf_redirect($returnPath, 'success'); // silent discard
}

// Rate limit: 5 submissions per IP per hour
$ipKey = 'cf_rate_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
$rate  = $_SESSION[$ipKey] ?? ['count' => 0, 'start' => time()];
if (time() - $rate['start'] > 3600) {
    $rate = ['count' => 0, 'start' => time()];
}
if ($rate['count'] >= 5) {
    cf_redirect($returnPath, 'limit');
}
$rate['count']++;
$_SESSION[$ipKey] = $rate;

// Validate required fields
$name    = trim($_POST['cf_name']    ?? '');
$email   = trim($_POST['cf_email']   ?? '');
$phone   = trim($_POST['cf_phone']   ?? '');
$message = trim($_POST['cf_message'] ?? '');

if ($name === '' || $email === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cf_redirect($returnPath, 'error');
}

// Sanitize for email body
$name    = substr(strip_tags($name),    0, 200);
$email   = substr(strip_tags($email),   0, 200);
$phone   = substr(strip_tags($phone),   0, 50);
$message = substr(strip_tags($message), 0, 5000);

$to = defined('CONTACT_EMAIL') && CONTACT_EMAIL !== '' ? CONTACT_EMAIL : '';
if ($to === '') {
    cf_redirect($returnPath, 'error');
}

// Strip CR/LF from any value that will go into a mail header (prevents header injection)
$safeName  = str_replace(["\r", "\n"], '', $name);
$safeEmail = str_replace(["\r", "\n"], '', $email);

$subject = 'New inquiry from ' . $safeName;
$body    = "Name: $name\nEmail: $email\n";
if ($phone !== '') $body .= "Phone: $phone\n";
$body   .= "\nMessage:\n$message\n";

$host    = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$headers = implode("\r\n", [
    'From: noreply@' . $host,
    'Reply-To: ' . $safeEmail,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

if (!mail($to, $subject, $body, $headers)) {
    cf_redirect($returnPath, 'error');
}

cf_redirect($returnPath, 'success');
