<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    $installUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/install.php';
    echo '<!doctype html><meta charset="utf-8"><title>ยังไม่ได้ติดตั้ง</title>'
        . '<body style="font-family:sans-serif;max-width:640px;margin:80px auto;text-align:center">'
        . '<h1>ระบบยังไม่ได้ติดตั้ง</h1>'
        . '<p>กรุณาเรียกใช้ตัวติดตั้งก่อนใช้งาน</p>'
        . '<p><a href="' . htmlspecialchars($installUrl) . '">เริ่มการติดตั้ง (install.php)</a></p></body>';
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configFile;

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Bangkok');

error_reporting(E_ALL);
ini_set('display_errors', !empty($config['app']['debug']) ? '1' : '0');

App\Core\Database::configure($config['db']);
App\Core\Upload::configure($config['upload']);

session_name('rvcwiak_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') ?: '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
