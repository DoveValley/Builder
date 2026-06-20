<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin/login.php'); exit; }
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
require __DIR__ . '/index.php';
