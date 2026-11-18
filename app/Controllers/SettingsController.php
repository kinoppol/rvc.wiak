<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Oa;
use App\Core\Request;
use App\Core\Url;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\OaUserSync;
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
        $oaSyncJson = Setting::get(OaUserSync::SUMMARY_KEY);
        $oaSyncSummary = $oaSyncJson ? json_decode($oaSyncJson, true) : null;

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
            'oaStartUrl' => self::publicUrl('/api/login.php', Oa::get('redirect_uri')),
            'oaUsersUrl' => Oa::get('users_url'),
            'oaSyncType' => Oa::get('sync_type'),
            'oaSecretSet' => Oa::clientSecret() !== '',
            'oaSecretSource' => Oa::clientSecretSource(),
            'oaSecretSaved' => Request::str('oaSecretSaved'),
            'oaSyncSince' => Setting::get(OaUserSync::SINCE_KEY),
            'oaSyncLastRunAt' => Setting::get(OaUserSync::LAST_RUN_KEY),
            'oaSyncSummary' => is_array($oaSyncSummary) ? $oaSyncSummary : null,
            'oaSynced' => Request::str('oaSynced') === '1',
            'oaSyncError' => Request::str('oaSyncError'),
            'schoolName' => (string) Setting::get('school_name', ''),
            'schoolNameDefault' => Setting::DEFAULT_SCHOOL_NAME,
            'academicYear' => (string) Setting::get('academic_year', ''),
            'academicTermSetting' => (string) Setting::get('academic_term', ''),
            'academic' => Setting::academicTerm(),
            'generalSaved' => Request::str('generalSaved') === '1',
            'generalError' => Request::str('generalError'),
        ]);
    }

    /**
     * Absolute URL of an app route, for pasting into another system (the
     * RVC-ONE app's "SSO start URL" field). Uses the registered redirect_uri's
     * scheme when we're on its host — behind Cloudflare the origin request may
     * look like plain http even though the public URL is https.
     */
    private static function publicUrl(string $path, string $redirectUri): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $r = parse_url($redirectUri);
        if (is_array($r) && ($r['host'] ?? '') === $host && !empty($r['scheme'])) {
            $scheme = $r['scheme'];
        } else {
            $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
            $scheme = $https || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        }
        return $scheme . '://' . $host . Url::to($path);
    }

    public function oaSync(): void
    {
        $user = $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $full = Request::str('full') === '1';

        try {
            $r = OaUserSync::run($full);
            AuditLog::record(
                (int) Auth::realUser()['id'],
                Auth::isImpersonating() ? (int) $user['id'] : null,
                'oa_users.sync',
                null,
                null,
                "mode={$r['mode']} created={$r['created']} updated={$r['updated']} suspended={$r['suspended']} skipped={$r['skipped']} failed={$r['failed']} total={$r['total']}"
            );
            $this->redirect('/admin/settings?oaSynced=1#rvc-one');
        } catch (Throwable $e) {
            $this->redirect('/admin/settings?oaSyncError=' . rawurlencode($e->getMessage()) . '#rvc-one');
        }
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
                $this->redirect('/admin/settings?oaError=' . rawurlencode("URL ไม่ถูกต้อง: {$label}") . '#rvc-one');
            }
        }
        if ($clientId === '') {
            $this->redirect('/admin/settings?oaError=' . rawurlencode('ต้องระบุ client_id') . '#rvc-one');
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

        $this->redirect('/admin/settings?oaSaved=1#rvc-one');
    }

    /**
     * Save or clear the RVC-ONE client_secret. Write-only: the page never
     * echoes the stored value back, and it's never logged or audited.
     */
    public function updateOaSecret(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        if (Request::str('clear') === '1') {
            Setting::set(Oa::SECRET_SETTING, '');
            $action = 'cleared';
        } else {
            $secret = trim((string) ($_POST['client_secret'] ?? ''));
            if ($secret === '') {
                $this->redirect('/admin/settings?oaError=' . rawurlencode('กรุณากรอก client_secret') . '#rvc-one');
            }
            if (strlen($secret) > 512) {
                $this->redirect('/admin/settings?oaError=' . rawurlencode('client_secret ยาวเกินไป') . '#rvc-one');
            }
            Setting::set(Oa::SECRET_SETTING, $secret);
            $action = 'set';
        }

        AuditLog::record(
            (int) Auth::realUser()['id'],
            null,
            'settings.update_oa_secret',
            'setting',
            null,
            $action
        );

        $this->redirect('/admin/settings?oaSecretSaved=' . $action . '#rvc-one');
    }

    public function updateGeneral(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $name = mb_substr(trim(Request::str('school_name')), 0, 150);
        $year = trim(Request::str('academic_year'));
        $term = trim(Request::str('academic_term'));

        if ($year !== '' && (!ctype_digit($year) || (int) $year < 2500 || (int) $year > 2700)) {
            $this->redirect('/admin/settings?generalError=' . rawurlencode('ปีการศึกษาต้องเป็น พ.ศ. 4 หลัก เช่น 2569 หรือเว้นว่างเพื่อคำนวณอัตโนมัติ') . '#general');
        }
        if ($term !== '' && !in_array($term, ['1', '2', '3'], true)) {
            $this->redirect('/admin/settings?generalError=' . rawurlencode('ภาคเรียนไม่ถูกต้อง') . '#general');
        }

        Setting::set('school_name', $name);
        Setting::set('academic_year', $year);
        Setting::set('academic_term', $term);
        AuditLog::record(
            (int) Auth::realUser()['id'],
            null,
            'settings.update_general',
            'setting',
            null,
            'school_name=' . $name . ' academic_year=' . ($year !== '' ? $year : 'auto') . ' academic_term=' . ($term !== '' ? $term : 'auto')
        );

        $this->redirect('/admin/settings?generalSaved=1#general');
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

        $this->redirect('/admin/settings?notifySaved=1#general');
    }

    public function updateUrl(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $url = rtrim(Request::str('base_url'), '/');
        $parts = parse_url($url);
        if ($url === '' || empty($parts['scheme']) || empty($parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            $this->redirect('/admin/settings?urlError=1#rms');
        }

        Setting::set(PeopleSync::SETTING_KEY, $url);
        AuditLog::record((int) Auth::realUser()['id'], null, 'settings.update_api_url', 'setting', null, $url);
        $this->redirect('/admin/settings?urlUpdated=1#rms');
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
            $this->redirect('/admin/settings?synced=1#rms');
        } catch (Throwable $e) {
            $this->redirect('/admin/settings?syncError=' . rawurlencode($e->getMessage()) . '#rms');
        }
    }
}
