<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

final class AdminController extends Controller
{
    private const USERS_PER_PAGE = 20;

    private function requireAdmin(): array
    {
        $user = $this->requireLogin();
        $real = Auth::realUser();
        if (!$real || !in_array('admin', User::rolesFor((int) $real['id']), true)) {
            $this->json(['ok' => false, 'error' => 'เฉพาะผู้ดูแลระบบเท่านั้น'], 403);
        }
        return $user;
    }

    public function manageUsers(): void
    {
        $this->requireAdmin();
        $realId = (int) Auth::realUser()['id'];

        $q = Request::str('q');
        $role = Request::str('role');
        $page = Request::int('page', 1);

        $result = User::searchPaged(['q' => $q, 'role' => $role], $page, self::USERS_PER_PAGE);
        $result['rows'] = array_map(
            fn ($u) => $u + ['isSelf' => (int) $u['id'] === $realId],
            $result['rows']
        );

        $this->page('admin/users', [
            'pageTitle' => 'จัดการผู้ใช้และบทบาท',
            'people' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'lastPage' => $result['lastPage'],
            'q' => $q,
            'role' => $role,
            'roles' => Role::all(),
        ]);
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
