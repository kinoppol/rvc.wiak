<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
/** @var string|null $error */
?>
<div style="min-height:100vh;display:grid;place-items:center;background:var(--bs-tertiary-bg,#f1f5f9);padding:20px">
  <div class="card border-0 shadow-lg" style="width:min(420px,100%);border-radius:16px">
    <div class="card-body p-4">
      <div class="d-flex align-items-center gap-2 mb-3">
        <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#2563eb,#0ea5e9);display:grid;place-items:center;color:#fff;font-size:1.2rem"><i class="bi bi-clipboard2-check-fill"></i></div>
        <div>
          <div style="font-weight:600">ระบบมอบหมายและติดตามงาน</div>
          <div class="text-body-secondary" style="font-size:.78rem">EduTask Tracking v1.0</div>
        </div>
      </div>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2" style="font-size:.85rem"><?= View::e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= Url::to('/login') ?>" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">ชื่อผู้ใช้</label>
          <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">รหัสผ่าน</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">เข้าสู่ระบบ</button>
      </form>
    </div>
  </div>
</div>
