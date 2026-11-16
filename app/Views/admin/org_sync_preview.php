<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\OrgUnit;
use App\Models\Role;
/** @var array $plan */
/** @var string $sourceUrl */

$unitChanges = array_values(array_filter($plan['units'], static fn($u) => $u['action'] !== 'keep'));
$keptUnits = count($plan['units']) - count($unitChanges);
$actionLabel = [
    'create' => ['สร้างใหม่', 'text-bg-success'],
    'rename' => ['เปลี่ยนชื่อ', 'text-bg-warning'],
    'move' => ['ย้ายฝ่าย', 'text-bg-info'],
];
$roleLabel = static fn(string $code): string => Role::find($code)['short_label'] ?? $code;
$nothingToDo = !$unitChanges && !$plan['people'];
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a class="btn btn-sm btn-outline-secondary" href="<?= Url::to('/admin/org') ?>"><i class="bi bi-arrow-left"></i> กลับ</a>
  <div class="text-body-secondary" style="font-size:.8rem">ที่มา: <?= View::e($sourceUrl) ?> · ยังไม่มีการบันทึกใด ๆ จนกว่าจะกดยืนยันด้านล่าง</div>
</div>

<div class="card border-0 shadow-sm mb-3" style="border-radius:14px">
  <div class="card-body">
    <div style="font-weight:600;font-size:.95rem" class="mb-2"><i class="bi bi-info-circle"></i> หลักการจับคู่ตำแหน่งใน RMS กับบทบาท</div>
    <ul class="mb-0 text-body-secondary" style="font-size:.8rem">
      <li>ผู้อำนวยการ → <?= View::e($roleLabel('director')) ?> · รองผู้อำนวยการ → <?= View::e($roleLabel('deputy')) ?> (สังกัดฝ่าย)</li>
      <li>ในงาน: หัวหน้างาน → <?= View::e($roleLabel('supervisor')) ?> · ผู้ช่วยหัวหน้างาน → <?= View::e($roleLabel('asst_supervisor')) ?> · ผู้ช่วยงาน → <?= View::e($roleLabel('work_teacher')) ?> · ตำแหน่งอื่น (ลูกจ้าง/เจ้าหน้าที่) → <?= View::e($roleLabel('staff')) ?></li>
      <li>ในแผนกวิชา/ภาควิชา: หัวหน้าแผนก/หัวหน้าภาควิชา → <?= View::e($roleLabel('dept')) ?> · อื่น ๆ → <?= View::e($roleLabel('teacher')) ?></li>
      <li>จับคู่บุคลากรกับบัญชีผู้ใช้ด้วยชื่อ-สกุล · เพิ่มบทบาทเท่านั้น ไม่ลบบทบาทที่มีอยู่ (ยกเว้นบทบาท "เจ้าหน้าที่" แบบไม่ระบุสังกัดที่ได้จากการซิงก์ผู้ใช้)</li>
      <li>เจ้าหน้าที่สารบรรณประจำฝ่ายไม่ได้อยู่ในงานใด จึงไม่ได้กำหนดบทบาทให้</li>
    </ul>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-5">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem" class="mb-1"><i class="bi bi-diagram-3"></i> หน่วยงาน</div>
        <div class="text-body-secondary mb-3" style="font-size:.76rem">เปลี่ยนแปลง <?= count($unitChanges) ?> รายการ · มีอยู่แล้วตรงกัน <?= $keptUnits ?> รายการ</div>
        <div class="d-flex flex-column gap-1" style="font-size:.8rem">
          <?php if (!$unitChanges): ?><div class="text-body-secondary">ไม่มีหน่วยงานที่ต้องเปลี่ยน</div><?php endif; ?>
          <?php foreach ($unitChanges as $u): [$lbl, $badge] = $actionLabel[$u['action']]; ?>
            <div class="d-flex gap-2 align-items-start">
              <span class="badge <?= $badge ?>" style="font-weight:500;min-width:68px"><?= $lbl ?></span>
              <span class="badge text-bg-light" style="font-weight:500"><?= View::e(OrgUnit::label($u['type'])) ?></span>
              <span class="flex-fill">
                <?= View::e($u['name']) ?>
                <?php if ($u['action'] === 'rename'): ?><span class="text-body-secondary">(เดิม: <?= View::e($u['from']) ?>)</span><?php endif; ?>
                <?php if (!empty($u['division']) && $u['type'] === 'work'): ?><span class="text-body-secondary">→ <?= View::e($u['division']) ?></span><?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($plan['ignored']): ?>
          <div class="mt-3 text-body-secondary" style="font-size:.76rem">
            <div style="font-weight:600">ข้ามไป <?= count($plan['ignored']) ?> หน่วย</div>
            <ul class="mb-0"><?php foreach ($plan['ignored'] as $i): ?><li><?= View::e($i) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-7">
    <div class="card border-0 shadow-sm mb-3" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem" class="mb-1"><i class="bi bi-person-check"></i> บทบาทที่จะเพิ่ม (<?= count($plan['people']) ?> คน)</div>
        <div class="d-flex flex-column gap-2 mt-2" style="font-size:.8rem;max-height:60vh;overflow-y:auto">
          <?php if (!$plan['people']): ?><div class="text-body-secondary">บทบาทของทุกคนตรงกับผังอยู่แล้ว</div><?php endif; ?>
          <?php foreach ($plan['people'] as $p): ?>
            <div style="border:1px solid var(--bs-border-color);border-radius:9px;padding:7px 11px">
              <div style="font-weight:600"><?= View::e($p['name']) ?></div>
              <?php foreach ($p['add'] as $a): ?>
                <div><i class="bi bi-plus-circle text-success"></i> <?= View::e($roleLabel($a['role'])) ?><?= $a['unit'] !== null ? ' · ' . View::e($a['unit']) : '' ?></div>
              <?php endforeach; ?>
              <?php if ($p['drop_default_staff']): ?>
                <div class="text-body-secondary"><i class="bi bi-dash-circle text-danger"></i> นำบทบาท "<?= View::e($roleLabel('staff')) ?>" แบบไม่ระบุสังกัดออก</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($plan['unmatched']): ?>
    <div class="card shadow-sm" style="border-radius:14px;border:1px solid #f59e0b">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem" class="mb-1"><i class="bi bi-person-x" style="color:#d97706"></i> จับคู่บัญชีไม่ได้ (<?= count($plan['unmatched']) ?> คน)</div>
        <div class="text-body-secondary mb-2" style="font-size:.76rem">จะไม่ถูกกำหนดบทบาท — ซิงก์ผู้ใช้จาก RMS ก่อน หรือกำหนดบทบาทเองที่หน้าจัดการผู้ใช้</div>
        <div class="d-flex flex-column gap-1" style="font-size:.78rem;max-height:40vh;overflow-y:auto">
          <?php foreach ($plan['unmatched'] as $u): ?>
            <div><strong><?= View::e($u['name']) ?></strong> <span class="text-body-secondary">— <?= View::e($u['reason']) ?> · <?= View::e(implode(' / ', $u['wants'])) ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= Url::to('/admin/org') ?>">ยกเลิก</a>
  <?php if (!$nothingToDo): ?>
  <form method="post" action="<?= Url::to('/admin/org-sync/apply') ?>" onsubmit="return confirm('ยืนยันบันทึกการเปลี่ยนแปลงตามผังองค์กร RMS?')">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle"></i> ยืนยันและบันทึก</button>
  </form>
  <?php endif; ?>
</div>
