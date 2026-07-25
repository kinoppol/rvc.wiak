<?php
/**
 * Copy to config.php (the installer does this automatically) and fill in
 * real values. config.php is git-ignored — never commit real credentials.
 */
return [
    'app' => [
        'name' => 'ระบบมอบหมายและติดตามงาน',
        'url' => 'http://localhost/rvc.wiak',
        'timezone' => 'Asia/Bangkok',
        'debug' => false,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'rvc_wiak',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'upload' => [
        // Absolute filesystem path to the upload directory.
        'path' => __DIR__ . '/../storage/uploads',
        // Max size in bytes for a single uploaded file.
        'max_bytes' => 10 * 1024 * 1024,
    ],
];
