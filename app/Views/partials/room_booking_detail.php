<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\RoomBooking;
/** @var array $b booking */
/** @var bool $isOwner */
/** @var bool $isApprover */
/** @var array $clashes other pending/approved bookings overlapping this one */
/** @var array|null $room */
/** @var bool $canSeeGraphic requester/room user or approver */
/** @var array $files sign-graphic reference files */

[$stLabel, $stBg, $stFg, $stIcon] = RoomBooking::STATUS[$b['status']];
$active = in_array($b['status'], ['pending', 'approved'], true);
$notPast = $b['end_date'] >= date('Y-m-d');
$canDecide = $isApprover && $b['status'] === 'pending';
$canCancelOwn = $isOwner && $active && $notPast;
$canRevoke = $isApprover && !$isOwner && $b['status'] === 'approved' && $notPast;
$days = RoomBooking::dayCount($b);
$row = fn(string $icon, string $label, string $value) =>
    '<div class="d-flex gap-2" style="font-size:.84rem"><i class="bi bi-' . $icon . ' text-body-secondary" style="width:18px"></i>'
    . '<div class="text-body-secondary" style="width:118px;flex-shrink:0">' . $label . '</div><div class="flex-fill">' . $value . '</div></div>';
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(600px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-start gap-2 mb-3">
        <span style="width:12px;height:12px;border-radius:50%;background:<?= View::e($b['room_color']) ?>;margin-top:6px;flex-shrink:0"></span>
        <div class="flex-fill">
          <div style="font-weight:600"><?= View::e($b['purpose']) ?></div>
          <div class="text-body-secondary" style="font-size:.78rem"><?= View::e($b['room_name']) ?> · คำขอ #<?= (int) $b['id'] ?></div>
        </div>
        <span class="badge rounded-pill" style="background:<?= $stBg ?>;color:<?= $stFg ?>;font-weight:500;font-size:.72rem"><i class="bi bi-<?= $stIcon ?>"></i> <?= $stLabel ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>

      <div class="d-flex flex-column gap-2">
        <?= $row('door-open', 'ห้อง', View::e($b['room_name']) . ($b['room_location'] ? ' <span class="text-body-secondary">· ' . View::e($b['room_location']) . '</span>' : '')) ?>
        <?= $row('calendar-event', 'วันที่', View::e($b['start_date'] === $b['end_date']
              ? RoomBooking::thaiDate($b['start_date'], true)
              : RoomBooking::thaiDate($b['start_date'], true) . ' – ' . RoomBooking::thaiDate($b['end_date'], true) . ' (' . $days . ' วัน)')) ?>
        <?= $row('clock', 'เวลา', View::e(RoomBooking::timeLabel($b)) . ($days > 1 ? ' <span class="text-body-secondary">ทุกวัน</span>' : '')) ?>
        <?php if ($b['attendees']): ?>
          <?= $row('people', 'ผู้เข้าร่วม', (int) $b['attendees'] . ' คน' . ($room && $room['capacity'] ? ' <span class="text-body-secondary">(ห้องจุ ' . (int) $room['capacity'] . ' คน)</span>' : '')) ?>
        <?php endif; ?>
        <?php
          $eq = $b['equipment']
              ? implode(' ', array_map(fn($e) => '<span class="badge rounded-pill text-bg-light border" style="font-weight:500;font-size:.72rem"><i class="bi bi-' . View::e($e['icon']) . '"></i> ' . View::e($e['name']) . '</span>', $b['equipment']))
              : '<span class="text-body-secondary">ไม่ต้องการอุปกรณ์สื่อ</span>';
        ?>
        <?= $row('speaker', 'อุปกรณ์ที่ขอใช้', $eq) ?>
        <?php if ($b['details']): ?>
          <?= $row('card-text', 'รายละเอียด', nl2br(View::e($b['details']))) ?>
        <?php endif; ?>
        <?= $row('person', 'ผู้ขอใช้', View::e($b['user_name']) . ' <span class="text-body-secondary" style="font-size:.76rem">· ส่งคำขอ ' . View::e(RoomBooking::thaiDate($b['created_at']) . ' ' . substr($b['created_at'], 11, 5)) . ' น.</span>') ?>
        <?php if ($b['decided_at']): ?>
          <?php $verb = ['approved' => 'อนุมัติโดย', 'rejected' => 'ไม่อนุมัติโดย', 'cancelled' => 'ยกเลิกโดย'][$b['status']] ?? 'ดำเนินการโดย'; ?>
          <?= $row('person-check', $verb, View::e($b['decider_name'] ?? '-') . ' <span class="text-body-secondary" style="font-size:.76rem">· ' . View::e(RoomBooking::thaiDate($b['decided_at']) . ' ' . substr($b['decided_at'], 11, 5)) . ' น.</span>') ?>
        <?php endif; ?>
        <?php if ($b['decision_note']): ?>
          <?= $row('chat-left-text', 'หมายเหตุ', nl2br(View::e($b['decision_note']))) ?>
        <?php endif; ?>
      </div>

      <?php if (($b['graphic_request'] ?? null) !== null): ?>
        <div class="mt-3 p-3 border rounded-3" style="font-size:.84rem">
          <div style="font-weight:600" class="mb-1"><i class="bi bi-easel"></i> ขอให้ทำภาพกราฟิกป้ายโครงการ</div>
          <?php if ($canSeeGraphic): ?>
            <div style="white-space:pre-wrap"><?= View::e($b['graphic_request']) ?></div>
            <?php if ($files): ?>
              <div class="d-flex flex-column gap-1 mt-2">
                <?php foreach ($files as $f): ?>
                  <?php
                    [$fIcon, $fColor] = \App\Core\Upload::iconFor((string) $f['mime'], $f['name']);
                    $fUrl = Url::to('/rooms/bookings/' . (int) $b['id'] . '/files/' . (int) $f['id']);
                    $preview = in_array((string) $f['mime'], \App\Models\TicketFile::PREVIEW_MIMES, true);
                  ?>
                  <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-<?= $fIcon ?>" style="color:<?= $fColor ?>"></i>
                    <a href="<?= $fUrl ?><?= $preview ? '?inline=1' : '' ?>" <?= $preview ? 'target="_blank" rel="noopener"' : '' ?> class="text-truncate"><?= View::e($f['name']) ?></a>
                    <span class="text-body-secondary" style="font-size:.74rem"><?= View::e(\App\Core\Upload::humanSize((int) $f['size'])) ?></span>
                    <a href="<?= $fUrl ?>" class="ms-auto text-body-secondary" title="ดาวน์โหลด"><i class="bi bi-download"></i></a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-body-secondary mt-1" style="font-size:.78rem">ไม่มีไฟล์แนบ</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-body-secondary" style="font-size:.78rem">รายละเอียดดูได้เฉพาะผู้ขอและผู้อนุมัติ</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($clashes && $active): ?>
        <div class="alert alert-warning mt-3 mb-0" style="font-size:.8rem;padding:8px 12px">
          <div style="font-weight:600"><i class="bi bi-exclamation-triangle"></i> ช่วงเวลาทับกับการจองอื่นในห้องเดียวกัน</div>
          <?php foreach ($clashes as $c): ?>
            <div class="mt-1">
              <a href="#" data-open-url="/rooms/bookings/<?= (int) $c['id'] ?>"><?= View::e($c['purpose']) ?></a>
              — <?= View::e(RoomBooking::rangeLabel($c)) ?> · <?= View::e($c['user_name']) ?>
              <span class="badge rounded-pill" style="background:<?= RoomBooking::STATUS[$c['status']][1] ?>;color:<?= RoomBooking::STATUS[$c['status']][2] ?>;font-weight:500"><?= RoomBooking::STATUS[$c['status']][0] ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($canDecide): ?>
        <form data-ajax-form action="<?= Url::to('/rooms/bookings/' . (int) $b['id'] . '/approve') ?>" data-reload-on-success="1" class="mt-3 p-3 border rounded-3">
          <?= Csrf::field() ?>
          <div style="font-weight:600;font-size:.85rem" class="mb-2"><i class="bi bi-clipboard-check"></i> พิจารณาคำขอ</div>
          <textarea name="note" class="form-control form-control-sm mb-2" rows="2" maxlength="500" placeholder="หมายเหตุถึงผู้ขอ (จำเป็นเมื่อไม่อนุมัติ)"></textarea>
          <div class="d-flex justify-content-end gap-2">
            <button type="submit" class="btn btn-sm btn-outline-danger" formaction="<?= Url::to('/rooms/bookings/' . (int) $b['id'] . '/reject') ?>" data-confirm="ยืนยันไม่อนุมัติคำขอนี้?"><i class="bi bi-x-circle"></i> ไม่อนุมัติ</button>
            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> อนุมัติ</button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($canRevoke): ?>
        <form data-ajax-form action="<?= Url::to('/rooms/bookings/' . (int) $b['id'] . '/cancel') ?>" data-reload-on-success="1" class="mt-3 p-3 border rounded-3">
          <?= Csrf::field() ?>
          <div style="font-weight:600;font-size:.85rem" class="mb-2"><i class="bi bi-slash-circle"></i> ยกเลิกการจองที่อนุมัติแล้ว</div>
          <input type="text" name="note" class="form-control form-control-sm mb-2" maxlength="500" required placeholder="เหตุผลที่ยกเลิก (แจ้งผู้ขอ)">
          <div class="text-end"><button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="ยืนยันยกเลิกการจองนี้?">ยกเลิกการจอง</button></div>
        </form>
      <?php endif; ?>

      <div class="d-flex justify-content-end gap-2 mt-3">
        <?php if ($canCancelOwn): ?>
          <button type="button" class="btn btn-sm btn-outline-danger" data-post="/rooms/bookings/<?= (int) $b['id'] ?>/cancel"
                  data-confirm="ยืนยันยกเลิกคำขอใช้ห้องนี้?" data-reload-on-success="1"><i class="bi bi-trash"></i> ยกเลิกคำขอ</button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ปิด</button>
      </div>
    </div>
  </div>
</div>
