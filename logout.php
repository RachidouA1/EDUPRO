<?php
require_once __DIR__ . '/config/config.php';
doLogout();
header('Location: ' . APP_URL . '/index.php');
exit;
