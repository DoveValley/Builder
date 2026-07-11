<?php
session_start();
$_SESSION['active_site'] = 'pest-template';
session_write_close();
$_GET['slug'] = $_GET['slug'] ?? 'cockroach-exterminator-lufkin-tx';
require __DIR__ . '/page.php';
