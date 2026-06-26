<?php
session_start();
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
$_GET['slug'] = 'pmp-bootcamp';
require __DIR__ . '/page.php';
