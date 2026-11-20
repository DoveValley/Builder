<?php
require_once __DIR__ . '/../config.php';

platform_session_record_logout();

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
