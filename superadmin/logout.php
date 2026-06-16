<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

superAdminLogout();
header('Location: ' . APP_URL . '/superadmin/login.php?logged_out=1');
exit;
