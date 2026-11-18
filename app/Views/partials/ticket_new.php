<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Ticket;
/** @var array $personItems */
/** @var array $unitItems */
/** @var DateTimeImmutable $defaultDue */
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(560px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-plus-circle" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div style="font-weight:600" class="flex-fill">สร้างตั๋วงานใหม่</div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>
      <form data-ajax-form action="<?= Url::to('/tickets') ?>" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">หัวข้องาน</label>
          <input type="text" name="title" class="form-control form-control-sm" required>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">หน่วยงาน / หมายเหตุสั้น</label>
          <?= View::render('partials/combo', ['name' => 'meta', 'items' => $unitItems, 'placeholder' => 'พิมพ์ค้นหาชื่องาน / แผนก / ฝ่าย หรือพิมพ์เอง']) ?>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">ผู้รับมอบหมาย</label>
          <?= View::render('partials/combo', ['name' => '', 'valueName' => 'to_user_id', 'items' => $personItems, 'placeholder' => 'พิมพ์ชื่อ บทบาท หรือหน่วยงาน เพื่อค้นหา…', 'required' => true, 'contextInput' => 'meta']) ?>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label" style="font-size:.82rem">ความสำคัญ</label>
            <select name="priority" class="form-select form-select-sm">
              <?php foreach (Ticket::PRIORITY as $code => [$label]): ?>
                <option value="<?= $code ?>" <?= $code === 'normal' ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:.82rem">กำหนดส่ง</label>
            <input type="datetime-local" name="due_at" class="form-control form-control-sm" value="<?= $defaultDue->format('Y-m-d\TH:i') ?>">
            <div class="form-text" style="font-size:.7rem">ค่าเริ่มต้น: วันทำการถัดไป <?= $defaultDue->format('H:i') ?> น.</div>
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">รายละเอียดงาน</label>
          <textarea name="description" class="form-control form-control-sm" rows="4"></textarea>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> สร้างตั๋วงาน</button>
        </div>
      </form>
    </div>
  </div>
</div>
