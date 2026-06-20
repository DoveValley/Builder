<?php
session_start();
$_SESSION['active_site'] = 'granitepmacademy';
$_GET['slug'] = 'pmp-certification-training';
session_write_close();
require __DIR__ . '/page.php';
