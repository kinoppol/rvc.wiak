<?php
use App\Core\View;
/** @var array $people */
?>
<div class="modal-overlay" data-close-overlay>
  <div class="card border-0 shadow-lg" style="width:min(520px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-person-badge" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div style="font-weight:600">สวมสิทธิ์ผู้ใช้ (Impersonate User)</div>
      </div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">เฉพาะผู้ดูแลระบบ · ระบบจะบันทึก Audit Log ทุกการกระทำ</div>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($people as $p): ?>
          <div class="d-flex align-items-center gap-2" style="border:1px solid var(--bs-border-color);border-radius:10px;padding:10px 12px">
            <div style="width:34px;height:34px;border-radius:50%;background:rgba(30,58,138,.12);color:#1e3a8a;display:grid;place-items:center"><i class="bi bi-<?= View::e($p['icon']) ?>"></i></div>
            <div class="min-w-0 flex-fill">
              <div style="font-size:.85rem;font-weight:500"><?= View::e($p['full_name']) ?></div>
              <div class="text-body-secondary" style="font-size:.73rem"><?= View::e(implode(', ', $p['roles'])) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-primary" data-impersonate-user="<?= (int) $p['id'] ?>">สวมสิทธิ์</button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
