<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin/login.php'); exit; }
$_SESSION['active_site'] = 'granitepmacademy';
$_GET['slug'] = 'pmp-certification-training';
session_write_close();
require __DIR__ . '/page.php';
