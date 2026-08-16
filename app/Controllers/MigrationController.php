<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Migration;
use App\Models\User;
use Throwable;

final class MigrationController extends Controller
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

        $this->page('admin/migrations', [
            'pageTitle' => 'จัดการโครงสร้างฐานข้อมูล (Migrations)',
            'pending' => Migration::pending(),
            'history' => Migration::history(),
            'ranCount' => Request::int('ran', 0),
            'runError' => Request::str('error'),
        ]);
    }

    public function run(): void
    {
        $user = $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $real = Auth::realUser();
        $actingAsId = Auth::isImpersonating() ? (int) $user['id'] : null;
        $filename = Request::str('filename');

        try {
            if ($filename === 'all') {
                $results = Migration::runAllPending((int) $real['id']);
                foreach ($results as $r) {
                    AuditLog::record((int) $real['id'], $actingAsId, 'migration.run', 'migration', null, $r['filename'] . " ({$r['statements']} statements)");
                }
                $this->redirect('/admin/migrations?ran=' . count($results));
            } else {
                $result = Migration::run($filename, (int) $real['id']);
                AuditLog::record((int) $real['id'], $actingAsId, 'migration.run', 'migration', null, $result['filename'] . " ({$result['statements']} statements)");
                $this->redirect('/admin/migrations?ran=1');
            }
        } catch (Throwable $e) {
            $this->redirect('/admin/migrations?error=' . rawurlencode($e->getMessage()));
        }
    }
}
