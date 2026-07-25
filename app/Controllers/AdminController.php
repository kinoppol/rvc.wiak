<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\User;

final class AdminController extends Controller
{
    private function requireAdmin(): array
    {
        $user = $this->requireLogin();
        $real = Auth::realUser();
        if (!$real || !in_array('admin', User::rolesFor((int) $real['id']), true)) {
            $this->json(['ok' => false, 'error' => 'เฉพาะผู้ดูแลระบบเท่านั้น'], 403);
        }
        return $user;
    }

    public function impersonatePicker(): void
    {
        $this->requireAdmin();
        $people = array_filter(User::allWithRoles(), fn ($u) => (int) $u['id'] !== (int) Auth::realUser()['id']);
        $this->html(View::render('partials/impersonate_picker', ['people' => $people]));
    }

    public function impersonate(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $ok = Auth::impersonate((int) $args['id']);
        $this->json(['ok' => $ok]);
    }

    public function stopImpersonating(): void
    {
        $this->requireLogin();
        Csrf::verifyRequestOrFail();
        Auth::stopImpersonating();
        $this->json(['ok' => true]);
    }

    public function auditLog(): void
    {
        $this->requireAdmin();
        $this->page('admin/audit', [
            'logs' => AuditLog::recent(200),
            'pageTitle' => 'Audit Log',
        ]);
    }
}
