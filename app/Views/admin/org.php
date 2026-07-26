<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\OrgUnit;
/** @var array $divisions */
/** @var array $works */
/** @var array $departments */
/** @var string $notice */
/** @var string $error */

/** Renders one unit row: name (inline-editable) + delete. */
$row = function (string $type, array $u, array $divisions): void {
    $label = OrgUnit::label($type);
    $inUse = OrgUnit::usageCount($type, (int) $u['id']);
    ?>
    <div class="d-flex align-items-center gap-2 flex-wrap" style="border:1px solid var(--bs-border-color);border-radius:9px;padding:8px 12px">
      <form method="post" action="<?= Url::to('/admin/org/' . $type . '/' . (int) $u['id']) ?>" class="d-flex align-items-center gap-2 flex-wrap flex-fill">
        <?= Csrf::field() ?>
        <input type="text" name="name" class="form-control form-control-sm" style="max-width:240px" value="<?= View::e($u['name']) ?>" required>
        <?php if ($type === 'work'): ?>
          <select name="division_id" class="form-select form-select-sm" style="max-width:220px">
            <option value="">— ไม่ระบุฝ่าย —</option>
            <?php foreach ($divisions as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (int) ($u['division_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= View::e($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check2"></i> บันทึก</button>
      </form>
      <?php if ($inUse > 0): ?>
        <span class="badge rounded-pill text-bg-secondary" style="font-weight:500" title="มีผู้ใช้ที่ถูกกำหนดบทบาทใน<?= View::e($label) ?>นี้"><i class="bi bi-people"></i> <?= $inUse ?></span>
      <?php endif; ?>
      <form method="post" action="<?= Url::to('/admin/org/' . $type . '/' . (int) $u['id'] . '/delete') ?>"
            onsubmit="return confirm('ยืนยันการลบ <?= View::e(addslashes($u['name'])) ?> ?')">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
      </form>
    </div>
    <?php
};
?>
<?php if ($notice): ?>
  <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> <?= View::e($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= View::e($error) ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- ฝ่าย -->
  <div class="col-12 col-xl-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem;margin-bottom:2px"><i class="bi bi-diagram-2"></i> ฝ่าย (<?= count($divisions) ?>)</div>
        <div class="text-body-secondary mb-3" style="font-size:.76rem">รองผู้อำนวยการสังกัด 1 ฝ่าย</div>

        <form method="post" action="<?= Url::to('/admin/org/division') ?>" class="d-flex gap-2 mb-3">
          <?= Csrf::field() ?>
          <input type="text" name="name" class="form-control form-control-sm" placeholder="ชื่อฝ่ายใหม่" required>
          <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
        </form>

        <div class="d-flex flex-column gap-2">
          <?php if (!$divisions): ?><div class="text-body-secondary" style="font-size:.8rem">ยังไม่มีข้อมูล</div><?php endif; ?>
          <?php foreach ($divisions as $u) { $row('division', $u, $divisions); } ?>
        </div>
      </div>
    </div>
  </div>

  <!-- งาน -->
  <div class="col-12 col-xl-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem;margin-bottom:2px"><i class="bi bi-briefcase"></i> งาน (<?= count($works) ?>)</div>
        <div class="text-body-secondary mb-3" style="font-size:.76rem">หัวหน้างานและเจ้าหน้าที่สังกัดได้หลายงาน</div>

        <form method="post" action="<?= Url::to('/admin/org/work') ?>" class="d-flex flex-column gap-2 mb-3">
          <?= Csrf::field() ?>
          <input type="text" name="name" class="form-control form-control-sm" placeholder="ชื่องานใหม่" required>
          <div class="d-flex gap-2">
            <select name="division_id" class="form-select form-select-sm">
              <option value="">— ไม่ระบุฝ่าย —</option>
              <?php foreach ($divisions as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= View::e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
          </div>
        </form>

        <div class="d-flex flex-column gap-2">
          <?php if (!$works): ?><div class="text-body-secondary" style="font-size:.8rem">ยังไม่มีข้อมูล</div><?php endif; ?>
          <?php foreach ($works as $u) { $row('work', $u, $divisions); } ?>
        </div>
      </div>
    </div>
  </div>

  <!-- แผนก -->
  <div class="col-12 col-xl-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
      <div class="card-body">
        <div style="font-weight:600;font-size:.95rem;margin-bottom:2px"><i class="bi bi-buildings"></i> แผนก (<?= count($departments) ?>)</div>
        <div class="text-body-secondary mb-3" style="font-size:.76rem">หัวหน้าแผนกและครูสังกัดได้หลายแผนก</div>

        <form method="post" action="<?= Url::to('/admin/org/department') ?>" class="d-flex gap-2 mb-3">
          <?= Csrf::field() ?>
          <input type="text" name="name" class="form-control form-control-sm" placeholder="ชื่อแผนกใหม่" required>
          <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
        </form>

        <div class="d-flex flex-column gap-2">
          <?php if (!$departments): ?><div class="text-body-secondary" style="font-size:.8rem">ยังไม่มีข้อมูล</div><?php endif; ?>
          <?php foreach ($departments as $u) { $row('department', $u, $divisions); } ?>
        </div>
      </div>
    </div>
  </div>
</div>
