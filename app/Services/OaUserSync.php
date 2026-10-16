<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Oa;
use App\Models\Setting;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Pulls the user roster from the RVC-ONE User-sync API (users.php) and upserts
 * it into `users`, keyed by the gateway user id (users.oa_user_id) — the same
 * id SSO login's verify_token returns, so both paths land on one account.
 *
 * Incremental by default: the `server_time` of the last *complete* run is
 * stored as `since` for the next one. It only moves after every page of a run
 * has been fetched and saved — a run that dies half-way (or fails to save any
 * one user) leaves `since` where it was, and the next run
 * re-fetches the same window. Users changed in the same second as `since` can
 * come back twice; the upsert is idempotent, so that's harmless.
 *
 * Triggered from /admin/settings (incremental or full) and by cron through
 * cli/oa-user-sync.php. Endpoint/client_id/type come from config/oa.php; the
 * client_secret from /admin/settings, OA_CLIENT_SECRET or config/config.php (see Oa::clientSecret).
 * Never log the secret, and never log national IDs.
 */
final class OaUserSync
{
    public const SINCE_KEY = 'oa_user_sync_since';
    public const LAST_RUN_KEY = 'oa_user_sync_last_run_at';
    public const SUMMARY_KEY = 'oa_user_sync_last_summary';

    private const LOCK_NAME = 'rvcwiak_oa_user_sync';
    private const MAX_ATTEMPTS = 3;
    private const MAX_PAGES = 2000;
    private const BAD_CURSOR = 1;
    private const LOG_FILE = '/storage/logs/oa-user-sync.log';

    /**
     * @return array{mode:string,since:?string,server_time:?string,pages:int,total:int,created:int,updated:int,suspended:int,skipped:int,failed:int,errors:string[],since_advanced:bool,error?:string}
     */
    public static function run(bool $full = false): array
    {
        @set_time_limit(0);

        $since = $full ? null : (Setting::get(self::SINCE_KEY) ?: null);
        $result = self::emptyResult($full, $since);

        $pdo = Database::pdo();
        if ((int) $pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            throw new RuntimeException('มีการซิงค์ผู้ใช้จาก RVC-ONE กำลังทำงานอยู่ กรุณารอให้เสร็จก่อน');
        }

        try {
            if (Oa::clientSecret() === '') {
                throw new RuntimeException('ยังไม่ได้ตั้งค่า client_secret ของ RVC-ONE (กรอกที่หน้าตั้งค่าระบบ)');
            }

            try {
                self::pass($since, $result);
            } catch (RuntimeException $e) {
                if ($e->getCode() !== self::BAD_CURSOR) {
                    throw $e;
                }
                // The gateway rejected our cursor (expired / reset): start the
                // round over from page one, once. Upserts are idempotent.
                $result = self::emptyResult($full, $since);
                self::pass($since, $result);
            }

            // A malformed row would come back in the same window forever, so
            // only a failed save (fixable locally, worth re-fetching) holds
            // `since` back.
            if ($result['failed'] === 0 && $result['server_time'] !== null) {
                Setting::set(self::SINCE_KEY, $result['server_time']);
                $result['since_advanced'] = true;
            }
            self::finish($result);
            return $result;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            self::finish($result);
            throw $e;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /** One full round: page through the API from the first page. */
    private static function pass(?string $since, array &$result): void
    {
        $type = Oa::get('sync_type') ?: 'staff';
        $limit = max(1, min(1000, (int) (Oa::get('sync_page_size') ?: 500)));
        $cursor = null;

        do {
            if ($result['pages'] >= self::MAX_PAGES) {
                throw new RuntimeException('จำนวนหน้าเกินขีดจำกัด (' . self::MAX_PAGES . ') — หยุดเพื่อป้องกันการวนไม่รู้จบ');
            }
            $params = $cursor !== null
                ? ['cursor' => $cursor, 'limit' => $limit]
                : array_filter(['type' => $type, 'since' => $since, 'limit' => $limit], static fn ($v) => $v !== null);

            $page = self::request($params);
            $result['pages']++;
            // Every page of one round carries the same server_time.
            $result['server_time'] ??= is_string($page['server_time'] ?? null) ? $page['server_time'] : null;

            foreach ($page['users'] as $row) {
                self::upsertOne($row, $result);
            }

            $hasMore = ($page['has_more'] ?? false) === true;
            $cursor = is_string($page['next_cursor'] ?? null) && $page['next_cursor'] !== '' ? $page['next_cursor'] : null;
            if ($hasMore && $cursor === null) {
                throw new RuntimeException('API แจ้งว่ายังมีข้อมูลหน้าถัดไปแต่ไม่ส่ง next_cursor มา');
            }
        } while ($hasMore);
    }

    /** @param mixed $row */
    private static function upsertOne($row, array &$result): void
    {
        $result['total']++;
        $oa = is_array($row) ? Oa::normalizeUser($row) : null;
        if ($oa === null) {
            $result['skipped']++;
            $result['errors'][] = 'พบรายการที่ไม่มี id ที่ถูกต้อง — ข้าม';
            return;
        }

        $status = is_array($row) ? (string) ($row['status'] ?? '') : '';
        try {
            if ($status === 'suspended') {
                // Don't create a disabled account for someone we've never had;
                // if they're reactivated later they'll show up as changed.
                if (User::matchOa($oa) === null) {
                    $result['skipped']++;
                    return;
                }
                User::upsertFromOa($oa, false);
                $result['suspended']++;
                return;
            }

            $out = User::upsertFromOa($oa, $status === 'active' ? true : null);
            $result[$out['created'] ? 'created' : 'updated']++;
        } catch (Throwable $e) {
            // Only the SQLSTATE: a duplicate-key message would echo the
            // conflicting value, which can be a national ID.
            $result['failed']++;
            $code = $e instanceof \PDOException ? (string) $e->getCode() : get_class($e);
            $result['errors'][] = "ผู้ใช้ RVC-ONE id {$oa['id']}: บันทึกไม่สำเร็จ ({$code})";
        }
    }

    /**
     * GET users.php with HTTP Basic auth (secret never in the query string).
     * Retries transport errors / 5xx / 429 a bounded number of times.
     *
     * @param array<string,scalar> $params
     * @return array{users:array,has_more?:bool,next_cursor?:?string,server_time?:string}
     */
    private static function request(array $params): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('เซิร์ฟเวอร์นี้ไม่มี PHP extension "curl" ซึ่งจำเป็นสำหรับการเชื่อมต่อ RVC-ONE');
        }

        $url = Oa::get('users_url') . '?' . http_build_query($params);
        $timeout = Oa::httpTimeout();
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                sleep($attempt - 1);
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => Oa::get('client_id') . ':' . Oa::clientSecret(),
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => max(2, min(5, $timeout)),
                CURLOPT_TIMEOUT        => max(10, $timeout * 3),
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $curlError = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                $lastError = 'เชื่อมต่อ RVC-ONE ไม่สำเร็จ: ' . $curlError;
                continue;
            }
            if ($status >= 500 || $status === 429) {
                $lastError = "RVC-ONE ตอบกลับด้วยสถานะ HTTP {$status}";
                continue;
            }

            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                throw new RuntimeException("ข้อมูลจาก RVC-ONE ไม่ใช่ JSON ที่ถูกต้อง (HTTP {$status})");
            }
            if (($data['ok'] ?? false) !== true) {
                $code = (string) ($data['error'] ?? 'unknown');
                $msg = trim((string) ($data['message'] ?? ''));
                throw new RuntimeException(
                    self::errorText($code, $status) . ($msg !== '' ? " — {$msg}" : ''),
                    $code === 'bad_cursor' ? self::BAD_CURSOR : 0
                );
            }
            if (!is_array($data['users'] ?? null)) {
                throw new RuntimeException('ข้อมูลจาก RVC-ONE ไม่มีรายการ users');
            }
            return $data;
        }

        throw new RuntimeException($lastError . ' (ลอง ' . self::MAX_ATTEMPTS . ' ครั้ง)');
    }

    private static function errorText(string $code, int $status): string
    {
        $map = [
            'invalid_client'   => 'client_id / client_secret ไม่ถูกต้อง หรือแอปถูกปิดใช้งานที่ RVC-ONE',
            'sync_disabled'    => 'RVC-ONE ยังไม่ได้เปิดสิทธิ์ User-sync API ให้แอปนี้',
            'type_not_allowed' => 'แอปนี้ไม่ได้รับอนุญาตให้ดึงผู้ใช้ประเภทที่ขอ',
            'bad_type'         => 'พารามิเตอร์ type ไม่ถูกต้อง',
            'bad_since'        => 'พารามิเตอร์ since ไม่ถูกต้อง',
            'bad_cursor'       => 'cursor ไม่ถูกต้องหรือหมดอายุ',
        ];
        return ($map[$code] ?? "RVC-ONE ตอบกลับข้อผิดพลาด {$code}") . " (HTTP {$status})";
    }

    private static function emptyResult(bool $full, ?string $since): array
    {
        return [
            'mode' => $full ? 'full' : ($since === null ? 'initial' : 'incremental'),
            'since' => $since,
            'server_time' => null,
            'pages' => 0,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'suspended' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'since_advanced' => false,
        ];
    }

    /** Persist the summary for /admin/settings and append one log line (counts only). */
    private static function finish(array $result): void
    {
        try {
            Setting::set(self::LAST_RUN_KEY, date('c'));
            $stored = $result;
            $stored['errors'] = array_slice($result['errors'], 0, 200);
            Setting::set(self::SUMMARY_KEY, json_encode($stored, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // The DB being down is already the error being reported.
        }

        $line = sprintf(
            "%s mode=%s pages=%d total=%d created=%d updated=%d suspended=%d skipped=%d failed=%d since_advanced=%s%s\n",
            date('c'),
            $result['mode'],
            $result['pages'],
            $result['total'],
            $result['created'],
            $result['updated'],
            $result['suspended'],
            $result['skipped'],
            $result['failed'],
            $result['since_advanced'] ? 'yes' : 'no',
            isset($result['error']) ? ' FAILED: ' . str_replace(["\r", "\n"], ' ', $result['error']) : ''
        );
        @file_put_contents(APP_ROOT . self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
