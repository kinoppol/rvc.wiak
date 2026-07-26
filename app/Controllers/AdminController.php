<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\OrgUnit;
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
            'currentUnits' => User::roleUnitsFor($targetId),
            'roles' => Role::all(),
            'unitsByType' => [
                'division' => OrgUnit::all('division'),
                'work' => OrgUnit::all('work'),
                'department' => OrgUnit::all('department'),
            ],
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
        $allRoles = Role::all();
        $validCodes = array_keys($allRoles);
        $roleCodes = array_values(array_intersect($validCodes, is_array($submitted) ? $submitted : []));

        if (!$roleCodes) {
            $this->json(['ok' => false, 'error' => 'กรุณาเลือกอย่างน้อย 1 บทบาท'], 422);
        }

        $realId = (int) Auth::realUser()['id'];
        if ($targetId === $realId && !in_array('admin', $roleCodes, true) && in_array('admin', User::rolesFor($targetId), true)) {
            $this->json(['ok' => false, 'error' => 'ไม่สามารถถอดบทบาทผู้ดูแลระบบออกจากบัญชีของตนเองได้'], 422);
        }

        // Roles inside the org chart must name the unit(s) they apply to,
        // and those ids have to be real ones of the right kind.
        $submittedUnits = $_POST['units'] ?? [];
        $unitsByRole = [];
        foreach ($roleCodes as $code) {
            $unitType = Role::unitType($code);
            if ($unitType === null) {
                continue;
            }
            $label = $allRoles[$code]['label'];
            $unitLabel = OrgUnit::label($unitType);

            $ids = $submittedUnits[$code] ?? [];
            $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];

            $validIds = array_map('intval', array_column(OrgUnit::all($unitType), 'id'));
            $ids = array_values(array_intersect($ids, $validIds));

            if (!$ids) {
                $this->json(['ok' => false, 'error' => "บทบาท \"{$label}\" ต้องระบุ{$unitLabel}อย่างน้อย 1 รายการ"], 422);
            }
            if (Role::needsSingleUnit($code) && count($ids) > 1) {
                $this->json(['ok' => false, 'error' => "บทบาท \"{$label}\" ระบุ{$unitLabel}ได้เพียง 1 รายการ"], 422);
            }
            $unitsByRole[$code] = $ids;
        }

        User::setRoles($targetId, $roleCodes, $unitsByRole);

        $detail = [];
        foreach ($roleCodes as $code) {
            $detail[] = $code . (isset($unitsByRole[$code]) ? '(' . implode('/', $unitsByRole[$code]) . ')' : '');
        }
        AuditLog::record($realId, null, 'user.update_roles', 'user', $targetId, implode(',', $detail));

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
