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
/** @var bool $isApprover may record past use, for someone else, approved at once */
/** @var array $user the signed-in (effective) user */
/** @var array $personItems combo items for the room user (approvers only) */
/** @var bool $graphicsReady */
if (!isset($rooms[$roomId])) {
    $roomId = (int) (array_key_first($rooms) ?? 0);
}
$today = date('Y-m-d');
// Bookings run in whole hours, so the times are picked from a list.
$hourOptions = function (int $from, int $to, string $selected): string {
    $out = '';
    for ($h = $from; $h <= $to; $h++) {
        $v = sprintf('%02d:00', $h);
        $out .= '<option value="' . $v . '"' . ($v === $selected ? ' selected' : '') . '>' . $v . ' น.</option>';
    }
    return $out;
};
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

        <?php if ($isApprover): ?>
          <div>
            <label class="form-label" style="font-size:.82rem">ผู้ใช้ห้อง <span class="text-danger">*</span></label>
            <?= View::render('partials/combo', [
                'name' => '', 'valueName' => 'for_user_id', 'items' => $personItems, 'required' => true,
                'value' => $user['full_name'], 'hiddenValue' => (string) (int) $user['id'],
                'placeholder' => 'พิมพ์ชื่อ บทบาท หรือหน่วยงาน เพื่อค้นหา…',
            ]) ?>
            <div class="form-text" style="font-size:.72rem">
              ในฐานะผู้อนุมัติ คุณบันทึกการใช้ห้องแทนผู้อื่นหรือย้อนหลังได้ — รายการที่บันทึกจะอนุมัติทันที
            </div>
          </div>
        <?php endif; ?>

        <div>
          <label class="form-label" style="font-size:.82rem">วัตถุประสงค์ <span class="text-danger">*</span></label>
          <input type="text" name="purpose" class="form-control form-control-sm" maxlength="255" required
                 placeholder="เช่น ประชุมคณะกรรมการบริหารสถานศึกษา">
        </div>

        <div class="row g-2">
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">ตั้งแต่วันที่ <span class="text-danger">*</span></label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= View::e($date) ?>" <?= $isApprover ? '' : 'min="' . $today . '"' ?> required data-room-start-date>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">ถึงวันที่</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= View::e($date) ?>" min="<?= View::e($date) ?>" data-room-end-date>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">เวลาเริ่ม <span class="text-danger">*</span></label>
            <select name="start_time" class="form-select form-select-sm" required data-room-start-time>
              <?= $hourOptions(RoomBooking::FIRST_HOUR, RoomBooking::LAST_HOUR - 1, $start) ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:.82rem">เวลาสิ้นสุด <span class="text-danger">*</span></label>
            <select name="end_time" class="form-select form-select-sm" required data-room-end-time>
              <?= $hourOptions(RoomBooking::FIRST_HOUR + 1, RoomBooking::LAST_HOUR, $end) ?>
            </select>
          </div>
          <div class="col-12 form-text" style="font-size:.72rem;margin-top:2px">
            จองเป็นชั่วโมงเต็ม · ขอใช้หลายวันได้ไม่เกิน <?= RoomBooking::MAX_DAYS ?> วัน — ใช้ช่วงเวลาเดียวกันทุกวัน
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

        <?php if ($graphicsReady): ?>
          <div class="border rounded-3" style="padding:10px 12px">
            <label class="d-flex align-items-center gap-2 m-0" style="font-size:.84rem;cursor:pointer;font-weight:500">
              <input type="checkbox" class="form-check-input m-0" name="graphic" value="1" data-room-graphic-toggle>
              <i class="bi bi-easel"></i> ขอให้งานสื่อทำภาพกราฟิกป้ายโครงการ
            </label>
            <div data-room-graphic hidden class="d-flex flex-column gap-2 mt-2">
              <div>
                <label class="form-label" style="font-size:.82rem">รายละเอียดป้าย <span class="text-danger">*</span></label>
                <textarea name="graphic_request" class="form-control form-control-sm" rows="3" maxlength="2000" disabled
                          placeholder="เช่น ชื่อโครงการ วันที่ สถานที่ ผู้จัด ขนาดป้าย ข้อความ/โลโก้ที่ต้องการ"></textarea>
              </div>
              <div>
                <label class="form-label" style="font-size:.82rem">ไฟล์ประกอบ <span class="text-body-secondary" style="font-weight:400">(แนบได้หลายไฟล์ ไม่เกิน <?= RoomBooking::MAX_GRAPHIC_FILES ?> ไฟล์ ไฟล์ละไม่เกิน <?= View::e(\App\Core\Upload::humanSize(\App\Core\Upload::maxBytes())) ?>)</span></label>
                <div data-rows="graphic-file" class="d-flex flex-column gap-2">
                  <div data-row>
                    <input type="file" name="graphic_files[]" multiple class="form-control form-control-sm" disabled>
                  </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-add-row="graphic-file"><i class="bi bi-plus-lg"></i> เพิ่มไฟล์</button>
              </div>
            </div>
          </div>
        <?php endif; ?>

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
          <?php if ($isApprover): ?>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-circle"></i> บันทึกการใช้ห้อง</button>
          <?php else: ?>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> ส่งคำขอใช้ห้อง</button>
          <?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
