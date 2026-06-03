<?php
define('APP_NAME',     'EDUPRO');
define('APP_FULLNAME', 'E-EDU PRO');
define('APP_URL',      '/EDUPRO');
define('APP_ROOT',     dirname(__DIR__));
define('ASSETS_URL',   APP_URL . '/assets');
define('APP_VERSION',  '1.0.0');

date_default_timezone_set('Africa/Niamey');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

require_once __DIR__ . '/database.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/auth.php';
