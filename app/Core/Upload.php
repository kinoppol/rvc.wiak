<?php
declare(strict_types=1);

namespace App\Core;

final class Upload
{
    private static array $cfg = [];

    public static function configure(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function maxBytes(): int
    {
        return (int) (self::$cfg['max_bytes'] ?? 10 * 1024 * 1024);
    }

    public static function dir(): string
    {
        return rtrim(self::$cfg['path'] ?? (APP_ROOT . '/storage/uploads'), '/');
    }

    /**
     * @return array{ok:bool,error?:string,storedPath?:string,mime?:string,size?:int,name?:string}
     */
    public static function store(array $file, int $ticketId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'อัปโหลดไฟล์ล้มเหลว (รหัส ' . ($file['error'] ?? '?') . ')'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'ไฟล์ที่อัปโหลดไม่ถูกต้อง'];
        }
        if ($file['size'] > self::maxBytes()) {
            return ['ok' => false, 'error' => 'ไฟล์มีขนาดเกินที่กำหนด (สูงสุด ' . self::humanSize(self::maxBytes()) . ')'];
        }

        $subdir = self::dir() . '/' . $ticketId;
        if (!is_dir($subdir) && !mkdir($subdir, 0755, true) && !is_dir($subdir)) {
            return ['ok' => false, 'error' => 'ไม่สามารถสร้างโฟลเดอร์จัดเก็บไฟล์ได้'];
        }

        $original = $file['name'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $safeExt = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? '.' . $ext : '';
        $stored = bin2hex(random_bytes(16)) . $safeExt;
        $dest = $subdir . '/' . $stored;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'ไม่สามารถบันทึกไฟล์ลงเซิร์ฟเวอร์ได้'];
        }

        $mime = @mime_content_type($dest) ?: ($file['type'] ?? 'application/octet-stream');

        return [
            'ok' => true,
            'storedPath' => $ticketId . '/' . $stored,
            'mime' => $mime,
            'size' => (int) $file['size'],
            'name' => $original,
        ];
    }

    public static function absolutePath(string $storedPath): string
    {
        return self::dir() . '/' . ltrim($storedPath, '/');
    }

    /**
     * Downloads an image from an external URL (used for RMS avatar sync) and
     * stores it as storage/uploads/avatars/{userId}.{ext}, overwriting any
     * previous avatar for that user. Returns the relative path on success,
     * or null if the download failed or the content isn't a real image.
     */
    public static function storeAvatarFromUrl(int $userId, string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $status < 200 || $status >= 300 || !is_string($body) || $body === '') {
            return null;
        }

        $info = @getimagesizefromstring($body);
        if ($info === false) {
            return null;
        }
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null) {
            return null;
        }

        $subdir = self::dir() . '/avatars';
        if (!is_dir($subdir) && !mkdir($subdir, 0755, true) && !is_dir($subdir)) {
            return null;
        }

        // Remove any previous avatar for this user under a different extension.
        foreach (glob($subdir . '/' . $userId . '.*') ?: [] as $old) {
            @unlink($old);
        }

        $relative = 'avatars/' . $userId . '.' . $ext;
        if (file_put_contents(self::dir() . '/' . $relative, $body) === false) {
            return null;
        }

        return $relative;
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return round($val, $val < 10 && $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }

    public static function iconFor(string $mime, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match (true) {
            $ext === 'pdf' => ['file-earmark-pdf-fill', '#dc2626'],
            in_array($ext, ['xls', 'xlsx', 'csv'], true) => ['file-earmark-excel-fill', '#16a34a'],
            in_array($ext, ['doc', 'docx'], true) => ['file-earmark-word-fill', '#2563eb'],
            in_array($ext, ['ppt', 'pptx'], true) => ['file-earmark-ppt-fill', '#ea580c'],
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => ['file-earmark-image-fill', '#7c3aed'],
            in_array($ext, ['zip', 'rar', '7z'], true) => ['file-earmark-zip-fill', '#6b7280'],
            default => ['file-earmark-fill', '#64748b'],
        };
    }
}
