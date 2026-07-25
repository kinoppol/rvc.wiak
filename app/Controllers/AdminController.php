<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;
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

    public function editRolesForm(array $args): void
    {
        $this->requireAdmin();
        $targetId = (int) $args['id'];
        $target = User::findById($targetId);
        if (!$target) {
            $this->html('<div class="p-4 text-danger">ไม่พบผู้ใช้นี้</div>', 404);
        }

        $this->html(View::render('partials/user_roles_edit', [
            'target' => $target,
            'currentRoles' => User::rolesFor($targetId),
            'roles' => Role::all(),
        ]));
    }

    public function updateRoles(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $targetId = (int) $args['id'];
        $target = User::findById($targetId);
        if (!$target) {
            $this->json(['ok' => false, 'error' => 'ไม่พบผู้ใช้นี้'], 404);
        }

        $submitted = $_POST['roles'] ?? [];
        $validCodes = array_keys(Role::all());
        $roleCodes = array_values(array_intersect($validCodes, is_array($submitted) ? $submitted : []));

        if (!$roleCodes) {
            $this->json(['ok' => false, 'error' => 'กรุณาเลือกอย่างน้อย 1 บทบาท'], 422);
        }

        $realId = (int) Auth::realUser()['id'];
        if ($targetId === $realId && !in_array('admin', $roleCodes, true) && in_array('admin', User::rolesFor($targetId), true)) {
            $this->json(['ok' => false, 'error' => 'ไม่สามารถถอดบทบาทผู้ดูแลระบบออกจากบัญชีของตนเองได้'], 422);
        }

        User::setRoles($targetId, $roleCodes);
        AuditLog::record($realId, null, 'user.update_roles', 'user', $targetId, implode(',', $roleCodes));

        $this->json(['ok' => true]);
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
