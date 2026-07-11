<?php
session_start();
$_SESSION['active_site'] = 'appliance-site';
session_write_close();
$_GET['slug'] = $_GET['slug'] ?? 'whirlpool-appliance-repair-lufkin-tx';
require __DIR__ . '/page.php';
