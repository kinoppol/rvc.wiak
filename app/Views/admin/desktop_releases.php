<?php
use App\Core\Url;
use App\Core\View;
/** @var bool $ready */
/** @var array<int,array> $releases */
/** @var int $chunkSize */
/** @var int $maxBytes */
?>
<div style="max-width:900px" class="d-flex flex-column gap-3">
  <?php if (!$ready): ?>
    <div class="alert alert-warning mb-0">กรุณารัน migration <code>20261124_add_desktop_notifications.sql</code> ที่หน้า <a href="<?= Url::to('/admin/migrations') ?>">Migration ฐานข้อมูล</a> ก่อน</div>
  <?php else: ?>
    <div class="card border-0 shadow-sm" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem;margin-bottom:10px"><i class="bi bi-upload"></i> อัปโหลดไฟล์ติดตั้ง</div>
        <form method="post" enctype="multipart/form-data" action="<?= Url::to('/admin/desktop-releases/upload') ?>" data-release-upload data-chunk-size="<?= (int) $chunkSize ?>" data-max-bytes="<?= (int) $maxBytes ?>" class="row g-2">
          <div class="col-sm-4">
            <label class="form-label" style="font-size:.82rem">เลขรุ่น</label>
            <input type="text" name="version" class="form-control form-control-sm" placeholder="1.0.0" required pattern="\d{1,5}\.\d{1,5}\.\d{1,5}(-[0-9A-Za-z.]{1,16})?">
          </div>
          <div class="col-sm-8">
            <label class="form-label" style="font-size:.82rem">ไฟล์ติดตั้ง (.exe หรือ .msi ไม่เกิน <?= (int) ($maxBytes / 1048576) ?> MB)</label>
            <input type="file" name="file" class="form-control form-control-sm" accept=".exe,.msi" required>
          </div>
          <div class="col-12">
            <label class="form-label" style="font-size:.82rem">รายละเอียดรุ่น (ไม่บังคับ)</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
          </div>
          <div class="col-12 form-check ms-1">
            <input class="form-check-input" type="checkbox" name="make_current" value="1" id="release-current" checked>
            <label class="form-check-label" for="release-current" style="font-size:.82rem">ตั้งเป็นรุ่นปัจจุบันที่ผู้ใช้ดาวน์โหลด</label>
          </div>
          <div class="col-12 d-none" data-release-progress>
            <div class="progress" role="progressbar" aria-label="ความคืบหน้าการอัปโหลด" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:0%">0%</div></div>
          </div>
          <div class="col-12"><button type="submit" class="btn btn-sm btn-primary">อัปโหลด</button></div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem;margin-bottom:8px">รุ่นทั้งหมด</div>
        <?php if (!$releases): ?>
          <div class="text-body-secondary" style="font-size:.84rem">ยังไม่มีไฟล์ติดตั้ง</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
              <thead><tr><th>รุ่น</th><th>ขนาด</th><th>อัปโหลด</th><th class="text-end">จัดการ</th></tr></thead>
              <tbody>
              <?php foreach ($releases as $r): $isCurrent = (int) $r['is_current'] === 1; ?>
                <tr>
                  <td>
                    <strong><?= View::e($r['version']) ?></strong>
                    <?php if ($isCurrent): ?><span class="badge text-bg-success">ปัจจุบัน</span><?php endif; ?>
                    <span class="text-body-secondary">.<?= View::e($r['extension']) ?></span>
                  </td>
                  <td><?= number_format((int) $r['size_bytes'] / 1048576, 1) ?> MB</td>
                  <td><?= View::e(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?><div class="text-body-secondary"><?= View::e($r['uploaded_by_name'] ?? '—') ?></div></td>
                  <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= Url::to('/desktop/download/' . (int) $r['id']) ?>" aria-label="ดาวน์โหลดรุ่น <?= View::e($r['version']) ?>"><i class="bi bi-download"></i></a>
                    <?php if (!$isCurrent): ?>
                      <button type="button" class="btn btn-sm btn-outline-primary" data-post="/admin/desktop-releases/<?= (int) $r['id'] ?>/current" data-confirm="ตั้งรุ่น <?= View::e($r['version']) ?> เป็นรุ่นปัจจุบัน?" data-reload-on-success="1">ตั้งเป็นปัจจุบัน</button>
                      <button type="button" class="btn btn-sm btn-outline-danger" data-post="/admin/desktop-releases/<?= (int) $r['id'] ?>/delete" data-confirm="ลบรุ่น <?= View::e($r['version']) ?> และไฟล์ติดตั้งถาวร?" data-reload-on-success="1" aria-label="ลบรุ่น <?= View::e($r['version']) ?>"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
