<?php
declare(strict_types=1);

final class Requirements
{
    public const MIN_PHP = '8.0.0';
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session', 'openssl'];

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    public static function checks(string $uploadDir): array
    {
        $out = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $out[] = ['label' => 'PHP เวอร์ชัน ' . self::MIN_PHP . ' ขึ้นไป', 'ok' => $phpOk, 'detail' => 'ตรวจพบ PHP ' . PHP_VERSION];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $ok = extension_loaded($ext);
            $out[] = ['label' => "PHP extension: {$ext}", 'ok' => $ok, 'detail' => $ok ? 'พร้อมใช้งาน' : 'ไม่พบส่วนขยายนี้ กรุณาเปิดใช้งานใน php.ini'];
        }

        $dirOk = self::ensureWritableDir($uploadDir);
        $out[] = ['label' => 'โฟลเดอร์อัปโหลดไฟล์ (' . $uploadDir . ')', 'ok' => $dirOk, 'detail' => $dirOk ? 'สร้าง/เขียนไฟล์ได้' : 'ไม่สามารถสร้างหรือเขียนไฟล์ในโฟลเดอร์นี้ได้'];

        $configDirWritable = is_writable(dirname(__DIR__) . '/config');
        $out[] = ['label' => 'โฟลเดอร์ config เขียนได้', 'ok' => $configDirWritable, 'detail' => $configDirWritable ? 'พร้อมใช้งาน' : 'กรุณาตั้งค่าสิทธิ์การเขียนให้โฟลเดอร์ config'];

        return $out;
    }

    public static function allOk(array $checks): bool
    {
        foreach ($checks as $c) {
            if (!$c['ok']) {
                return false;
            }
        }
        return true;
    }

    public static function ensureWritableDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    /** Largest single-file upload PHP itself will accept, in bytes. */
    public static function phpUploadCeilingBytes(): int
    {
        return min(self::iniBytes(ini_get('upload_max_filesize') ?: '2M'), self::iniBytes(ini_get('post_max_size') ?: '8M'));
    }

    public static function iniBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = strtolower(substr($val, -1));
        $num = (float) $val;
        return (int) match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (float) $val,
        };
    }
}
