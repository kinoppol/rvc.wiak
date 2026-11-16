<?php
use App\Core\Csrf;
use App\Core\Upload;
use App\Core\Url;
use App\Core\View;
use App\Models\Ticket;
/** @var array $people */
/** @var list<string> $unitNames */
$unitNames = $unitNames ?? [];
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(560px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-plus-circle" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div style="font-weight:600" class="flex-fill">สร้างตั๋วงานใหม่</div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>
      <form data-ajax-form action="<?= Url::to('/tickets') ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">หัวข้องาน</label>
          <input type="text" name="title" class="form-control form-control-sm" required>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">หน่วยงาน / หมายเหตุสั้น</label>
          <input type="text" name="meta" class="form-control form-control-sm" list="new-ticket-units" autocomplete="off" placeholder="พิมพ์เพื่อค้นหา เช่น งานประกันคุณภาพ · แผนกสามัญสัมพันธ์">
          <datalist id="new-ticket-units">
            <?php foreach ($unitNames as $n): ?>
              <option value="<?= View::e($n) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">ผู้รับมอบหมาย</label>
          <div class="input-group input-group-sm mb-1">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" placeholder="พิมพ์ชื่อเพื่อกรองรายชื่อ…" data-filter-select="#new-ticket-assignee" autocomplete="off">
          </div>
          <select id="new-ticket-assignee" name="to_user_id" class="form-select form-select-sm" required>
            <option value="">เลือกผู้รับมอบหมาย…</option>
            <?php foreach ($people as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= View::e($p['full_name']) ?><?= $p['roles'] ? ' (' . View::e(implode(', ', $p['roles'])) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
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
            <input type="datetime-local" name="due_at" class="form-control form-control-sm">
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">รายละเอียดงาน</label>
          <textarea name="description" class="form-control form-control-sm" rows="4"></textarea>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">ไฟล์แนบ (เลือกได้หลายไฟล์)</label>
          <input type="file" name="files[]" multiple class="form-control form-control-sm" data-paste-file data-multi-file data-max-files="<?= (int) (ini_get('max_file_uploads') ?: 20) ?>" data-max-bytes="<?= Upload::maxBytes() ?>">
          <div class="text-body-secondary mt-1" style="font-size:.73rem">
            สูงสุดไฟล์ละ <?= View::e(Upload::humanSize(Upload::maxBytes())) ?> ·
            <i class="bi bi-clipboard"></i> กด <kbd>Ctrl</kbd>+<kbd>V</kbd> เพื่อวางรูปภาพหรือไฟล์จากคลิปบอร์ดได้
          </div>
          <div data-file-list class="d-flex flex-column gap-1 mt-1" style="font-size:.78rem"></div>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> สร้างตั๋วงาน</button>
        </div>
      </form>
    </div>
  </div>
</div>
