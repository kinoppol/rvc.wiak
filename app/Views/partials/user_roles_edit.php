<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\OrgUnit;
use App\Models\Role;
/** @var array $target */
/** @var string[] $currentRoles */
/** @var array<string,int[]> $currentUnits */
/** @var array $roles keyed by code */
/** @var array<string,array> $unitsByType */
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(540px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-person-vcard" style="font-size:1.2rem;color:#1e3a8a"></i>
        <div style="font-weight:600" class="flex-fill">กำหนดบทบาท: <?= View::e($target['full_name']) ?></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem;font-family:ui-monospace,monospace"><?= View::e($target['username']) ?></div>

      <form data-ajax-form action="<?= Url::to('/admin/users/' . (int) $target['id'] . '/roles') ?>" data-reload-on-success="1" class="d-flex flex-column gap-2">
        <?= Csrf::field() ?>
        <?php foreach ($roles as $code => $r): ?>
          <?php
            $checked = in_array($code, $currentRoles, true);
            $unitType = Role::unitType($code);
            $single = Role::needsSingleUnit($code);
            $selected = $currentUnits[$code] ?? [];
            $options = $unitType ? ($unitsByType[$unitType] ?? []) : [];
          ?>
          <div style="border:1px solid var(--bs-border-color);border-radius:9px;padding:8px 12px">
            <label class="d-flex align-items-center gap-2" style="cursor:pointer">
              <input type="checkbox" name="roles[]" value="<?= View::e($code) ?>" <?= $checked ? 'checked' : '' ?>
                     data-role-toggle="<?= View::e($code) ?>">
              <span class="badge rounded-pill" style="background:<?= View::e($r['chip_bg']) ?>;color:<?= View::e($r['chip_fg']) ?>;font-weight:500"><i class="bi bi-<?= View::e($r['icon']) ?>"></i></span>
              <span style="font-size:.85rem"><?= View::e($r['label']) ?></span>
            </label>

            <?php if ($unitType !== null): ?>
              <div data-role-units="<?= View::e($code) ?>" style="<?= $checked ? '' : 'display:none' ?>;margin-top:8px;padding-left:26px">
                <div class="text-body-secondary mb-1" style="font-size:.74rem">
                  ต้องระบุ<?= View::e(OrgUnit::label($unitType)) ?><?= $single ? ' (เลือกได้ 1 รายการ)' : ' (เลือกได้หลายรายการ)' ?>
                </div>

                <?php if (!$options): ?>
                  <div class="text-warning-emphasis" style="font-size:.76rem">
                    <i class="bi bi-exclamation-triangle"></i> ยังไม่มีข้อมูล<?= View::e(OrgUnit::label($unitType)) ?>ในระบบ
                    — <a href="<?= Url::to('/admin/org') ?>">เพิ่มที่เมนูจัดการฝ่าย งาน และแผนก</a>
                  </div>
                <?php elseif ($single): ?>
                  <select name="units[<?= View::e($code) ?>][]" class="form-select form-select-sm">
                    <option value="">— เลือก<?= View::e(OrgUnit::label($unitType)) ?> —</option>
                    <?php foreach ($options as $o): ?>
                      <option value="<?= (int) $o['id'] ?>" <?= in_array((int) $o['id'], $selected, true) ? 'selected' : '' ?>><?= View::e($o['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <div style="max-height:150px;overflow-y:auto;border:1px solid var(--bs-border-color);border-radius:8px;padding:6px 10px">
                    <?php foreach ($options as $o): ?>
                      <label class="d-flex align-items-center gap-2" style="cursor:pointer;padding:2px 0">
                        <input type="checkbox" name="units[<?= View::e($code) ?>][]" value="<?= (int) $o['id'] ?>"
                               <?= in_array((int) $o['id'], $selected, true) ? 'checked' : '' ?>>
                        <span style="font-size:.8rem"><?= View::e($o['name']) ?><?php if (!empty($o['division_name'])): ?><span class="text-body-secondary"> · <?= View::e($o['division_name']) ?></span><?php endif; ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="d-flex justify-content-end gap-2 mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> บันทึกบทบาท</button>
        </div>
      </form>
    </div>
  </div>
</div>
