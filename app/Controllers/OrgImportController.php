<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\OrgUnit;
use App\Models\Setting;
use App\Models\User;
use App\Services\RmsOrgChartParser;
use App\Services\RmsOrgImport;
use Throwable;

/**
 * /admin/org-import — import the staff structure from RMS's pms_graph.php.
 * Preview first (read-only), then apply; see App\Services\RmsOrgImport.
 */
final class OrgImportController extends Controller
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

    private function render(array $vars): void
    {
        $this->page('admin/org_import', $vars + [
            'pageTitle' => 'นำเข้าโครงสร้างบุคลากรจาก RMS',
            'url' => RmsOrgImport::url(),
            'defaultUrl' => RmsOrgImport::DEFAULT_URL,
            'lastRunAt' => Setting::get(RmsOrgImport::LAST_RUN_KEY),
            'divisions' => OrgUnit::all('division'),
            'plan' => null,
            'fingerprint' => '',
            'skipRetired' => true,
            'error' => '',
            'done' => Request::str('done'),
        ]);
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->render([]);
    }

    public function preview(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $url = trim(Request::str('url'));
        $skipRetired = Request::str('skip_retired') === '1';
        if ($url !== '' && $url !== RmsOrgImport::url()) {
            $p = parse_url($url);
            if (empty($p['scheme']) || empty($p['host']) || !in_array($p['scheme'], ['http', 'https'], true)) {
                $this->render(['error' => 'URL ไม่ถูกต้อง', 'skipRetired' => $skipRetired]);
            }
            Setting::set(RmsOrgImport::URL_SETTING, $url);
        }

        try {
            $chart = RmsOrgChartParser::parse(RmsOrgImport::fetch(RmsOrgImport::url()));
            $plan = RmsOrgImport::plan($chart, [], $skipRetired);
        } catch (Throwable $e) {
            $this->render(['error' => $e->getMessage(), 'skipRetired' => $skipRetired]);
        }

        $this->render([
            'plan' => $plan,
            'fingerprint' => RmsOrgImport::fingerprint($chart),
            'skipRetired' => $skipRetired,
        ]);
    }

    public function apply(): void
    {
        $user = $this->requireAdmin();
        Csrf::verifyRequestOrFail();

        $skipRetired = Request::str('skip_retired') === '1';
        $divisionMap = [];
        foreach ((array) ($_POST['division_map'] ?? []) as $name => $id) {
            $divisionMap[(string) $name] = (int) $id;
        }

        try {
            $chart = RmsOrgChartParser::parse(RmsOrgImport::fetch(RmsOrgImport::url()));
            if (!hash_equals(RmsOrgImport::fingerprint($chart), Request::str('fingerprint'))) {
                $this->render(['error' => 'ข้อมูลบนหน้า RMS เปลี่ยนไปหลังจากดูตัวอย่าง กรุณากดดูตัวอย่างใหม่อีกครั้ง', 'skipRetired' => $skipRetired]);
            }
            $plan = RmsOrgImport::plan($chart, $divisionMap, $skipRetired);
            $r = RmsOrgImport::apply($plan);
        } catch (Throwable $e) {
            $this->render(['error' => 'นำเข้าไม่สำเร็จ: ' . $e->getMessage(), 'skipRetired' => $skipRetired]);
        }

        AuditLog::record(
            (int) Auth::realUser()['id'],
            Auth::isImpersonating() ? (int) $user['id'] : null,
            'org.import_rms',
            null,
            null,
            "units_created={$r['units_created']} people_updated={$r['people_updated']} roles_added={$r['roles_added']} unmatched=" . count($plan['unmatched'])
        );

        $this->redirect('/admin/org-import?done=' . rawurlencode(
            "สร้างหน่วยงานใหม่ {$r['units_created']} · อัปเดตบทบาท {$r['people_updated']} คน · ไม่พบบัญชี " . count($plan['unmatched']) . ' ชื่อ'
        ));
    }
}
