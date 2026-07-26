<?php
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Url;
use App\Core\View;
/** @var string $baseUrl */
/** @var string|null $lastRunAt */
/** @var array|null $lastSummary */
/** @var bool $urlUpdated */
/** @var bool $synced */
/** @var string $syncError */
?>
<div class="d-flex flex-column gap-3" style="max-width:760px">

  <?php if ($urlUpdated): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> บันทึก URL ของระบบภายนอกแล้ว</div>
  <?php endif; ?>
  <?php if (Request::str('urlError') === '1'): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> URL ไม่ถูกต้อง กรุณาระบุในรูปแบบ http:// หรือ https://</div>
  <?php endif; ?>
  <?php if ($synced): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> ซิงค์ข้อมูลผู้ใช้เรียบร้อยแล้ว</div>
  <?php endif; ?>
  <?php if ($syncError): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> ซิงค์ข้อมูลไม่สำเร็จ: <?= View::e($syncError) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-hdd-network"></i> การเชื่อมต่อระบบภายนอก (RMS)</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">ที่อยู่เซิร์ฟเวอร์ต้นทางสำหรับโอนข้อมูลผู้ใช้ (เฉพาะโปรโตคอล+โดเมน) — เส้นทาง API และพารามิเตอร์ถูกกำหนดไว้ในโค้ดของระบบแล้ว ไม่ต้องระบุซ้ำที่นี่</div>

      <form method="post" action="<?= Url::to('/admin/settings/url') ?>" class="d-flex flex-column gap-2">
        <?= Csrf::field() ?>
        <label class="form-label" style="font-size:.82rem">Base URL</label>
        <div class="d-flex gap-2 flex-wrap">
          <input type="text" name="base_url" class="form-control form-control-sm" style="max-width:360px" value="<?= View::e($baseUrl) ?>" placeholder="http://rms.rvc.ac.th" required>
          <button type="submit" class="btn btn-sm btn-primary">บันทึก</button>
        </div>
        <div class="text-body-secondary" style="font-size:.72rem;font-family:ui-monospace,monospace">endpoint: <?= View::e($baseUrl) ?>/api_connection.php?app_name=nutty&amp;data=people</div>
      </form>
    </div>
  </div>

  <?php if ($notifySaved): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> บันทึกค่าเริ่มต้นการแจ้งเตือนแล้ว</div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-bell"></i> ค่าเริ่มต้นการแจ้งเตือนงานใกล้ถึงกำหนด</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        ค่าเริ่มต้นสำหรับผู้ใช้ทุกคน — ผู้ใช้แต่ละคนสามารถกำหนดจำนวนวันแจ้งเตือนล่วงหน้าของตนเองทับค่านี้ได้ที่เมนู "การแจ้งเตือนของฉัน"
      </div>
      <form method="post" action="<?= Url::to('/admin/settings/notify') ?>" class="d-flex gap-3 flex-wrap align-items-end">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">เตือนล่วงหน้า (วัน)</label>
          <input type="number" name="warn_days_default" min="1" max="60" class="form-control form-control-sm" style="max-width:120px" value="<?= (int) $warnDaysDefault ?>" required>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">แจ้งเตือนระดับด่วนที่สุด (ชั่วโมง)</label>
          <input type="number" name="urgent_hours" min="1" max="720" class="form-control form-control-sm" style="max-width:140px" value="<?= (int) $urgentHours ?>" required>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">บันทึก</button>
      </form>
      <div class="form-text mt-2">
        งานที่ถูกมอบหมายมาแบบกระชั้นชิดตั้งแต่ต้น (ระยะเวลาจากวันที่มอบหมายถึงกำหนดส่งสั้นกว่าเกณฑ์) จะไม่ถูกแจ้งเตือนในระดับนั้น
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-arrow-repeat"></i> โอนข้อมูลผู้ใช้จากระบบภายนอก</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        โอนเฉพาะรายชื่อที่มี <code>people_exit = 0</code> — ผู้ใช้ที่มีอยู่แล้วจะอัปเดตชื่อ/อีเมล/รหัสผ่านให้ตรงกับต้นทาง (วันที่สร้างบัญชีเดิมจะไม่ถูกแก้ไข) ผู้ใช้ใหม่จะได้รับบทบาทเริ่มต้นเป็น "เจ้าหน้าที่ในงาน (Staff)"
      </div>

      <?php if ($lastRunAt): ?>
        <div class="mb-3" style="font-size:.8rem;background:var(--bs-tertiary-bg);border-radius:9px;padding:10px 12px">
          <div>ซิงค์ล่าสุด: <strong><?= date('d/m/Y H:i:s', strtotime($lastRunAt)) ?></strong></div>
          <?php if ($lastSummary): ?>
            <div class="mt-1">ทั้งหมด <?= (int) $lastSummary['total'] ?> รายการ · สร้างใหม่ <strong class="text-success"><?= (int) $lastSummary['created'] ?></strong> · อัปเดต <strong class="text-primary"><?= (int) $lastSummary['updated'] ?></strong> · ข้าม <strong class="text-warning"><?= (int) $lastSummary['skipped'] ?></strong></div>
            <?php if (!empty($lastSummary['errors'])): ?>
              <details class="mt-2">
                <summary style="cursor:pointer;font-size:.76rem">รายละเอียดที่ถูกข้าม (<?= count($lastSummary['errors']) ?>)</summary>
                <ul class="mb-0 mt-1" style="font-size:.74rem">
                  <?php foreach ($lastSummary['errors'] as $err): ?>
                    <li><?= View::e($err) ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="text-body-secondary mb-3" style="font-size:.8rem">ยังไม่เคยซิงค์ข้อมูล</div>
      <?php endif; ?>

      <form method="post" action="<?= Url::to('/admin/settings/sync') ?>">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-cloud-download"></i> ซิงค์ข้อมูลผู้ใช้ตอนนี้</button>
      </form>
    </div>
  </div>
</div>
