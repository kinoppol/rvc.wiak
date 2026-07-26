<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Models\Setting;
use App\Models\User;

final class ProfileController extends Controller
{
    public function switchRole(): void
    {
        $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $role = Request::str('role');
        $ok = Auth::setActiveRole($role);
        $this->json(['ok' => $ok]);
    }

    public function preferences(): void
    {
        $user = $this->requireLogin();
        $this->page('preferences', [
            'pageTitle' => 'การแจ้งเตือนของฉัน',
            'ownWarnDays' => $user['notify_warn_days'],
            'defaultWarnDays' => max(1, (int) Setting::get('notify_warn_days_default', '3')),
            'urgentHours' => max(1, (int) Setting::get('notify_urgent_hours', '24')),
            'saved' => Request::str('saved') === '1',
        ]);
    }

    public function updatePreferences(): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();

        // An empty field means "follow the admin default", stored as NULL.
        $raw = Request::str('warn_days');
        if ($raw === '') {
            User::setWarnDays((int) $user['id'], null);
        } else {
            $days = max(1, min(60, (int) $raw));
            User::setWarnDays((int) $user['id'], $days);
        }

        $this->redirect('/preferences?saved=1');
    }
}
