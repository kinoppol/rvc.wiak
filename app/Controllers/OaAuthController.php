<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Oa;
use App\Core\Request;
use App\Core\Url;
use App\Models\AuditLog;
use App\Models\User;

/**
 * RVC-ONE (Open Authenticator) SSO login. See App\Core\Oa for the flow overview.
 *
 * Two entry points end at the same callback:
 *  - SP-initiated: our login button → start() mints `state` → gateway → callback.
 *  - IdP-initiated: the user clicks this app's tile on the RVC-ONE Workspace
 *    page. The tile is configured with our start() URL, so it normally goes
 *    through start() too; a gateway that posts straight to the callback sends
 *    an empty `state`, which we accept only when we never minted one.
 *
 * The callback is a cross-origin POST from the gateway. It carries no app CSRF
 * token — `state` (minted in start(), stored in the session, one-time) is the
 * anti-forgery guard. The session cookie is SameSite=Lax, which still rides
 * along here because workspace.rvc.ac.th and wiak.rvc.ac.th are the same site
 * (registrable domain rvc.ac.th); keep production hosts under *.rvc.ac.th or
 * this breaks.
 */
final class OaAuthController extends Controller
{
    public function start(): void
    {
        if (Auth::user()) {
            $this->redirect('/');
        }
        if (!Oa::isEnabled()) {
            $this->redirect('/login');
        }
        $state = bin2hex(random_bytes(32));
        $_SESSION['oa_state'] = $state;
        header('Location: ' . Oa::authorizeUrl($state));
        exit;
    }

    public function callback(): void
    {
        if (!Oa::isEnabled()) {
            $this->message('ปิดใช้งานอยู่', 'การเข้าสู่ระบบผ่าน RVC-ONE ถูกปิดใช้งานโดยผู้ดูแลระบบ', 403);
        }

        if (Request::method() === 'GET') {
            // The only legitimate GET here is the user declining the gateway's
            // auth/consent screen.
            if (Request::str('error') === 'access_denied') {
                unset($_SESSION['oa_state']);
                $this->message(
                    'ยกเลิกการเข้าสู่ระบบ',
                    'คุณไม่ได้อนุญาตให้ระบบ RVC-ONE แชร์ข้อมูลกับแอปนี้',
                    200
                );
            }
            $this->message(
                'ลิงก์ไม่ถูกต้อง',
                'ไม่พบข้อมูลการเข้าสู่ระบบ กรุณาเริ่มใหม่จากหน้าเข้าสู่ระบบ',
                400
            );
        }

        // POST from the gateway. Check state before doing anything else.
        // If we minted one it must match (anti-CSRF). If we didn't and none was
        // posted, this is an IdP-initiated login from the Workspace page — let
        // it through; the real gate is the server-side token verify below.
        $expected = $_SESSION['oa_state'] ?? null;
        unset($_SESSION['oa_state']); // one-time use, pass or fail
        $state = Request::str('state');
        $hasExpected = is_string($expected) && $expected !== '';
        $stateOk = $hasExpected ? hash_equals($expected, $state) : $state === '';
        if (!$stateOk) {
            $this->message(
                'คำขอไม่ถูกต้อง',
                'การตรวจสอบความปลอดภัย (state) ไม่ผ่าน กรุณาเริ่มการเข้าสู่ระบบใหม่',
                400
            );
        }

        $oaUser = Oa::verifyToken(Request::str('token_id'), Request::str('token_key'));
        if ($oaUser === null) {
            $reason = [
                'no_secret'      => 'แอปนี้ยังไม่ได้ตั้งค่า client_secret ของ RVC-ONE กรุณาติดต่อผู้ดูแลระบบ',
                'invalid_client' => 'RVC-ONE ไม่ยอมรับ client_id / client_secret ของแอปนี้ (ค่าไม่ถูกต้อง หรือแอปถูกปิดใช้งาน) กรุณาติดต่อผู้ดูแลระบบ',
                'wrong_client'   => 'โทเคนนี้ไม่ได้ออกให้แอปนี้ กรุณาเริ่มการเข้าสู่ระบบใหม่',
                'invalid_token'  => 'โทเคนไม่ถูกต้องหรือหมดอายุ (มีอายุ 5 นาที) กรุณาเริ่มการเข้าสู่ระบบใหม่',
                'transport'      => 'ติดต่อระบบยืนยันตัวตน RVC-ONE ไม่ได้ (ไม่ตอบสนองหรือหมดเวลา) กรุณาลองใหม่ภายหลัง',
                'no_curl'        => 'เซิร์ฟเวอร์ไม่มีส่วนขยาย PHP curl กรุณาติดต่อผู้ดูแลระบบ',
                'bad_response'   => 'ระบบ RVC-ONE ตอบกลับในรูปแบบที่ไม่ถูกต้อง กรุณาลองใหม่ภายหลัง',
            ][Oa::$lastError] ?? 'ไม่สามารถยืนยันตัวตนกับระบบ RVC-ONE ได้';
            $this->message('เข้าสู่ระบบไม่สำเร็จ', $reason, 401);
        }

        $user = User::provisionFromOa($oaUser);
        if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
            $this->message(
                'บัญชีถูกระงับ',
                'บัญชีผู้ใช้นี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
                403
            );
        }

        Auth::login($user);
        AuditLog::record((int) $user['id'], (int) $user['id'], 'login.oa', 'user', (int) $user['id']);
        $this->redirect('/');
    }

    /** Minimal standalone page — the callback runs outside the app layout. */
    private function message(string $title, string $body, int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $t = htmlspecialchars($title, ENT_QUOTES);
        $b = htmlspecialchars($body, ENT_QUOTES);
        $login = htmlspecialchars(Url::to('/login'), ENT_QUOTES);
        echo "<!doctype html><meta charset=\"utf-8\"><title>{$t}</title>"
            . '<body style="font-family:sans-serif;max-width:520px;margin:80px auto;text-align:center;color:#0f172a">'
            . "<h1 style=\"font-size:1.3rem\">{$t}</h1>"
            . "<p style=\"color:#475569\">{$b}</p>"
            . "<p><a href=\"{$login}\">กลับไปหน้าเข้าสู่ระบบ</a></p></body>";
        exit;
    }
}
