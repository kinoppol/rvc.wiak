<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Oa;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\PeopleSync;
use Throwable;

final class SettingsController extends Controller
{
    private function requireAdmin(): array
    {
        $user = $this->requireLogin();
        $real = Auth::realUser();
        if (!$real || !in_array('admin', User::rolesFor((int) $real['id']), true)) {
            $this->redirect('/');
        }
        return $user;
    }

    public function index(): void
    {
        $this->requireAdmin();

        $summaryJson = Setting::get('people_sync_last_summary');
        $lastSummary = $summaryJson ? json_decode($summaryJson, true) : null;

        $this->page('admin/settings', [
            'pageTitle' => 'ตั้งค่าระบบ',
            'baseUrl' => Setting::get(PeopleSync::SETTING_KEY, ''),
            'lastRunAt' => Setting::get('people_sync_last_run_at'),
            'lastSummary' => is_array($lastSummary) ? $lastSummary : null,
            'urlUpdated' => Request::str('urlUpdated') === '1',
            'synced' => Request::str('synced') === '1',
            'syncError' => Request::str('syncError'),
            'warnDaysDefault' => max(1, (int) Setting::get('notify_warn_days_default', '3')),
            'urgentHours' => max(1, (int) Setting::get('notify_urgent_hours', '24')),
            'notifySaved' => Request::str('notifySaved') === '1',
            'oaEnabled' => Oa::isEnabled(),
            'oaButtonLabel' => Oa::buttonLabel(),
            'oaButtonLabelDefault' => Oa::DEFAULT_BUTTON_LABEL,
            'oaAuthorizeUrl' => Oa::get('authorize_url'),
            'oaVerifyUrl' => Oa::get('verify_token_url'),
            'oaClientId' => Oa::get('client_id'),
            'oaRedirectUri' => Oa::get('redirect_uri'),
            'oaSaved' => Request::str('oaSaved') === '1',
            'oaError' => Request::str('oaError'),
        ]);
    }

    public function updateOauth(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $authorizeUrl = rtrim(Request::str('authorize_url'), '/');
        $verifyUrl = rtrim(Request::str('verify_token_url'), '/');
        $clientId = Request::str('client_id');
        $redirectUri = Request::str('redirect_uri');
        $enabled = Request::str('enabled') === '1';
        $buttonLabel = mb_substr(Request::str('button_label'), 0, 120);

        foreach (['Authorization endpoint' => $authorizeUrl, 'Token verify endpoint' => $verifyUrl, 'redirect_uri' => $redirectUri] as $label => $u) {
            $p = parse_url($u);
            if ($u === '' || empty($p['scheme']) || empty($p['host']) || !in_array($p['scheme'], ['http', 'https'], true)) {
                $this->redirect('/admin/settings?oaError=' . rawurlencode("URL ไม่ถูกต้อง: {$label}"));
            }
        }
        if ($clientId === '') {
            $this->redirect('/admin/settings?oaError=' . rawurlencode('ต้องระบุ client_id'));
        }

        Setting::set('oa_enabled', $enabled ? '1' : '0');
        Setting::set('oa_button_label', $buttonLabel !== '' ? $buttonLabel : Oa::DEFAULT_BUTTON_LABEL);
        Setting::set('oa_authorize_url', $authorizeUrl);
        Setting::set('oa_verify_token_url', $verifyUrl);
        Setting::set('oa_client_id', $clientId);
        Setting::set('oa_redirect_uri', $redirectUri);
        AuditLog::record(
            (int) Auth::realUser()['id'],
            null,
            'settings.update_oauth',
            'setting',
            null,
            'enabled=' . ($enabled ? '1' : '0') . " client_id={$clientId}"
        );

        $this->redirect('/admin/settings?oaSaved=1');
    }

    public function updateNotify(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $days = max(1, min(60, Request::int('warn_days_default', 3)));
        $hours = max(1, min(720, Request::int('urgent_hours', 24)));

        Setting::set('notify_warn_days_default', (string) $days);
        Setting::set('notify_urgent_hours', (string) $hours);
        AuditLog::record((int) Auth::realUser()['id'], null, 'settings.update_notify', 'setting', null, "warn_days={$days} urgent_hours={$hours}");

        $this->redirect('/admin/settings?notifySaved=1');
    }

    public function updateUrl(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $url = rtrim(Request::str('base_url'), '/');
        $parts = parse_url($url);
        if ($url === '' || empty($parts['scheme']) || empty($parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            $this->redirect('/admin/settings?urlError=1');
        }

        Setting::set(PeopleSync::SETTING_KEY, $url);
        AuditLog::record((int) Auth::realUser()['id'], null, 'settings.update_api_url', 'setting', null, $url);
        $this->redirect('/admin/settings?urlUpdated=1');
    }

    public function sync(): void
    {
        $user = $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        try {
            $result = PeopleSync::run();
            AuditLog::record(
                (int) Auth::realUser()['id'],
                Auth::isImpersonating() ? (int) $user['id'] : null,
                'people.sync',
                null,
                null,
                "created={$result['created']} updated={$result['updated']} skipped={$result['skipped']} total={$result['total']}"
            );
            $this->redirect('/admin/settings?synced=1');
        } catch (Throwable $e) {
            $this->redirect('/admin/settings?syncError=' . rawurlencode($e->getMessage()));
        }
    }
}
