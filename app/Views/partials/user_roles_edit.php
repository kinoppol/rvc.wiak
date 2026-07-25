<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
/** @var array $target */
/** @var string[] $currentRoles */
/** @var array $roles keyed by code */
?>
<div class="modal-overlay">
  <div class="card border-0 shadow-lg" style="width:min(460px,100%);border-radius:14px;max-height:92vh;overflow-y:auto">
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
          <label class="d-flex align-items-center gap-2" style="border:1px solid var(--bs-border-color);border-radius:9px;padding:8px 12px;cursor:pointer">
            <input type="checkbox" name="roles[]" value="<?= View::e($code) ?>" <?= in_array($code, $currentRoles, true) ? 'checked' : '' ?>>
            <span class="badge rounded-pill" style="background:<?= View::e($r['chip_bg']) ?>;color:<?= View::e($r['chip_fg']) ?>;font-weight:500"><i class="bi bi-<?= View::e($r['icon']) ?>"></i></span>
            <span style="font-size:.85rem"><?= View::e($r['label']) ?></span>
          </label>
        <?php endforeach; ?>

        <div class="d-flex justify-content-end gap-2 mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ยกเลิก</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> บันทึกบทบาท</button>
        </div>
      </form>
    </div>
  </div>
</div>
