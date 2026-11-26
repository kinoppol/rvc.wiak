<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Room;
/** @var array<int,array> $rooms */
/** @var array<int,array> $equipment */
/** @var bool $isAdmin */
/** @var array $works */
/** @var int|null $mediaWorkId */
/** @var bool $mediaGuessed */
/** @var array $approvers      media งาน's หัวหน้างาน/เจ้าหน้าที่ */
/** @var bool  $namedReady     room_approvers table exists */
/** @var array $namedApprovers people named to approve every room */
/** @var array $personItems    combo items for adding one (admins only) */
/** @var string $notice */
/** @var string $error */

$roomForm = function (?array $r) use ($equipment): string {
    $isNew = $r === null;
    $id = $isNew ? 0 : (int) $r['id'];
    $color = $r['color'] ?? Room::COLORS[0];
    ob_start(); ?>
    <form method="post" action="<?= Url::to('/rooms/manage/rooms' . ($isNew ? '' : '/' . $id)) ?>" class="d-flex flex-column gap-2">
      <?= Csrf::field() ?>
      <div class="row g-2">
        <div class="col-md-5">
          <label class="form-label mb-1" style="font-size:.78rem">ชื่อห้อง <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control form-control-sm" maxlength="150" required value="<?= View::e($r['name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label mb-1" style="font-size:.78rem">สถานที่</label>
          <input type="text" name="location" class="form-control form-control-sm" maxlength="255" value="<?= View::e($r['location'] ?? '') ?>" placeholder="เช่น อาคาร 1 ชั้น 2">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1" style="font-size:.78rem">ความจุ (คน)</label>
          <input type="number" name="capacity" class="form-control form-control-sm" min="0" max="65535" value="<?= View::e((string) ($r['capacity'] ?? '')) ?>">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label mb-1" style="font-size:.78rem">ลำดับ</label>
          <input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) ($r['sort_order'] ?? 0) ?>">
        </div>
      </div>
      <div>
        <label class="form-label mb-1" style="font-size:.78rem">รายละเอียด</label>
        <textarea name="description" class="form-control form-control-sm" rows="1"><?= View::e($r['description'] ?? '') ?></textarea>
      </div>
      <div>
        <div class="form-label mb-1" style="font-size:.78rem">อุปกรณ์สื่อในห้อง (ติ๊กและระบุจำนวน)</div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($equipment as $e): ?>
            <?php $has = isset($r['equipment'][(int) $e['id']]); ?>
            <label class="d-inline-flex align-items-center gap-1 border rounded-pill" style="padding:3px 6px 3px 10px;font-size:.78rem">
              <input type="checkbox" class="form-check-input m-0" name="equipment[]" value="<?= (int) $e['id'] ?>" <?= $has ? 'checked' : '' ?>>
              <i class="bi bi-<?= View::e($e['icon']) ?>"></i> <?= View::e($e['name']) ?>
              <input type="number" name="qty[<?= (int) $e['id'] ?>]" min="1" max="999" value="<?= $has ? (int) $r['equipment'][(int) $e['id']]['quantity'] : 1 ?>"
                     class="form-control form-control-sm" style="width:58px;padding:1px 6px;font-size:.75rem" title="จำนวน">
            </label>
          <?php endforeach; ?>
          <?php if (!$equipment): ?><span class="text-body-secondary" style="font-size:.78rem">ยังไม่มีรายการอุปกรณ์ — เพิ่มได้ที่ส่วน "ประเภทอุปกรณ์สื่อ"</span><?php endif; ?>
        </div>
      </div>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-1" style="font-size:.78rem">
          <span class="text-body-secondary">สีในปฏิทิน:</span>
          <?php foreach (Room::COLORS as $c): ?>
            <label style="cursor:pointer" title="<?= $c ?>">
              <input type="radio" name="color" value="<?= $c ?>" class="d-none room-color-input" <?= strtolower($color) === $c ? 'checked' : '' ?>>
              <span class="room-color-dot" style="background:<?= $c ?>"></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-check form-switch m-0" style="font-size:.8rem">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" name="is_active" value="1" id="room-active-<?= $id ?>" <?= ($r === null || (int) $r['is_active']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="room-active-<?= $id ?>">เปิดให้จอง</label>
        </div>
        <?php if (Room::hasInactiveReason()): ?>
          <input type="text" name="inactive_reason" class="form-control form-control-sm" style="max-width:300px" maxlength="255"
                 value="<?= View::e($r['inactive_reason'] ?? '') ?>" placeholder="สาเหตุที่ปิดการใช้งาน (จำเป็นเมื่อปิดให้จอง)">
        <?php endif; ?>
        <div class="flex-fill"></div>
        <?php if ($isNew): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button><?php endif; ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-<?= $isNew ? 'plus-lg' : 'save' ?>"></i> <?= $isNew ? 'เพิ่มห้อง' : 'บันทึก' ?></button>
      </div>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<?php if ($notice): ?><div class="alert alert-success py-2 mb-0" style="font-size:.85rem"><i class="bi bi-check-circle"></i> <?= View::e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2 mb-0" style="font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> <?= View::e($error) ?></div><?php endif; ?>

<div class="d-flex gap-2 flex-wrap">
  <a href="<?= Url::to('/rooms') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar3"></i> ปฏิทินห้องประชุม</a>
  <a href="<?= Url::to('/rooms/requests#pending') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-hourglass-split"></i> คำขอรออนุมัติ</a>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent d-flex align-items-center gap-2" style="padding:10px 18px">
    <div style="font-weight:600;font-size:.9rem" class="flex-fill"><i class="bi bi-door-open"></i> ห้องประชุม (<?= count($rooms) ?>)</div>
    <button type="button" class="btn btn-sm btn-primary" data-open-template="room-add-modal"><i class="bi bi-plus-lg"></i> เพิ่มห้องประชุม</button>
  </div>
  <div class="list-group list-group-flush">
    <?php if (!$rooms): ?>
      <div class="list-group-item text-center text-body-secondary" style="padding:28px 18px;font-size:.85rem">
        <i class="bi bi-door-closed" style="font-size:1.6rem"></i>
        <div class="mt-1">ยังไม่มีห้องประชุม — กดปุ่ม "เพิ่มห้องประชุม" เพื่อเริ่มต้น</div>
      </div>
    <?php endif; ?>
    <?php foreach ($rooms as $r): ?>
      <div class="list-group-item" id="room-<?= (int) $r['id'] ?>" style="padding:10px 18px">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span style="width:12px;height:12px;border-radius:50%;background:<?= View::e($r['color']) ?>"></span>
          <div style="font-weight:600;font-size:.88rem"><?= View::e($r['name']) ?></div>
          <?php if (!(int) $r['is_active']): ?><span class="badge text-bg-secondary" style="font-size:.64rem">ปิดการจอง</span><?php if (!empty($r['inactive_reason'])): ?><span class="text-danger" style="font-size:.74rem">— <?= View::e($r['inactive_reason']) ?></span><?php endif; ?><?php endif; ?>
          <span class="text-body-secondary" style="font-size:.76rem">
            <?= $r['location'] ? View::e($r['location']) . ' · ' : '' ?><?= $r['capacity'] ? (int) $r['capacity'] . ' คน · ' : '' ?>
            <?= $r['equipment'] ? View::e(implode(', ', array_map(fn($e) => $e['name'] . ((int) $e['quantity'] > 1 ? ' ×' . (int) $e['quantity'] : ''), $r['equipment']))) : 'ไม่มีอุปกรณ์สื่อ' ?>
          </span>
          <div class="flex-fill"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#room-edit-<?= (int) $r['id'] ?>"><i class="bi bi-pencil"></i> แก้ไข</button>
          <form method="post" action="<?= Url::to('/rooms/manage/rooms/' . (int) $r['id'] . '/delete') ?>" onsubmit="return confirm('ลบห้องนี้?')">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
          </form>
        </div>
        <div class="collapse mt-2" id="room-edit-<?= (int) $r['id'] ?>">
          <?= $roomForm($r) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<template id="room-add-modal">
  <div class="modal-overlay">
    <div class="card border-0 shadow-lg" style="width:min(760px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-plus-circle" style="font-size:1.2rem;color:#1e3a8a"></i>
          <div style="font-weight:600" class="flex-fill">เพิ่มห้องประชุม</div>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay title="ปิด"><i class="bi bi-x-lg"></i></button>
        </div>
        <?= $roomForm(null) ?>
      </div>
    </div>
  </div>
</template>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px" id="equipment">
      <div class="card-header bg-transparent" style="padding:12px 18px;font-weight:600;font-size:.9rem"><i class="bi bi-speaker"></i> ประเภทอุปกรณ์สื่อ</div>
      <div class="list-group list-group-flush">
        <?php foreach ($equipment as $e): ?>
          <div class="list-group-item d-flex align-items-center gap-2" style="padding:8px 18px">
            <form method="post" action="<?= Url::to('/rooms/manage/equipment/' . (int) $e['id']) ?>" class="d-flex align-items-center gap-2 flex-fill">
              <?= Csrf::field() ?>
              <select name="icon" class="form-select form-select-sm" style="width:130px">
                <?php foreach (Room::ICONS as $icon => $iconLabel): ?>
                  <option value="<?= $icon ?>" <?= $icon === $e['icon'] ? 'selected' : '' ?>><?= View::e($iconLabel) ?></option>
                <?php endforeach; ?>
              </select>
              <i class="bi bi-<?= View::e($e['icon']) ?>"></i>
              <input type="text" name="name" class="form-control form-control-sm" maxlength="150" value="<?= View::e($e['name']) ?>" required>
              <button type="submit" class="btn btn-sm btn-outline-primary" title="บันทึก"><i class="bi bi-save"></i></button>
            </form>
            <form method="post" action="<?= Url::to('/rooms/manage/equipment/' . (int) $e['id'] . '/delete') ?>" onsubmit="return confirm('ลบอุปกรณ์นี้?')">
              <?= Csrf::field() ?>
              <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        <?php endforeach; ?>
        <div class="list-group-item" style="padding:10px 18px;background:var(--bs-tertiary-bg)">
          <form method="post" action="<?= Url::to('/rooms/manage/equipment') ?>" class="d-flex align-items-center gap-2">
            <?= Csrf::field() ?>
            <select name="icon" class="form-select form-select-sm" style="width:130px">
              <?php foreach (Room::ICONS as $icon => $iconLabel): ?>
                <option value="<?= $icon ?>"><?= View::e($iconLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="name" class="form-control form-control-sm" maxlength="150" placeholder="ชื่ออุปกรณ์ใหม่" required>
            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px" id="approvers">
      <div class="card-header bg-transparent" style="padding:12px 18px;font-weight:600;font-size:.9rem"><i class="bi bi-person-check"></i> ผู้อนุมัติการจอง</div>
      <div class="card-body d-flex flex-column gap-2" style="font-size:.84rem">
        <div class="text-body-secondary" style="font-size:.78rem">ผู้ดูแลระบบ บุคคลที่กำหนดด้านล่าง และหัวหน้างาน/เจ้าหน้าที่ของงานที่เลือก อนุมัติคำขอใช้ได้ทุกห้อง</div>

        <div style="font-weight:600;font-size:.82rem" class="mt-1"><i class="bi bi-person-plus"></i> บุคคลที่อนุมัติได้ทุกห้อง</div>
        <?php if (!$namedReady): ?>
          <div class="text-body-secondary" style="font-size:.78rem">ต้องรัน migration <code>20261126_add_room_approvers.sql</code> ที่หน้า "Migration ฐานข้อมูล" ก่อน</div>
        <?php else: ?>
          <?php if ($namedApprovers): ?>
            <div class="d-flex flex-column gap-1">
              <?php foreach ($namedApprovers as $a): ?>
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-person"></i>
                  <span class="flex-fill"><?= View::e($a['full_name']) ?><?= (int) $a['is_active'] ? '' : ' <span class="badge text-bg-secondary" style="font-size:.64rem">บัญชีปิดใช้งาน</span>' ?></span>
                  <?php if ($isAdmin): ?>
                    <form method="post" action="<?= Url::to('/rooms/manage/approvers/' . (int) $a['id'] . '/delete') ?>" onsubmit="return confirm('นำ <?= View::e(addslashes($a['full_name'])) ?> ออกจากผู้อนุมัติ?')">
                      <?= Csrf::field() ?>
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="นำออก"><i class="bi bi-x-lg"></i></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-body-secondary" style="font-size:.78rem">ยังไม่ได้กำหนดบุคคล</div>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
            <form method="post" action="<?= Url::to('/rooms/manage/approvers') ?>" class="d-flex gap-2 align-items-start">
              <?= Csrf::field() ?>
              <div class="flex-fill">
                <?= View::render('partials/combo', ['name' => '', 'valueName' => 'user_id', 'items' => $personItems, 'placeholder' => 'พิมพ์ชื่อ บทบาท หรือหน่วยงาน เพื่อค้นหา…', 'required' => true]) ?>
              </div>
              <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>

        <hr class="my-1">
        <div style="font-weight:600;font-size:.82rem"><i class="bi bi-diagram-3"></i> งานผู้อนุมัติ</div>
        <?php if ($isAdmin): ?>
          <form method="post" action="<?= Url::to('/rooms/manage/media-work') ?>" class="d-flex gap-2">
            <?= Csrf::field() ?>
            <select name="work_id" class="form-select form-select-sm">
              <option value="0">— ให้ระบบหางานที่ชื่อมีคำว่า "สื่อ" หรือ "โสต" —</option>
              <?php foreach ($works as $w): ?>
                <option value="<?= (int) $w['id'] ?>" <?= !$mediaGuessed && (int) $w['id'] === $mediaWorkId ? 'selected' : '' ?>><?= View::e($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-save"></i> บันทึก</button>
          </form>
        <?php endif; ?>
        <div>
          งานผู้อนุมัติ:
          <?php
            $workName = null;
            foreach ($works as $w) { if ((int) $w['id'] === $mediaWorkId) { $workName = $w['name']; } }
          ?>
          <?php if ($workName): ?>
            <b><?= View::e($workName) ?></b><?= $mediaGuessed ? ' <span class="text-body-secondary">(ระบบเลือกให้อัตโนมัติ)</span>' : '' ?>
          <?php else: ?>
            <span class="text-danger">ยังไม่ได้กำหนด<?= $namedApprovers ? '' : ' — ตอนนี้มีเพียงผู้ดูแลระบบที่อนุมัติได้' ?></span>
          <?php endif; ?>
        </div>
        <?php if ($approvers): ?>
          <div class="d-flex flex-column gap-1">
            <?php foreach ($approvers as $a): ?>
              <div><i class="bi bi-person"></i> <?= View::e($a['full_name']) ?> <span class="badge text-bg-light border" style="font-size:.66rem"><?= View::e($a['role']) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php elseif ($workName): ?>
          <div class="text-body-secondary">ยังไม่มีหัวหน้างาน/เจ้าหน้าที่ในงานนี้ — กำหนดได้ที่ "จัดการผู้ใช้และบทบาท"</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
