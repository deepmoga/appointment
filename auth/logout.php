<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

logout();
header('Location: ' . APP_URL . '/auth/login.php?logged_out=1');
exit;
