<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\OrgUnit;
use App\Models\Role;
/** @var string $url */
/** @var string $defaultUrl */
/** @var string|null $lastRunAt */
/** @var array $divisions */
/** @var array|null $plan */
/** @var string $fingerprint */
/** @var bool $skipRetired */
/** @var string $error */
/** @var string $done */

$roles = Role::all();
$roleChip = function (string $code) use ($roles): string {
    $r = $roles[$code] ?? null;
    if (!$r) {
        return View::e($code);
    }
    return '<span class="badge rounded-pill" style="background:' . View::e($r['chip_bg']) . ';color:' . View::e($r['chip_fg']) . ';font-weight:500;filter:saturate(1.4) brightness(.8)">'
        . View::e($r['short_label']) . '</span>';
};
?>
<div class="d-flex flex-column gap-3" style="max-width:980px">

  <?php if ($done !== ''): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> นำเข้าเรียบร้อย — <?= View::e($done) ?> · <a href="<?= Url::to('/admin/org') ?>">ดูฝ่าย งาน แผนก</a> · <a href="<?= Url::to('/admin/users') ?>">ดูผู้ใช้</a></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= View::e($error) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-diagram-3"></i> นำเข้าโครงสร้างบุคลากรจาก RMS</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        อ่านแผนผังจากหน้าโครงสร้างบุคลากรของ RMS แล้วสร้างฝ่าย / งาน / แผนกที่ยังไม่มี และกำหนดบทบาทให้ผู้ใช้ตามตำแหน่งในแผนผัง —
        ระบบจะแสดงตัวอย่างก่อน ยังไม่บันทึกอะไรจนกว่าจะกดยืนยัน
      </div>
      <form method="post" action="<?= Url::to('/admin/org-import/preview') ?>" class="d-flex flex-column gap-2">
        <?= Csrf::field() ?>
        <label class="form-label" style="font-size:.82rem">URL หน้าโครงสร้างบุคลากร</label>
        <div class="d-flex gap-2 flex-wrap">
          <input type="text" name="url" class="form-control form-control-sm" style="max-width:460px;font-family:ui-monospace,monospace" value="<?= View::e($url) ?>" placeholder="<?= View::e($defaultUrl) ?>" required>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> ดึงข้อมูลและแสดงตัวอย่าง</button>
        </div>
        <div class="form-check mt-1">
          <input type="hidden" name="skip_retired" value="0">
          <input class="form-check-input" type="checkbox" id="skip-retired" name="skip_retired" value="1" <?= $skipRetired ? 'checked' : '' ?>>
          <label class="form-check-label" for="skip-retired" style="font-size:.82rem">ข้ามหน่วยงานที่ RMS ระบุว่า “(สำหรับลบ)”</label>
        </div>
      </form>
      <div class="form-text mt-2" style="font-size:.72rem">
        การจับคู่บุคคลใช้ชื่อ–นามสกุล (ไม่สนใจคำนำหน้า) กับบัญชีผู้ใช้ที่มีอยู่ — ไม่สร้างบัญชีใหม่ ·
        เป็นการ<strong>เพิ่ม</strong>บทบาท ไม่ลบบทบาทที่กำหนดเองไว้ (ยกเว้นบทบาท “เจ้าหน้าที่” ที่ยังไม่ระบุสังกัดจากการซิงค์ ซึ่งจะถูกแทนด้วยบทบาทจริง)
        <?php if ($lastRunAt): ?> · นำเข้าล่าสุด <?= date('d/m/Y H:i', strtotime($lastRunAt)) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($plan): ?>
    <?php
      $newUnits = array_filter($plan['units'], fn($u) => !$u['exists']);
      $newDivs = array_filter($plan['divisions'], fn($d) => (int) $d['target_id'] === 0);
      $roleCount = 0;
      foreach ($plan['people'] as $p) { foreach ($p['roles'] as $refs) { $roleCount += max(1, count($refs)); } }
      $kpis = [
        ['ฝ่าย', count($plan['divisions']), count($newDivs) . ' ใหม่', 'linear-gradient(135deg,#60a5fa,#2563eb)', 'diagram-2'],
        ['งาน / แผนก', count($plan['units']), count($newUnits) . ' ใหม่', 'linear-gradient(135deg,#a78bfa,#6366f1)', 'buildings'],
        ['บุคลากรที่จับคู่ได้', count($plan['people']), $roleCount . ' การมอบหมาย', 'linear-gradient(135deg,#34d399,#059669)', 'person-check'],
        ['ไม่พบบัญชี', count($plan['unmatched']), 'จะถูกข้าม', 'linear-gradient(135deg,#fbbf24,#f97316)', 'person-x'],
      ];
    ?>
    <div class="row g-3">
      <?php foreach ($kpis as [$label, $value, $hint, $grad, $icon]): ?>
        <div class="col-6 col-lg-3">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex gap-3 align-items-start">
              <div style="width:42px;height:42px;border-radius:12px;background:<?= $grad ?>;color:#fff;display:grid;place-items:center;flex:0 0 42px"><i class="bi bi-<?= $icon ?>"></i></div>
              <div>
                <div class="text-body-secondary" style="font-size:.76rem"><?= $label ?></div>
                <div style="font-size:1.45rem;font-weight:600;line-height:1.2"><?= (int) $value ?></div>
                <div class="text-body-secondary" style="font-size:.72rem"><?= View::e($hint) ?></div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="<?= Url::to('/admin/org-import/apply') ?>" class="d-flex flex-column gap-3"
          onsubmit="return confirm('ยืนยันนำเข้าโครงสร้างตามตัวอย่างนี้?')">
      <?= Csrf::field() ?>
      <input type="hidden" name="fingerprint" value="<?= View::e($fingerprint) ?>">
      <input type="hidden" name="skip_retired" value="<?= $skipRetired ? '1' : '0' ?>">

      <div class="card border-0 shadow-sm">
        <div class="card-header"><i class="bi bi-diagram-2"></i> จับคู่ฝ่าย</div>
        <div class="card-body d-flex flex-column gap-2">
          <div class="text-body-secondary" style="font-size:.76rem">ถ้าชื่อฝ่ายใน RMS ต่างจากที่มีในระบบ (เช่น เปลี่ยนชื่อ) ให้เลือกฝ่ายเดิมเพื่อไม่ให้เกิดฝ่ายซ้ำ</div>
          <?php foreach ($plan['divisions'] as $d): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <div style="min-width:240px;font-weight:500;font-size:.86rem"><?= View::e($d['name']) ?></div>
              <i class="bi bi-arrow-right text-body-secondary"></i>
              <select name="division_map[<?= View::e($d['name']) ?>]" class="form-select form-select-sm" style="max-width:300px">
                <option value="0" <?= (int) $d['target_id'] === 0 ? 'selected' : '' ?>>+ สร้างฝ่ายใหม่ชื่อนี้</option>
                <?php foreach ($divisions as $dv): ?>
                  <option value="<?= (int) $dv['id'] ?>" <?= (int) $d['target_id'] === (int) $dv['id'] ? 'selected' : '' ?>>ใช้ฝ่ายเดิม: <?= View::e($dv['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header"><i class="bi bi-buildings"></i> งาน / แผนก (<?= count($plan['units']) ?>)</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
            <thead><tr><th style="padding-left:18px">ชื่อ</th><th>ประเภท</th><th>ฝ่าย (ตาม RMS)</th><th style="padding-right:18px">สถานะ</th></tr></thead>
            <tbody>
              <?php foreach ($plan['units'] as $u): ?>
                <tr>
                  <td style="padding-left:18px"><?= View::e($u['name']) ?></td>
                  <td><?= View::e(OrgUnit::label($u['type'])) ?></td>
                  <td class="text-body-secondary"><?= View::e($u['division']) ?></td>
                  <td style="padding-right:18px"><?= $u['exists'] ? '<span class="badge rounded-pill text-bg-light border">มีอยู่แล้ว</span>' : '<span class="badge rounded-pill text-bg-primary">สร้างใหม่</span>' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($plan['skipped_units']): ?>
          <div class="card-body pt-2 pb-3 text-body-secondary" style="font-size:.74rem">ข้าม <?= count($plan['skipped_units']) ?> หน่วยที่ระบุ “(สำหรับลบ)”: <?= View::e(implode(', ', $plan['skipped_units'])) ?></div>
        <?php endif; ?>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header"><i class="bi bi-person-check"></i> บทบาทที่จะกำหนด (<?= count($plan['people']) ?> คน)</div>
        <div class="table-responsive" style="max-height:480px;overflow-y:auto">
          <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
            <thead><tr><th style="padding-left:18px">ผู้ใช้</th><th style="padding-right:18px">บทบาท · สังกัด</th></tr></thead>
            <tbody>
              <?php foreach ($plan['people'] as $p): ?>
                <tr>
                  <td style="padding-left:18px;white-space:nowrap"><?= View::e($p['full_name']) ?></td>
                  <td style="padding-right:18px">
                    <?php foreach ($p['roles'] as $code => $refs): ?>
                      <div class="d-flex gap-2 align-items-center flex-wrap mb-1">
                        <?= $roleChip($code) ?>
                        <span class="text-body-secondary"><?= View::e(implode(', ', array_map(fn($r) => $r['name'], $refs))) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($plan['unmatched'] || $plan['not_importable']): ?>
        <div class="card border-0 shadow-sm">
          <div class="card-header"><i class="bi bi-person-x"></i> รายชื่อที่จะไม่ถูกนำเข้า (<?= count($plan['unmatched']) + count($plan['not_importable']) ?>)</div>
          <div class="card-body" style="font-size:.8rem">
            <?php if ($plan['unmatched']): ?>
              <div class="mb-2 text-body-secondary" style="font-size:.76rem">ไม่พบบัญชีผู้ใช้ที่ชื่อตรงกัน (หรือพบมากกว่า 1 บัญชี) — ซิงค์ผู้ใช้หรือแก้ชื่อในระบบให้ตรงกับ RMS แล้วดูตัวอย่างใหม่</div>
              <ul class="mb-3">
                <?php foreach ($plan['unmatched'] as $u): ?>
                  <li><?= View::e($u['name']) ?> <span class="text-body-secondary">— <?= View::e($u['where']) ?><?= $u['title'] !== '' ? ' · ' . View::e($u['title']) : '' ?></span><?php if ($u['reason'] === 'ambiguous'): ?> <span class="badge text-bg-warning">ชื่อซ้ำหลายบัญชี</span><?php endif; ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($plan['not_importable']): ?>
              <div class="mb-2 text-body-secondary" style="font-size:.76rem">ผู้ที่สังกัดฝ่ายโดยตรง (ไม่อยู่ในงาน/แผนกใด) — ระบบนี้ให้บทบาทเจ้าหน้าที่ได้เฉพาะในงาน จึงต้องกำหนดเองที่หน้าจัดการผู้ใช้</div>
              <ul class="mb-0">
                <?php foreach ($plan['not_importable'] as $u): ?>
                  <li><?= View::e($u['name']) ?> <span class="text-body-secondary">— <?= View::e($u['where']) ?> · <?= View::e($u['title']) ?></span></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-download"></i> ยืนยันนำเข้า</button>
        <a href="<?= Url::to('/admin/org-import') ?>" class="btn btn-outline-secondary">ยกเลิก</a>
      </div>
    </form>
  <?php endif; ?>
</div>
