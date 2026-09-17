<?php
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
