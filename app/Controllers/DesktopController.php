<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\AuditLog;
use App\Models\DesktopDevice;
use App\Models\Ticket;

/** Browser side of the desktop notifier: deep links, pairing, device removal. */
final class DesktopController extends Controller
{
    /** Target of a toast click: lands on the dashboard with the ticket open. */
    public function openTicket(array $args): void
    {
        $id = (int) $args['id'];
        if (!Auth::user()) {
            Auth::rememberReturn('/open/ticket/' . $id);
            $this->redirect('/login');
        }
        $this->redirect(Ticket::find($id) ? '/?open_ticket=' . $id : '/');
    }

    public function pairForm(): void
    {
        $p = $this->pairParams($_GET);
        if ($p === null) {
            $this->html($this->notice('ลิงก์ผูกอุปกรณ์ไม่ถูกต้อง กรุณาเริ่มใหม่จากแอป'), 400);
        }
        if (!Auth::user()) {
            Auth::rememberReturn('/desktop/pair?' . http_build_query($p));
            $this->redirect('/login');
        }
        $this->assertPairable();
        $this->page('desktop/pair', ['pageTitle' => 'เชื่อมต่อแอปแจ้งเตือน', 'pair' => $p]);
    }

    public function pairApprove(): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $this->assertPairable();
        $p = $this->pairParams($_POST);
        if ($p === null) {
            $this->html($this->notice('ข้อมูลการผูกอุปกรณ์ไม่ถูกต้อง กรุณาเริ่มใหม่จากแอป'), 400);
        }
        $code = DesktopDevice::issueCode((int) $user['id'], $p['challenge'], $p['device_name']);
        // Fixed loopback host: the code can only ever go to this computer.
        header('Cache-Control: no-store');
        header('Location: http://127.0.0.1:' . (int) $p['port'] . '/callback?' . http_build_query(['code' => $code, 'state' => $p['state']]));
        exit;
    }

    public function revokeDevice(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        if (Auth::isImpersonating()) {
            $this->json(['ok' => false, 'error' => 'ไม่สามารถจัดการอุปกรณ์ระหว่างสวมสิทธิ์'], 403);
        }
        if (!DesktopDevice::ready() || !DesktopDevice::revoke((int) $args['id'], (int) $user['id'])) {
            $this->json(['ok' => false, 'error' => 'ไม่พบอุปกรณ์นี้'], 404);
        }
        AuditLog::record((int) $user['id'], null, 'desktop.revoke', 'desktop_device', (int) $args['id']);
        $this->json(['ok' => true]);
    }

    /** A token must belong to the person at the keyboard, never an impersonated user. */
    private function assertPairable(): void
    {
        if (Auth::isImpersonating()) {
            $this->html($this->notice('ไม่สามารถเชื่อมต่อแอประหว่างสวมสิทธิ์ผู้ใช้อื่น'), 403);
        }
        if (!DesktopDevice::ready()) {
            $this->html($this->notice('ระบบยังไม่พร้อม ผู้ดูแลระบบต้องรัน migration ก่อน'), 503);
        }
    }

    /** @return array{challenge:string,state:string,port:int,device_name:string}|null */
    private function pairParams(array $src): ?array
    {
        $challenge = (string) ($src['challenge'] ?? '');
        $state = (string) ($src['state'] ?? '');
        $port = filter_var($src['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state) || $port === false) {
            return null;
        }
        return [
            'challenge' => $challenge,
            'state' => $state,
            'port' => $port,
            'device_name' => DesktopDevice::cleanName((string) ($src['device_name'] ?? '')),
        ];
    }

    private function notice(string $message): string
    {
        return '<!doctype html><meta charset="utf-8"><title>เชื่อมต่อแอปแจ้งเตือน</title>'
            . '<body style="font-family:sans-serif;max-width:520px;margin:80px auto;text-align:center;color:#0f172a">'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></body>';
    }
}
