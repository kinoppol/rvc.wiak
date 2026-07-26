<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
/** @var int|null $ownWarnDays */
/** @var int $defaultWarnDays */
/** @var int $urgentHours */
/** @var bool $saved */
?>
<div style="max-width:620px">
  <?php if ($saved): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> บันทึกการตั้งค่าแล้ว</div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:2px"><i class="bi bi-bell"></i> การแจ้งเตือนงานใกล้ถึงกำหนดส่ง</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        ระบบจะแจ้งเตือนงานที่ยังไม่แล้วเสร็จซึ่งใกล้ถึงกำหนดส่ง ทั้งงานที่คุณได้รับมอบหมายและงานที่คุณมอบหมายให้ผู้อื่น
      </div>

      <form method="post" action="<?= Url::to('/preferences') ?>" class="d-flex flex-column gap-3">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">แจ้งเตือนล่วงหน้า (วัน)</label>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="number" name="warn_days" min="1" max="60" class="form-control form-control-sm" style="max-width:120px"
                   value="<?= $ownWarnDays !== null ? (int) $ownWarnDays : '' ?>" placeholder="<?= (int) $defaultWarnDays ?>">
            <button type="submit" class="btn btn-sm btn-primary">บันทึก</button>
          </div>
          <div class="form-text">
            เว้นว่างไว้เพื่อใช้ค่าเริ่มต้นที่ผู้ดูแลระบบกำหนด (ปัจจุบัน <strong><?= (int) $defaultWarnDays ?> วัน</strong>)
          </div>
        </div>
      </form>

      <hr>

      <div style="font-size:.82rem;font-weight:600;margin-bottom:6px">เกณฑ์การแจ้งเตือน</div>
      <ul class="text-body-secondary mb-0" style="font-size:.8rem;line-height:1.8">
        <li>
          <span class="badge rounded-pill" style="background:rgba(217,119,6,.15);color:#b45309;font-weight:500">เตือนล่วงหน้า</span>
          งานที่ยังไม่แล้วเสร็จและถึงกำหนดส่งภายใน <strong><?= $ownWarnDays !== null ? (int) $ownWarnDays : (int) $defaultWarnDays ?> วัน</strong>
        </li>
        <li>
          <span class="badge rounded-pill" style="background:rgba(220,38,38,.15);color:#b91c1c;font-weight:500">ด่วนที่สุด</span>
          งานที่ยังไม่แล้วเสร็จและถึงกำหนดส่งภายใน <strong><?= (int) $urgentHours ?> ชั่วโมง</strong> (รวมงานที่เกินกำหนดแล้ว)
        </li>
        <li>
          ระบบจะ<strong>ไม่แจ้งเตือน</strong>งานที่ถูกมอบหมายมาแบบกระชั้นชิดอยู่แล้ว คือมีระยะเวลาตั้งแต่วันที่มอบหมายถึงกำหนดส่งสั้นกว่าเกณฑ์ข้างต้น
          เพราะงานลักษณะนั้นเร่งด่วนโดยตัวมันเองตั้งแต่ต้น การเตือนซ้ำจึงไม่ช่วยอะไร
        </li>
      </ul>
    </div>
  </div>
</div>
