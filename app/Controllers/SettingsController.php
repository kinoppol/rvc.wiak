<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
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
        ]);
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
