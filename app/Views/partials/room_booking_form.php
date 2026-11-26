<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\RoomBooking;
/** @var array<int,array> $rooms active rooms with equipment */
/** @var int $roomId */
/** @var string $date */
/** @var string $start */
/** @var string $end */
/** @var array $approvers */
if (!isset($rooms[$roomId])) {
    $roomId = (int) (array_key_first($rooms) ?? 0);
}
$today = date('Y-m-d');
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(620px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-door-open" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div style="font-weight:600" class="flex-fill">ขอใช้ห้องประชุม</div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>

      <?php if (!$rooms): ?>
        <div class="alert alert-warning" style="font-size:.85rem">ยังไม่มีห้องประชุมที่เปิดให้จอง</div>
        <div class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ปิด</button></div>
      <?php else: ?>
      <form data-ajax-form data-room-form action="<?= Url::to('/rooms/bookings') ?>" data-reload-on-success="1" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>

        <div>
          <label class="form-label" style="font-size:.82rem">ห้องประชุม <span class="text-danger">*</span></label>
          <select name="room_id" class="form-select form-select-sm" data-room-select required>
            <?php foreach ($rooms as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $roomId ? 'selected' : '' ?>
                      data-capacity="<?= (int) $r['capacity'] ?>">
                <?= View::e($r['name']) ?><?= $r['capacity'] ? ' (' . (int) $r['capacity'] . ' คน)' : '' ?><?= $r['location'] ? ' · ' . View::e($r['location']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="form-label" style="font-size:.82rem">วัตถุประสงค์ <span class="text-danger">*</span></label>
          <input type="text" name="purpose" class="form-control form-control-sm" maxlength="255" required
                 placeholder="เช่น ประชุมคณะกรรมการบริหารสถานศึกษา">
        </div>

        <div class="row g-2">
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">ตั้งแต่วันที่ <span class="text-danger">*</span></label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= View::e($date) ?>" min="<?= $today ?>" required data-room-start-date>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">ถึงวันที่</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= View::e($date) ?>" min="<?= View::e($date) ?>" data-room-end-date>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">เวลาเริ่ม <span class="text-danger">*</span></label>
            <input type="time" name="start_time" class="form-control form-control-sm" value="<?= View::e($start) ?>" step="900" required>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">เวลาสิ้นสุด <span class="text-danger">*</span></label>
            <input type="time" name="end_time" class="form-control form-control-sm" value="<?= View::e($end) ?>" step="900" required>
          </div>
          <div class="col-12 form-text" style="font-size:.72rem;margin-top:2px">
            ขอใช้หลายวันได้ไม่เกิน <?= RoomBooking::MAX_DAYS ?> วัน — ใช้ช่วงเวลาเดียวกันทุกวัน
          </div>
        </div>

        <div>
          <label class="form-label" style="font-size:.82rem">อุปกรณ์สื่อที่ต้องการใช้</label>
          <?php foreach ($rooms as $r): ?>
            <div data-room-equip="<?= (int) $r['id'] ?>" class="d-flex flex-wrap gap-2" <?= (int) $r['id'] === $roomId ? '' : 'hidden' ?>>
              <?php foreach ($r['equipment'] as $e): ?>
                <label class="d-inline-flex align-items-center gap-1 border rounded-pill" style="padding:4px 11px;font-size:.8rem;cursor:pointer">
                  <input type="checkbox" class="form-check-input m-0" name="equipment[]" value="<?= (int) $e['id'] ?>" <?= (int) $r['id'] === $roomId ? '' : 'disabled' ?>>
                  <i class="bi bi-<?= View::e($e['icon']) ?>"></i> <?= View::e($e['name']) ?>
                </label>
              <?php endforeach; ?>
              <?php if (!$r['equipment']): ?>
                <span class="text-body-secondary" style="font-size:.78rem">ห้องนี้ไม่มีอุปกรณ์สื่อให้บริการ</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label" style="font-size:.82rem">จำนวนผู้เข้าร่วม</label>
            <input type="number" name="attendees" class="form-control form-control-sm" min="1" max="65535" placeholder="คน">
          </div>
          <div class="col-md-8">
            <label class="form-label" style="font-size:.82rem">รายละเอียดเพิ่มเติม</label>
            <textarea name="details" class="form-control form-control-sm" rows="2" placeholder="เช่น การจัดโต๊ะ, ต้องการเจ้าหน้าที่ควบคุมเสียง"></textarea>
          </div>
        </div>

        <div class="alert alert-info mb-0 d-flex gap-2" style="font-size:.78rem;padding:8px 12px">
          <i class="bi bi-info-circle"></i>
          <div>
            การจองจะมีผลเมื่อได้รับการอนุมัติจากหัวหน้างาน/เจ้าหน้าที่งานสื่อ
            <?php if ($approvers): ?>
              <div class="text-body-secondary" style="font-size:.74rem">ผู้อนุมัติ: <?= View::e(implode(', ', array_column($approvers, 'full_name'))) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> ส่งคำขอใช้ห้อง</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
