<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\OrgUnit;
use App\Models\User;

final class OrgController extends Controller
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

    /** Validates a type coming off the URL before it reaches OrgUnit. */
    private function typeOrFail(array $args): string
    {
        $type = (string) ($args['type'] ?? '');
        if (!OrgUnit::isType($type)) {
            http_response_code(404);
            exit;
        }
        return $type;
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->page('admin/org', [
            'pageTitle' => 'จัดการฝ่าย งาน และแผนก',
            'divisions' => OrgUnit::all('division'),
            'works' => OrgUnit::all('work'),
            'departments' => OrgUnit::all('department'),
            'notice' => Request::str('notice'),
            'error' => Request::str('error'),
        ]);
    }

    public function store(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $type = $this->typeOrFail($args);

        $name = Request::str('name');
        if ($name === '') {
            $this->redirect('/admin/org?error=' . rawurlencode('กรุณากรอกชื่อ' . OrgUnit::label($type)));
        }

        $divisionId = $type === 'work' ? (Request::int('division_id') ?: null) : null;
        try {
            OrgUnit::create($type, $name, $divisionId);
        } catch (\PDOException $e) {
            $this->redirect('/admin/org?error=' . rawurlencode('มีชื่อนี้อยู่แล้วในระบบ: ' . $name));
        }

        AuditLog::record((int) Auth::realUser()['id'], null, 'org.create', $type, null, $name);
        $this->redirect('/admin/org?notice=' . rawurlencode('เพิ่ม' . OrgUnit::label($type) . '"' . $name . '"แล้ว'));
    }

    public function update(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $type = $this->typeOrFail($args);
        $id = (int) $args['id'];

        $name = Request::str('name');
        if ($name === '') {
            $this->redirect('/admin/org?error=' . rawurlencode('กรุณากรอกชื่อ' . OrgUnit::label($type)));
        }

        $divisionId = $type === 'work' ? (Request::int('division_id') ?: null) : null;
        try {
            OrgUnit::update($type, $id, $name, $divisionId);
        } catch (\PDOException $e) {
            $this->redirect('/admin/org?error=' . rawurlencode('มีชื่อนี้อยู่แล้วในระบบ: ' . $name));
        }

        AuditLog::record((int) Auth::realUser()['id'], null, 'org.update', $type, $id, $name);
        $this->redirect('/admin/org?notice=' . rawurlencode('บันทึกการแก้ไขแล้ว'));
    }

    public function delete(array $args): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();
        $type = $this->typeOrFail($args);
        $id = (int) $args['id'];

        $unit = OrgUnit::find($type, $id);
        if (!$unit) {
            $this->redirect('/admin/org?error=' . rawurlencode('ไม่พบรายการที่ต้องการลบ'));
        }

        if (!OrgUnit::delete($type, $id)) {
            $n = OrgUnit::usageCount($type, $id);
            $this->redirect('/admin/org?error=' . rawurlencode(
                'ไม่สามารถลบ "' . $unit['name'] . '" ได้ เนื่องจากมีผู้ใช้ ' . $n . ' รายการที่ถูกกำหนดบทบาทใน' . OrgUnit::label($type) . 'นี้ กรุณาแก้ไขบทบาทของผู้ใช้เหล่านั้นก่อน'
            ));
        }

        AuditLog::record((int) Auth::realUser()['id'], null, 'org.delete', $type, $id, (string) $unit['name']);
        $this->redirect('/admin/org?notice=' . rawurlencode('ลบ "' . $unit['name'] . '" แล้ว'));
    }
}
