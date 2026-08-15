<?php
session_start();
$_SESSION['active_site'] = 'smoketest';
session_write_close();
require __DIR__ . '/index.php';
