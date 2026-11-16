<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Ticket;
/** @var array $t */
/** @var list<string> $unitNames */
$tid = (int) $t['id'];
$dueValue = $t['due_at'] ? date('Y-m-d\TH:i', strtotime($t['due_at'])) : '';
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(560px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-pencil-square" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div class="flex-fill">
          <div style="font-weight:600">แก้ไขตั๋วงาน</div>
          <div class="text-body-secondary" style="font-size:.75rem"><?= View::e($t['code']) ?> · มอบหมายให้ <?= View::e($t['to_name']) ?></div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>
      <?php if ($t['opened_at'] !== null): ?>
        <div class="alert alert-info py-2 px-3" style="font-size:.78rem">
          <i class="bi bi-info-circle"></i> ผู้รับมอบหมายเปิดอ่านงานนี้แล้ว — เมื่อบันทึก ระบบจะแจ้งให้ทราบว่ามีการเปลี่ยนแปลงอะไรบ้าง
        </div>
      <?php endif; ?>
      <form data-ajax-form action="<?= Url::to('/tickets/' . $tid . '/update') ?>" data-refresh-ticket="<?= $tid ?>" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">หัวข้องาน</label>
          <input type="text" name="title" class="form-control form-control-sm" value="<?= View::e($t['title']) ?>" required>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">หน่วยงาน / หมายเหตุสั้น</label>
          <input type="text" name="meta" class="form-control form-control-sm" list="edit-ticket-units" autocomplete="off" value="<?= View::e($t['meta'] ?? '') ?>" placeholder="พิมพ์เพื่อค้นหา เช่น งานประกันคุณภาพ · แผนกสามัญสัมพันธ์">
          <datalist id="edit-ticket-units">
            <?php foreach ($unitNames as $n): ?>
              <option value="<?= View::e($n) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label" style="font-size:.82rem">ความสำคัญ</label>
            <select name="priority" class="form-select form-select-sm">
              <?php foreach (Ticket::PRIORITY as $code => [$label]): ?>
                <option value="<?= $code ?>" <?= $code === $t['priority'] ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:.82rem">กำหนดส่ง</label>
            <input type="datetime-local" name="due_at" class="form-control form-control-sm" value="<?= $dueValue ?>">
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">รายละเอียดงาน</label>
          <textarea name="description" class="form-control form-control-sm" rows="5"><?= View::e($t['description'] ?? '') ?></textarea>
        </div>
        <div class="text-body-secondary" style="font-size:.73rem">
          <i class="bi bi-info-circle"></i> เปลี่ยนผู้รับผิดชอบหรือแนบไฟล์เพิ่มได้จากหน้ารายละเอียดตั๋วงาน
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-open-ticket="<?= $tid ?>">ยกเลิก</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> บันทึกการแก้ไข</button>
        </div>
      </form>
    </div>
  </div>
</div>
