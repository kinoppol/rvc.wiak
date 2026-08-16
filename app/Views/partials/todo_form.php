<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Todo;
/** @var array|null $todo   null = create mode, array = edit mode */
/** @var array      $logs   recent log entries (edit mode only) */

$isEdit   = $todo !== null;
$logs     = $logs ?? [];
$rt       = $isEdit ? $todo['recur_type'] : 'none';
$wdays    = $isEdit ? (int) $todo['recur_weekly_days'] : 0;
$action   = $isEdit ? Url::to('/todos/' . (int) $todo['id']) : Url::to('/todos');

// Helper: show section only when recur type matches
$showIf   = fn(string ...$types): string => in_array($rt, $types, true) ? '' : 'display:none';
$showIfNot = fn(string ...$types): string => !in_array($rt, $types, true) ? '' : 'display:none';

// Format due_at for datetime-local input (value="YYYY-MM-DDTHH:MM")
$dueVal = '';
if ($isEdit && $todo['due_at']) {
    $dueVal = (new DateTimeImmutable($todo['due_at']))->format('Y-m-d\TH:i');
}
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(560px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">

      <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-circle' ?>" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div style="font-weight:600" class="flex-fill"><?= $isEdit ? 'แก้ไขรายการ' : 'สร้างรายการงานส่วนตัว' ?></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>

      <form data-ajax-form action="<?= $action ?>" data-reload-on-success="1" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>

        <!-- Title -->
        <div>
          <label class="form-label" style="font-size:.82rem">ชื่องาน <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control form-control-sm"
                 value="<?= View::e($todo['title'] ?? '') ?>" required autofocus>
        </div>

        <!-- Note -->
        <div>
          <label class="form-label" style="font-size:.82rem">หมายเหตุ</label>
          <textarea name="note" class="form-control form-control-sm" rows="2"><?= View::e($todo['note'] ?? '') ?></textarea>
        </div>

        <!-- Due date/time -->
        <div>
          <label class="form-label" style="font-size:.82rem">วันเวลาที่กำหนด</label>
          <input type="datetime-local" name="due_at" class="form-control form-control-sm"
                 value="<?= $dueVal ?>">
          <div class="form-text" style="font-size:.73rem">เว้นว่างถ้าไม่มีกำหนดเวลา</div>
        </div>

        <!-- Recurrence type -->
        <div>
          <label class="form-label" style="font-size:.82rem">การทำซ้ำ</label>
          <select name="recur_type" id="todo-recur-type" class="form-select form-select-sm">
            <?php foreach (Todo::RECUR_TYPES as $val => $lbl): ?>
              <option value="<?= $val ?>" <?= $rt === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Weekly: day checkboxes -->
        <div id="todo-recur-weekly" style="<?= $showIf('weekly') ?>">
          <label class="form-label" style="font-size:.82rem">วันในสัปดาห์</label>
          <div class="d-flex gap-2 flex-wrap">
            <?php foreach (Todo::WEEK_DAYS as $d => $dn): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="recur_weekly_days[]"
                       value="<?= $d ?>" id="rwd-<?= $d ?>"
                       <?= ($wdays & (1 << $d)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="rwd-<?= $d ?>" style="font-size:.82rem"><?= $dn ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Monthly date: day of month -->
        <div id="todo-recur-monthly_date" style="<?= $showIf('monthly_date') ?>">
          <label class="form-label" style="font-size:.82rem">วันที่ของเดือน</label>
          <input type="number" name="recur_day" class="form-control form-control-sm"
                 min="1" max="31" value="<?= (int) ($todo['recur_day'] ?? 1) ?: 1 ?>" style="width:90px">
          <div class="form-text" style="font-size:.73rem">ถ้าเดือนนั้นไม่มีวันดังกล่าว ระบบจะใช้วันสุดท้ายของเดือน</div>
        </div>

        <!-- Yearly: informational -->
        <div id="todo-recur-yearly" style="<?= $showIf('yearly') ?>">
          <div class="alert alert-info py-2" style="font-size:.78rem">ซ้ำทุกปีในวันเดียวกับวันที่กำหนด</div>
        </div>

        <!-- Interval: N days -->
        <div id="todo-recur-interval" style="<?= $showIf('interval') ?>">
          <label class="form-label" style="font-size:.82rem">ซ้ำทุก ๆ กี่วัน</label>
          <div class="input-group input-group-sm" style="width:140px">
            <input type="number" name="recur_interval" class="form-control"
                   min="1" max="365" value="<?= (int) ($todo['recur_interval'] ?? 7) ?: 7 ?>">
            <span class="input-group-text">วัน</span>
          </div>
        </div>

        <!-- Recurrence end date -->
        <div id="todo-recur-end" style="<?= $showIfNot('none') ?>">
          <label class="form-label" style="font-size:.82rem">สิ้นสุดการทำซ้ำ <span class="text-body-secondary">(ไม่บังคับ)</span></label>
          <input type="date" name="recur_end_at" class="form-control form-control-sm"
                 value="<?= View::e($todo['recur_end_at'] ?? '') ?>">
        </div>

        <!-- Overdue action -->
        <div>
          <label class="form-label" style="font-size:.82rem">เมื่อพลาดกำหนด</label>
          <div class="d-flex flex-column gap-1">
            <?php $oa = $todo['overdue_action'] ?? 'alert'; ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="overdue_action" value="alert" id="oa-alert"
                     <?= $oa === 'alert' ? 'checked' : '' ?>>
              <label class="form-check-label" for="oa-alert" style="font-size:.82rem">
                <i class="bi bi-bell-fill text-warning"></i> แจ้งเตือนต่อเนื่องจนกว่าจะจัดการ
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="overdue_action" value="miss" id="oa-miss"
                     <?= $oa === 'miss' ? 'checked' : '' ?>>
              <label class="form-check-label" for="oa-miss" style="font-size:.82rem">
                <i class="bi bi-x-circle text-secondary"></i> บันทึกเป็นรายการที่พลาดโดยอัตโนมัติ
              </label>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 pt-1">
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-floppy"></i> <?= $isEdit ? 'บันทึกการแก้ไข' : 'สร้างรายการ' ?>
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button>
        </div>
      </form>

      <?php if ($isEdit && $logs): ?>
      <hr style="margin:18px 0 12px">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:8px">ประวัติย้อนหลัง</div>
      <div class="d-flex flex-column gap-1">
        <?php foreach ($logs as $log): ?>
          <?php $isDone = $log['status'] === 'done'; ?>
          <div class="d-flex align-items-center gap-2" style="font-size:.75rem;color:var(--bs-secondary)">
            <i class="bi bi-<?= $isDone ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?>"></i>
            <span><?= $isDone ? 'เสร็จ' : 'พลาด' ?></span>
            <?php if ($log['due_at']): ?>
              <span class="text-body-secondary">กำหนด <?= date('d/m/Y H:i', strtotime($log['due_at'])) ?></span>
            <?php endif; ?>
            <span class="ms-auto"><?= date('d/m/Y H:i', strtotime($log['acted_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
