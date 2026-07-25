<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Upload;
use App\Models\Role;
use App\Models\Setting;
use RuntimeException;

/**
 * Imports user accounts from the external RMS personnel system.
 *
 * The base URL (protocol + host, e.g. "http://rms.rvc.ac.th") is stored in
 * the `settings` table and editable by admins from /admin/settings. The
 * endpoint path itself is app-specific and not meant to be reconfigured, so
 * it stays a constant here rather than in the database.
 */
final class PeopleSync
{
    public const SETTING_KEY = 'external_api_base_url';
    private const ENDPOINT_PATH = '/api_connection.php?app_name=nutty&data=people';
    private const DEFAULT_ROLE_CODE = 'staff';

    /**
     * @return array{created:int,updated:int,skipped:int,total:int,errors:string[]}
     */
    public static function run(): array
    {
        // A full sync fetches the roster plus one avatar image per person —
        // comfortably over PHP's default execution-time limit for a list of
        // any real size. This is an infrequent, admin-triggered action, so
        // let it run as long as it needs to instead of being killed mid-sync.
        @set_time_limit(0);

        $baseUrl = rtrim((string) Setting::get(self::SETTING_KEY, ''), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า URL ของระบบภายนอก (RMS) กรุณากำหนดที่เมนูตั้งค่าก่อน');
        }

        $people = self::fetch($baseUrl . self::ENDPOINT_PATH);

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => count($people), 'errors' => []];
        $pdo = Database::pdo();
        $staffRoleId = Role::find(self::DEFAULT_ROLE_CODE)['id'] ?? null;

        foreach ($people as $i => $p) {
            if (!is_array($p)) {
                $result['skipped']++;
                continue;
            }
            if ((int) ($p['people_exit'] ?? 1) !== 0) {
                continue; // not an active person in the source system — silently excluded, not an error
            }

            $username = trim((string) ($p['people_id'] ?? ''));
            $name = trim((string) ($p['people_name'] ?? ''));
            $surname = trim((string) ($p['people_surname'] ?? ''));
            $password = (string) ($p['ath_pass'] ?? '');
            $email = trim((string) ($p['people_email'] ?? ''));
            $pic = trim((string) ($p['people_pic'] ?? ''));

            if ($username === '') {
                $result['skipped']++;
                $result['errors'][] = "แถวที่ {$i}: ไม่มี people_id — ข้าม";
                continue;
            }
            if ($password === '') {
                $result['skipped']++;
                $result['errors'][] = "รหัส {$username}: ไม่มี ath_pass — ข้าม";
                continue;
            }

            $fullName = trim($name . ' ' . $surname);
            if ($fullName === '') {
                $fullName = $username;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $existing = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $existing->execute([$username]);
            $existingId = $existing->fetchColumn();

            if ($existingId !== false) {
                $userId = (int) $existingId;
                $upd = $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, email = ? WHERE id = ?');
                $upd->execute([$hash, $fullName, $email ?: null, $userId]);
                $result['updated']++;
            } else {
                $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, icon) VALUES (?,?,?,?,?)');
                $ins->execute([$username, $hash, $fullName, $email ?: null, 'person-workspace']);
                $userId = (int) $pdo->lastInsertId();
                if ($staffRoleId !== null) {
                    $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $staffRoleId]);
                }
                $result['created']++;
            }

            if ($pic !== '') {
                $avatarPath = Upload::storeAvatarFromUrl($userId, $baseUrl . '/files/' . ltrim($pic, '/'));
                if ($avatarPath !== null) {
                    $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$avatarPath, $userId]);
                } else {
                    $result['errors'][] = "รหัส {$username}: ดาวน์โหลดรูปโปรไฟล์ไม่สำเร็จ";
                }
            }
        }

        Setting::set('people_sync_last_run_at', date('c'));
        Setting::set('people_sync_last_summary', json_encode($result, JSON_UNESCAPED_UNICODE));

        return $result;
    }

    /** @return array<int,mixed> */
    private static function fetch(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('เซิร์ฟเวอร์นี้ไม่มี PHP extension "curl" ซึ่งจำเป็นสำหรับการเชื่อมต่อระบบภายนอก');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('เชื่อมต่อระบบภายนอกไม่สำเร็จ: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("ระบบภายนอกตอบกลับด้วยสถานะ HTTP {$status}");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('ข้อมูลที่ได้รับจากระบบภายนอกไม่ใช่ JSON ที่ถูกต้อง');
        }

        return $data;
    }
}
