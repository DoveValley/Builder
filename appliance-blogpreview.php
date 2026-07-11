<?php
session_start();
$_SESSION['active_site'] = 'appliance-site';
session_write_close();
require __DIR__ . '/blog.php';
