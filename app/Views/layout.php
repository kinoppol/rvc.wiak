<?php
/** @var string $content */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;

$user = Auth::user();
$roles = $user ? User::rolesFor((int) $user['id']) : [];
$activeRole = Auth::activeRole();
$roleInfo = $activeRole ? Role::find($activeRole) : null;
$allRoles = Role::all();
$crossCount = $user ? Ticket::stats()['cross'] : 0;
$isAdmin = in_array('admin', $roles, true);
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($pageTitle ?? 'ระบบมอบหมายและติดตามงาน') ?></title>
<link href="<?= Url::asset('vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= Url::asset('vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
<link href="<?= Url::asset('css/app.css') ?>" rel="stylesheet">
</head>
<body data-base="<?= Url::base() ?>" data-csrf="<?= View::e(Csrf::token()) ?>">
<?php if (!$user): ?>
  <?= $content ?>
<?php else: ?>
  <div class="sidebar-backdrop" data-sidebar-close></div>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="d-flex align-items-center gap-2 justify-content-between pb-3 mb-1" style="border-bottom:1px solid rgba(255,255,255,.1)">
        <div class="d-flex align-items-center gap-2">
          <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#0ea5e9);display:grid;place-items:center;color:#fff;font-size:1.1rem"><i class="bi bi-clipboard2-check-fill"></i></div>
          <div style="line-height:1.25">
            <div style="color:#fff;font-weight:600;font-size:.92rem">ระบบมอบหมายงาน</div>
            <div style="color:#64748b;font-size:.72rem">EduTask Tracking v1.0</div>
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-light offcanvas-sidebar-close" data-sidebar-close><i class="bi bi-x-lg"></i></button>
      </div>

      <div>
        <div style="color:#64748b;font-size:.68rem;letter-spacing:.09em;text-transform:uppercase;padding:0 4px 8px">บทบาทที่ใช้งาน</div>
        <select id="role-switcher" class="form-select form-select-sm" style="background:#1e293b;color:#e2e8f0;border-color:#334155">
          <?php foreach ($roles as $rc): $r = $allRoles[$rc] ?? null; if (!$r) { continue; } ?>
            <option value="<?= View::e($rc) ?>" <?= $rc === $activeRole ? 'selected' : '' ?>><?= View::e($r['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="d-flex flex-wrap gap-1 pt-2">
          <?php foreach ($roles as $rc): $r = $allRoles[$rc] ?? null; if (!$r) { continue; } ?>
            <span class="badge rounded-pill" style="background:<?= View::e($r['chip_bg']) ?>;color:<?= View::e($r['chip_fg']) ?>;font-weight:500;font-size:.66rem"><i class="bi bi-<?= View::e($r['icon']) ?>"></i> <?= View::e($r['short_label']) ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <nav class="d-flex flex-column gap-1 mt-3">
        <div style="color:#64748b;font-size:.68rem;letter-spacing:.09em;text-transform:uppercase;padding:0 4px 8px">เมนู</div>
        <a class="side-link <?= ($_SERVER['REQUEST_URI'] ?? '') === Url::to('/') || rtrim($_SERVER['REQUEST_URI'] ?? '', '/') === rtrim(Url::to(''), '/') ? 'active' : '' ?>" href="<?= Url::to('/') ?>">
          <i class="bi bi-speedometer2"></i><span class="flex-fill">แดชบอร์ด / ตั๋วงาน</span>
        </a>
        <?php if ($isAdmin): ?>
        <a class="side-link" href="<?= Url::to('/admin/users') ?>">
          <i class="bi bi-people-fill"></i><span class="flex-fill">จัดการผู้ใช้และบทบาท</span>
        </a>
        <a class="side-link" href="<?= Url::to('/admin/audit') ?>">
          <i class="bi bi-clock-history"></i><span class="flex-fill">Audit Log</span>
        </a>
        <a class="side-link" href="<?= Url::to('/admin/settings') ?>">
          <i class="bi bi-gear"></i><span class="flex-fill">ตั้งค่าระบบ</span>
        </a>
        <?php endif; ?>
      </nav>

      <div class="mt-auto" style="background:rgba(37,99,235,.12);border:1px solid rgba(59,130,246,.3);border-radius:10px;padding:12px">
        <div style="color:#93c5fd;font-size:.72rem;font-weight:600;margin-bottom:4px"><i class="bi bi-diagram-3"></i> การสั่งงานข้ามขั้น</div>
        <div style="color:#94a3b8;font-size:.72rem;line-height:1.5"><?= (int) $crossCount ?> รายการ ถูกบันทึกเพื่อวิเคราะห์ภาระงานและทักษะการบริหาร</div>
      </div>
    </aside>

    <div class="main-col">
      <header class="app-header">
        <button type="button" class="btn btn-sm btn-outline-secondary sidebar-toggle" data-sidebar-open><i class="bi bi-list"></i></button>
        <div class="min-w-0">
          <div style="font-size:1.02rem;font-weight:600"><?= View::e($pageTitle ?? '') ?></div>
          <div class="text-body-secondary" style="font-size:.76rem">วิทยาลัยเทคนิค · ปีการศึกษา <?= (int) date('Y') + 543 ?> · ภาคเรียนที่ 1</div>
        </div>
        <div class="flex-fill"></div>
        <div class="btn-group btn-group-sm">
          <button type="button" class="btn btn-outline-secondary" data-theme-btn="light" title="Light Mode"><i class="bi bi-sun-fill"></i></button>
          <button type="button" class="btn btn-outline-secondary" data-theme-btn="dark" title="Dark Mode"><i class="bi bi-moon-stars-fill"></i></button>
          <button type="button" class="btn btn-outline-secondary" data-theme-btn="system" title="System Default"><i class="bi bi-circle-half"></i></button>
        </div>
        <div class="d-flex align-items-center gap-2" style="padding-left:12px;border-left:1px solid var(--bs-border-color)">
          <div class="text-end d-none d-sm-block" style="line-height:1.3">
            <div style="font-size:.83rem;font-weight:600"><?= View::e($user['full_name']) ?></div>
            <div style="font-size:.72rem" class="text-body-secondary"><?= View::e($roleInfo['label'] ?? '') ?></div>
          </div>
          <?php if (!empty($user['avatar_path'])): ?>
            <img src="<?= Url::to('/avatar/' . (int) $user['id']) ?>" style="width:38px;height:38px;border-radius:50%;object-fit:cover" alt="">
          <?php else: ?>
            <div style="width:38px;height:38px;border-radius:50%;background:#1e3a8a;color:#fff;display:grid;place-items:center;font-size:1rem"><i class="bi bi-<?= View::e($roleInfo['icon'] ?? 'person-fill') ?>"></i></div>
          <?php endif; ?>
          <form method="post" action="<?= Url::to('/logout') ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary" title="ออกจากระบบ"><i class="bi bi-box-arrow-right"></i></button>
          </form>
        </div>
      </header>

      <?php if (Auth::isImpersonating()): ?>
        <div class="impersonate-banner">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span class="flex-fill">โหมดสวมสิทธิ์ (Impersonation) — คุณกำลังใช้งานในฐานะ <strong><?= View::e($user['full_name']) ?></strong> โดยผู้ดูแลระบบ ทุกการกระทำจะถูกบันทึกในระบบตรวจสอบ</span>
          <button type="button" class="btn btn-sm btn-dark" data-post="/admin/impersonate-stop" data-reload-on-success="1"><i class="bi bi-box-arrow-left"></i> ออกจากการสวมสิทธิ์</button>
        </div>
      <?php endif; ?>

      <main class="p-3 p-md-4 d-flex flex-column gap-3">
        <?= $content ?>
      </main>
    </div>
  </div>
  <div id="overlay-root"></div>
<?php endif; ?>
<script src="<?= Url::asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= Url::asset('js/app.js') ?>"></script>
</body>
</html>
