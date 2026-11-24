<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\AuditLog;
use App\Models\DesktopRelease;
use App\Models\User;

/**
 * Admins upload the notifier's installer; signed-in users download it.
 * Uploads arrive in chunks because an installer can exceed PHP's upload limit
 * and Cloudflare's per-request limit.
 */
final class DesktopReleaseController extends Controller
{
    private const MAX_CHUNK = 5 * 1024 * 1024;
    private const MIN_CHUNK = 256 * 1024;

    public function downloadPage(): void
    {
        $this->requireLogin();
        $this->page('desktop/download', [
            'pageTitle' => 'ดาวน์โหลดแอปแจ้งเตือน',
            'release' => DesktopRelease::current(),
        ]);
    }

    /** The current release for anyone signed in; older ones for admins only. */
    public function download(array $args): void
    {
        $this->requireLogin();
        $release = DesktopRelease::ready() ? DesktopRelease::find((int) $args['id']) : null;
        if (!$release || (!(int) $release['is_current'] && !$this->isAdmin())) {
            $this->html('<!doctype html><meta charset="utf-8"><p>ไม่พบไฟล์ติดตั้งนี้</p>', 404);
        }
        $path = DesktopRelease::path($release);
        if (!is_file($path)) {
            $this->html('<!doctype html><meta charset="utf-8"><p>ไม่พบไฟล์ติดตั้งบนเซิร์ฟเวอร์</p>', 404);
        }

        $this->stream($path, 'EduTaskNotifier-Setup-' . $release['version'] . '.' . $release['extension']);
    }

    /**
     * Velopack feed: the current release only. Public because the updater runs
     * before any sign-in, and a package holds no user data.
     */
    public function updateFeed(): void
    {
        header('Cache-Control: no-store');
        $r = DesktopRelease::current();
        $assets = [];
        if ($r && !empty($r['package_stored']) && is_file(DesktopRelease::packagePath($r))) {
            $assets[] = [
                'PackageId' => 'EduTaskNotifier',
                'Version' => $r['version'],
                'Type' => 'Full',
                'FileName' => $r['package_name'],
                'SHA1' => strtoupper((string) $r['package_sha1']),
                'SHA256' => strtoupper((string) $r['package_sha256']),
                'Size' => (int) $r['package_size'],
                'NotesMarkdown' => (string) ($r['notes'] ?? ''),
            ];
        }
        $this->json(['Assets' => $assets]);
    }

    public function updatePackage(array $args): void
    {
        $r = DesktopRelease::current();
        if (!$r || empty($r['package_stored']) || !hash_equals((string) $r['package_name'], (string) $args['file'])
            || !is_file(DesktopRelease::packagePath($r))) {
            header('Cache-Control: no-store');
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $this->stream(DesktopRelease::packagePath($r), (string) $r['package_name']);
    }

    private function stream(string $path, string $name): void
    {
        // Don't hold the session lock for the length of a large download.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->page('admin/desktop_releases', [
            'pageTitle' => 'ไฟล์ติดตั้งแอปแจ้งเตือน',
            'ready' => DesktopRelease::ready(),
            'releases' => DesktopRelease::ready() ? DesktopRelease::all() : [],
            'chunkSize' => $this->chunkSize(),
            'maxBytes' => DesktopRelease::MAX_BYTES,
        ]);
    }

    /** One chunk. Details are validated on the first; the last one publishes. */
    public function upload(): void
    {
        $user = $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        if (!DesktopRelease::ready()) {
            $this->fail('กรุณารัน migration ก่อน', 503);
        }

        $index = (int) ($_POST['index'] ?? -1);
        $total = (int) ($_POST['total'] ?? 0);
        $chunk = $_FILES['chunk'] ?? null;
        if ($index < 0 || $total < 1 || $index >= $total || !$chunk || ($chunk['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
            $this->fail('รับข้อมูลไฟล์ไม่สำเร็จ กรุณาลองใหม่');
        }

        if ($index === 0) {
            $this->beginUpload();
        }
        $state = $_SESSION['desktop_upload'] ?? null;
        if (!is_array($state) || $state['next'] !== $index || $state['total'] !== $total) {
            $this->fail('การอัปโหลดไม่ต่อเนื่อง กรุณาเริ่มใหม่', 409);
        }

        $part = DesktopRelease::dir() . '/' . $state['id'] . '.part';
        $size = (int) $chunk['size'];
        if ($state['received'] + $size > $state['size']) {
            $this->abortUpload($part, 'ขนาดไฟล์ไม่ตรงกับที่แจ้งไว้');
        }
        $in = fopen($chunk['tmp_name'], 'rb');
        $out = fopen($part, 'ab');
        $copied = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
        if ($in) {
            fclose($in);
        }
        if ($out) {
            fclose($out);
        }
        if ($copied !== $size) {
            $this->abortUpload($part, 'บันทึกไฟล์ไม่สำเร็จ พื้นที่เซิร์ฟเวอร์อาจไม่พอ');
        }

        $state['received'] += $size;
        $state['next'] = $index + 1;
        $_SESSION['desktop_upload'] = $state;
        if ($state['next'] < $total) {
            $this->json(['ok' => true, 'done' => false]);
        }
        $this->finishUpload($state, $part, (int) Auth::realUser()['id'], (int) $user['id']);
    }

    public function makeCurrent(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $release = DesktopRelease::ready() ? DesktopRelease::find((int) $args['id']) : null;
        if (!$release) {
            $this->fail('ไม่พบรุ่นนี้', 404);
        }
        DesktopRelease::setCurrent((int) $release['id']);
        $this->audit('desktop_release.current', (int) $release['id'], (string) $release['version']);
        $this->json(['ok' => true]);
    }

    public function delete(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $release = DesktopRelease::ready() ? DesktopRelease::find((int) $args['id']) : null;
        if (!$release) {
            $this->fail('ไม่พบรุ่นนี้', 404);
        }
        if ((int) $release['is_current'] === 1) {
            $this->fail('ลบรุ่นปัจจุบันไม่ได้ กรุณาตั้งรุ่นอื่นเป็นรุ่นปัจจุบันก่อน', 422);
        }
        DesktopRelease::delete($release);
        $this->audit('desktop_release.delete', (int) $release['id'], (string) $release['version']);
        $this->json(['ok' => true]);
    }

    private function beginUpload(): void
    {
        $dir = DesktopRelease::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('ไม่สามารถสร้างโฟลเดอร์จัดเก็บไฟล์ได้', 500);
        }
        // Leftovers from this admin's restart, and anything abandoned for a day.
        if (!empty($_SESSION['desktop_upload']['id'])) {
            @unlink($dir . '/' . $_SESSION['desktop_upload']['id'] . '.part');
        }
        unset($_SESSION['desktop_upload']);
        foreach (glob($dir . '/*.part') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }

        $fileName = (string) ($_POST['file_name'] ?? '');
        $ext = DesktopRelease::extensionOf($fileName);
        $size = (int) ($_POST['size'] ?? 0);
        if ($size < 1 || $size > DesktopRelease::MAX_BYTES) {
            $this->fail('ไฟล์ต้องมีขนาดไม่เกิน 500 MB', 422);
        }
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size) {
            $this->fail('พื้นที่เซิร์ฟเวอร์ไม่พอ', 507);
        }

        $state = [
            'id' => bin2hex(random_bytes(16)),
            'next' => 0,
            'total' => (int) $_POST['total'],
            'size' => $size,
            'received' => 0,
            'ext' => $ext,
            'make_current' => ($_POST['make_current'] ?? '') === '1',
        ];

        if (($_POST['kind'] ?? '') === 'package') {
            $release = DesktopRelease::find((int) ($_POST['release_id'] ?? 0));
            if (!$release || !empty($release['package_stored'])) {
                $this->fail('ไม่พบรุ่นที่จะเพิ่มแพ็กเกจอัปเดต', 422);
            }
            $expected = 'EduTaskNotifier-' . $release['version'] . '-full.nupkg';
            if ($ext !== 'nupkg' || $fileName !== $expected) {
                $this->fail('แพ็กเกจอัปเดตต้องชื่อ ' . $expected, 422);
            }
            $_SESSION['desktop_upload'] = $state + ['kind' => 'package', 'release_id' => (int) $release['id'], 'name' => $expected, 'version' => $release['version']];
            return;
        }

        $version = trim((string) ($_POST['version'] ?? ''));
        if (!preg_match(DesktopRelease::VERSION_PATTERN, $version)) {
            $this->fail('รูปแบบเลขรุ่นไม่ถูกต้อง (ตัวอย่าง 1.0.0)', 422);
        }
        if ($ext !== 'exe' && $ext !== 'msi') {
            $this->fail('ตัวติดตั้งรองรับเฉพาะไฟล์ .exe และ .msi', 422);
        }
        if (DesktopRelease::versionExists($version)) {
            $this->fail('มีเลขรุ่นนี้อยู่แล้ว', 422);
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $_SESSION['desktop_upload'] = $state + [
            'kind' => 'installer',
            'version' => $version,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
        ];
    }

    private function finishPackage(array $state, string $dest, string $stored, string $sha): void
    {
        DesktopRelease::attachPackage($state['release_id'], [
            'stored' => $stored,
            'name' => $state['name'],
            'size' => $state['size'],
            'sha1' => (string) hash_file('sha1', $dest),
            'sha256' => $sha,
        ]);
        if ($state['make_current']) {
            DesktopRelease::setCurrent($state['release_id']);
        }
        $this->audit('desktop_release.package', $state['release_id'], $state['version']);
        $this->json(['ok' => true, 'done' => true, 'id' => $state['release_id']]);
    }

    private function finishUpload(array $state, string $part, int $actorId, int $userId): void
    {
        if ($state['received'] !== $state['size'] || filesize($part) !== $state['size']) {
            $this->abortUpload($part, 'ไฟล์ที่ได้รับไม่ครบ กรุณาอัปโหลดใหม่');
        }
        if (!DesktopRelease::hasValidSignature($part, $state['ext'])) {
            $this->abortUpload($part, 'เนื้อหาไฟล์ไม่ตรงกับชนิดไฟล์ที่ต้องการ');
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $state['ext'];
        $dest = DesktopRelease::dir() . '/' . $stored;
        $sha = (string) hash_file('sha256', $part);
        if (!rename($part, $dest)) {
            $this->abortUpload($part, 'บันทึกไฟล์ไม่สำเร็จ');
        }
        unset($_SESSION['desktop_upload']);

        if ($state['kind'] === 'package') {
            try {
                $this->finishPackage($state, $dest, $stored, $sha);
            } catch (\PDOException $e) {
                @unlink($dest);
                $this->fail('บันทึกข้อมูลแพ็กเกจไม่สำเร็จ', 500);
            }
            return;
        }

        try {
            $id = DesktopRelease::create([
                'version' => $state['version'],
                'stored_name' => $stored,
                'extension' => $state['ext'],
                'size_bytes' => $state['size'],
                'sha256' => $sha,
                'notes' => $state['notes'],
                'uploaded_by' => $actorId,
            ]);
            if ($state['make_current']) {
                DesktopRelease::setCurrent($id);
            }
        } catch (\Throwable $e) {
            @unlink($dest);
            $this->fail('บันทึกข้อมูลรุ่นไม่สำเร็จ', 500);
        }
        $this->audit('desktop_release.upload', $id, $state['version']);
        $this->json(['ok' => true, 'done' => true, 'id' => $id]);
    }

    private function abortUpload(string $part, string $message): void
    {
        @unlink($part);
        unset($_SESSION['desktop_upload']);
        $this->fail($message, 422);
    }

    private function fail(string $message, int $status = 400): void
    {
        $this->json(['ok' => false, 'error' => $message], $status);
    }

    private function audit(string $action, int $id, string $version): void
    {
        $real = (int) Auth::realUser()['id'];
        $as = Auth::isImpersonating() ? (int) Auth::user()['id'] : null;
        AuditLog::record($real, $as, $action, 'desktop_release', $id, 'v' . $version);
    }

    private function isAdmin(): bool
    {
        $real = Auth::realUser();
        return $real && in_array('admin', User::rolesFor((int) $real['id']), true);
    }

    private function requireAdmin(): array
    {
        $user = $this->requireLogin();
        if (!$this->isAdmin()) {
            if (\App\Core\Request::isAjax()) {
                $this->fail('ไม่มีสิทธิ์', 403);
            }
            $this->redirect('/');
        }
        return $user;
    }

    /** Largest chunk this server accepts, with room for the other form fields. */
    private function chunkSize(): int
    {
        $limit = min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
        return max(self::MIN_CHUNK, min(self::MAX_CHUNK, (int) ($limit * 0.8)));
    }

    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        $n = (int) $v;
        if ($n <= 0) {
            return self::MAX_CHUNK * 2; // 0 means "no limit"
        }
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
