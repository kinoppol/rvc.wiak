<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\Chat;
use App\Models\DesktopDevice;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketEdit;
use App\Models\User;

/**
 * JSON API for the Windows notifier, under /api/desktop/. Authenticated by a
 * bearer token, with no cookies or PHP session (see bootstrap.php), which is
 * why these POSTs carry no CSRF token. Responses must never be cached by
 * Cloudflare.
 */
final class DesktopApiController extends Controller
{
    private const POLL_SECONDS = 30;

    public function __construct()
    {
        header('Cache-Control: no-store, private');
        if (!DesktopDevice::ready()) {
            $this->json(['ok' => false, 'error' => 'not_ready'], 503);
        }
    }

    /** POST code + code_verifier → bearer token (returned once). */
    public function token(): void
    {
        $code = (string) ($_POST['code'] ?? '');
        $verifier = (string) ($_POST['code_verifier'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $code) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            $this->json(['ok' => false, 'error' => 'invalid_request'], 400);
        }
        $pair = DesktopDevice::consumeCode($code, $verifier);
        $user = $pair ? User::findById($pair['user_id']) : null;
        if (!$pair || !$user || (int) $user['is_active'] !== 1) {
            $this->json(['ok' => false, 'error' => 'invalid_grant'], 400);
        }

        // Existing alerts are stored as seen before the cursor is taken, so
        // a new device starts quiet and only hears about what happens next.
        Notification::recordDue($user, true);
        $device = DesktopDevice::create((int) $user['id'], $pair['device_name']);
        AuditLog::record((int) $user['id'], null, 'desktop.pair', 'desktop_device', $device['id']);

        $this->json([
            'ok' => true,
            'token' => $device['token'],
            'device_id' => $device['id'],
            'cursor' => Notification::latestId((int) $user['id']),
            'message_cursor' => Chat::ready() ? Chat::latestIdFor((int) $user['id']) : 0,
            'user' => self::userInfo($user),
            'poll_seconds' => self::POLL_SECONDS,
        ]);
    }

    /** GET ?after={cursor}&limit={n} */
    public function notifications(): void
    {
        ['user' => $user] = $this->authenticate();
        $userId = (int) $user['id'];
        $feed = Notification::feed($userId, max(0, (int) ($_GET['after'] ?? 0)), (int) ($_GET['limit'] ?? 50));
        $alerts = Ticket::alertsFor($userId, User::warnDaysFor($user), max(1, (int) Setting::get('notify_urgent_hours', '24')));

        // Chat: without after_message (an app that has no cursor yet) only the
        // current position comes back, so it starts quiet instead of flooding.
        $messages = [];
        $messageCursor = 0;
        $unreadMessages = 0;
        if (Chat::ready()) {
            if (isset($_GET['after_message']) && is_numeric($_GET['after_message'])) {
                $chat = Chat::feedForDevice($userId, max(0, (int) $_GET['after_message']));
                $messages = $chat['items'];
                $messageCursor = $chat['cursor'];
            } else {
                $messageCursor = Chat::latestIdFor($userId);
            }
            $unreadMessages = Chat::unreadTotal($userId);
        }

        $this->json([
            'messages' => $messages,
            'message_cursor' => $messageCursor,
            'unread_messages' => $unreadMessages,
            'ok' => true,
            'items' => $feed['items'],
            'cursor' => $feed['cursor'],
            'has_more' => $feed['has_more'],
            'unread' => Notification::unreadCount($userId),
            'summary' => [
                'urgent' => count($alerts['urgent']),
                'warning' => count($alerts['warning']),
                'edits' => count(TicketEdit::unseenFor($userId)),
            ],
            'user' => self::userInfo($user),
            'poll_seconds' => self::POLL_SECONDS,
            'server_time' => date('c'),
        ]);
    }

    /** POST ids[]=1&ids[]=2, or all=1 */
    public function markRead(): void
    {
        ['user' => $user] = $this->authenticate();
        $userId = (int) $user['id'];
        if (($_POST['all'] ?? '') === '1') {
            $marked = Notification::markAllRead($userId);
        } else {
            $ids = $_POST['ids'] ?? [];
            $marked = Notification::markRead($userId, is_array($ids) ? $ids : [$ids]);
        }
        $this->json(['ok' => true, 'marked' => $marked, 'unread' => Notification::unreadCount($userId)]);
    }

    /** The app signing itself out. */
    public function revoke(): void
    {
        ['device' => $device, 'user' => $user] = $this->authenticate();
        DesktopDevice::revoke((int) $device['id'], (int) $user['id']);
        AuditLog::record((int) $user['id'], null, 'desktop.revoke', 'desktop_device', (int) $device['id']);
        $this->json(['ok' => true]);
    }

    /** @return array{device:array,user:array} */
    private function authenticate(): array
    {
        // Under CGI/FPM the header arrives via the rewrite rule in .htaccess.
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $auth = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? DesktopDevice::authenticate($m[1]) : null;
        if (!$auth) {
            header('WWW-Authenticate: Bearer');
            $this->json(['ok' => false, 'error' => 'invalid_token'], 401);
        }
        DesktopDevice::touch($auth['device'], $_SERVER['HTTP_X_APP_VERSION'] ?? null);
        return $auth;
    }

    private static function userInfo(array $user): array
    {
        return ['id' => (int) $user['id'], 'full_name' => $user['full_name']];
    }
}
