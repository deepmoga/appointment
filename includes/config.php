<?php
define('APP_NAME',    'BookWA');
define('APP_TAGLINE', 'Automate Appointments. Power Growth.');
define('APP_URL',     'http://localhost/github/Appointment');
define('APP_VERSION', '1.0.0');

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'appointment_system');
define('DB_CHARSET', 'utf8mb4');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 86400);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('UTC');
error_reporting(0);
ini_set('display_errors', 0);