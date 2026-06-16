<?php
define('APP_NAME',    'BookWA');
define('APP_TAGLINE', 'Automate Appointments. Power Growth.');
define('APP_URL',     'https://yourdomain.com');   // ← apna domain
define('APP_VERSION', '1.0.0');

define('DB_HOST',    'localhost');
define('DB_USER',    'your_db_user');              // ← apna DB user
define('DB_PASS',    'your_db_password');          // ← apna DB password
define('DB_NAME',    'your_db_name');              // ← apna DB name
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
