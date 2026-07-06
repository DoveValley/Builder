<?php
session_start();
$_SESSION['active_site'] = 'pest-template';
session_write_close();
require __DIR__ . '/index.php';
