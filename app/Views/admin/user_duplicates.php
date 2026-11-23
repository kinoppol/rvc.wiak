<?php
use App\Core\Url;
use App\Core\View;
use App\Models\UserMerge;
/** @var array{merge:array,review:array} $found */
/** @var bool $migrated */

$safeCount = 0;
foreach ($found['merge'] as $g) {
    $safeCount += count($g['sources']);
}
$describe = static function (array $u): string {
    return 'บัญชี #' . (int) $u['id'] . ' · ' . (str_starts_with((string) $u['username'], 'oa:') ? $u['username'] : 'เลขประชาชน ' . UserMerge::maskNationalId((string) $u['nid']))
        . ($u['oa_user_id'] !== null ? ' · RVC-ONE ' . (int) $u['oa_user_id'] : '');
};
?>
<?php if (!$migrated): ?>
  <div class="alert alert-warning" style="border-radius:14px">
    ยังไม่ได้รัน migration <code>20261123_add_user_national_id.sql</code> กรุณารันที่ <a href="<?= Url::to('/admin/migrations') ?>">Migration ฐานข้อมูล</a> ก่อนรวมบัญชี
  </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3" style="border-radius:14px">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <div style="font-weight:600">รวมบัญชีที่ซ้ำ (<?= $safeCount ?> บัญชี)</div>
      <div class="text-body-secondary" style="font-size:.8rem">งาน ไฟล์ บทบาท และประวัติจะย้ายไปยังบัญชีที่มีเลขประจำตัวประชาชน การรวมบัญชีย้อนกลับไม่ได้</div>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= Url::to('/admin/users') ?>" class="btn btn-sm btn-outline-secondary">กลับ</a>
      <?php if ($safeCount > 0 && $migrated): ?>
        <button type="button" class="btn btn-sm btn-primary" data-post="/admin/users/duplicates/merge" data-reload-on-success="1" data-confirm="รวม <?= $safeCount ?> บัญชีที่ซ้ำ? การรวมย้อนกลับไม่ได้"><i class="bi bi-link-45deg"></i> รวมทั้งหมด</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$found['merge'] && !$found['review']): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px"><div class="card-body text-center text-body-secondary py-4">ไม่พบบัญชีซ้ำ</div></div>
<?php endif; ?>

<?php foreach ($found['merge'] as $g): ?>
  <div class="card border-0 shadow-sm mb-2" style="border-radius:14px">
    <div class="card-body" style="font-size:.85rem">
      <div style="font-weight:600"><?= View::e($g['target']['full_name']) ?></div>
      <div class="text-success-emphasis mb-2" style="font-size:.78rem"><i class="bi bi-check-circle"></i> บัญชีหลัก: <?= View::e($describe($g['target'])) ?></div>
      <?php foreach ($g['sources'] as $s): ?>
        <div class="d-flex align-items-center justify-content-between gap-2 py-1">
          <span><?= View::e($describe($s)) ?></span>
          <?php if ($migrated): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-post="/admin/users/duplicates/<?= (int) $s['id'] ?>/merge" data-reload-on-success="1" data-confirm="รวมบัญชี #<?= (int) $s['id'] ?> เข้าบัญชีหลัก? การรวมย้อนกลับไม่ได้">รวมบัญชี</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php foreach ($g['blocked'] as $s): ?>
        <div class="d-flex align-items-center justify-content-between gap-2 py-1">
          <span><?= View::e($describe($s)) ?> <span class="text-warning-emphasis" style="font-size:.75rem"><i class="bi bi-exclamation-triangle"></i> ผูกกับ RVC-ONE คนละรหัส โปรดตรวจว่าเป็นคนเดียวกัน</span></span>
          <?php if ($migrated): ?>
            <button type="button" class="btn btn-sm btn-outline-warning" data-post="/admin/users/duplicates/<?= (int) $s['id'] ?>/merge" data-reload-on-success="1" data-confirm="ยืนยันว่าบัญชี #<?= (int) $s['id'] ?> เป็นคนเดียวกับบัญชีหลัก? การรวมย้อนกลับไม่ได้">รวมบัญชี</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($found['review']): ?>
  <div class="mt-3 mb-2" style="font-weight:600">ชื่อซ้ำแต่รวมอัตโนมัติไม่ได้ (<?= count($found['review']) ?> กลุ่ม)</div>
  <?php foreach ($found['review'] as $g): ?>
    <div class="card border-0 shadow-sm mb-2" style="border-radius:14px">
      <div class="card-body" style="font-size:.85rem">
        <div style="font-weight:600"><?= View::e($g['users'][0]['full_name']) ?></div>
        <div class="text-warning-emphasis mb-1" style="font-size:.78rem"><?= View::e($g['reason']) ?></div>
        <?php foreach ($g['users'] as $u): ?><div><?= View::e($describe($u)) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
