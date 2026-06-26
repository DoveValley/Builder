<?php
session_start();
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
$_GET['slug'] = $_REQUEST['slug'] ?? 'pmp-certification-training';
require __DIR__ . '/page.php';
