<?php
use App\Core\Url;
use App\Core\View;
/** @var array|null $release */
?>
<div style="max-width:620px">
  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:1rem;margin-bottom:4px"><i class="bi bi-window-desktop"></i> แอปแจ้งเตือนสำหรับ Windows</div>
      <div class="text-body-secondary mb-3" style="font-size:.82rem">
        รับแจ้งเตือนงานใหม่และงานใกล้ถึงกำหนดบนหน้าจอ คลิกแล้วเปิดงานในเบราว์เซอร์ได้ทันที รองรับ Windows 11 ขึ้นไป
      </div>
      <?php if ($release): ?>
        <a class="btn btn-primary" href="<?= Url::to('/desktop/download/' . (int) $release['id']) ?>">
          <i class="bi bi-download"></i> ดาวน์โหลดรุ่น <?= View::e($release['version']) ?>
        </a>
        <div class="text-body-secondary mt-2" style="font-size:.76rem">
          <?= number_format((int) $release['size_bytes'] / 1048576, 1) ?> MB ·
          อัปโหลดเมื่อ <?= View::e(date('d/m/Y H:i', strtotime((string) $release['created_at']))) ?>
        </div>
        <?php if ($release['notes']): ?>
          <hr>
          <div style="font-size:.82rem;font-weight:600">มีอะไรใหม่</div>
          <div class="text-body-secondary" style="font-size:.82rem;white-space:pre-line"><?= View::e($release['notes']) ?></div>
        <?php endif; ?>
        <div class="text-body-secondary mt-3" style="font-size:.7rem;word-break:break-all">SHA-256: <?= View::e($release['sha256']) ?></div>
      <?php else: ?>
        <div class="alert alert-secondary py-2 mb-0" style="font-size:.84rem">ยังไม่มีไฟล์ติดตั้งให้ดาวน์โหลด</div>
      <?php endif; ?>
    </div>
  </div>
</div>
