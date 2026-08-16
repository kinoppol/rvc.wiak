<?php
use App\Core\Url;
use App\Core\View;
use App\Models\Todo;
/** @var array{overdue:array,today:array,soon:array,later:array,nodue:array,done:array} $groups */

$totalActive = count($groups['overdue']) + count($groups['today']) + count($groups['soon'])
             + count($groups['later']) + count($groups['nodue']);

$sections = [
    'overdue' => ['เกินกำหนด',   'danger',    'exclamation-circle-fill'],
    'today'   => ['วันนี้',       'warning',   'calendar-check-fill'],
    'soon'    => ['ภายใน 7 วัน', 'primary',   'calendar3'],
    'later'   => ['กำหนดการถัดไป','secondary', 'calendar-range'],
    'nodue'   => ['ไม่มีกำหนด',   'secondary', 'infinity'],
    'done'    => ['เสร็จสิ้นล่าสุด (7 วัน)', 'success', 'check-circle-fill'],
];
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <div class="flex-fill">
    <div class="text-body-secondary" style="font-size:.8rem"><?= $totalActive ?> รายการที่ยังค้างอยู่</div>
  </div>
  <button type="button" class="btn btn-sm btn-primary" data-open-new-todo>
    <i class="bi bi-plus-lg"></i> สร้างรายการ
  </button>
</div>

<?php if ($totalActive === 0 && empty($groups['done'])): ?>
<div class="card border-0 shadow-sm text-center" style="border-radius:14px;padding:48px 24px">
  <i class="bi bi-check2-all" style="font-size:2.5rem;color:var(--bs-success)"></i>
  <div class="mt-2" style="font-weight:600">ไม่มีรายการค้างอยู่</div>
  <div class="text-body-secondary" style="font-size:.82rem">กดปุ่ม "สร้างรายการ" เพื่อเพิ่มงานส่วนตัว</div>
</div>
<?php endif; ?>

<?php foreach ($sections as $key => [$label, $color, $icon]): ?>
  <?php if (empty($groups[$key])) { continue; } ?>
  <div class="card border-0 shadow-sm mb-3" style="border-radius:14px">
    <div class="card-header bg-transparent d-flex align-items-center gap-2" style="padding:10px 16px">
      <i class="bi bi-<?= $icon ?> text-<?= $color ?>" style="font-size:.9rem"></i>
      <span style="font-weight:600;font-size:.88rem"><?= $label ?></span>
      <span class="badge text-bg-<?= $color ?> rounded-pill" style="font-size:.66rem"><?= count($groups[$key]) ?></span>
    </div>
    <div class="list-group list-group-flush" style="border-radius:0 0 14px 14px">
      <?php foreach ($groups[$key] as $t): ?>
        <?php
          $isRecur   = $t['recur_type'] !== 'none';
          $isDone    = (bool) $t['is_done'];
          $recurLbl  = $isRecur ? Todo::recurLabel($t) : '';
          $due       = $t['due_at'] ? new DateTimeImmutable($t['due_at']) : null;
          $now       = new DateTimeImmutable();
          $isOverdue = $due && $due < $now && !$isDone;
        ?>
        <div class="list-group-item d-flex align-items-start gap-3" style="padding:10px 16px">
          <div class="pt-1">
            <?php if (!$isDone): ?>
              <button type="button" class="btn btn-sm btn-outline-success rounded-circle"
                      data-post="/todos/<?= (int) $t['id'] ?>/done"
                      data-reload-on-success="1"
                      style="width:28px;height:28px;padding:0;line-height:1"
                      title="ทำเสร็จแล้ว">
                <i class="bi bi-check-lg" style="font-size:.75rem"></i>
              </button>
            <?php else: ?>
              <span style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;color:var(--bs-success)">
                <i class="bi bi-check-circle-fill"></i>
              </span>
            <?php endif; ?>
          </div>

          <div class="flex-fill min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span style="font-size:.88rem;font-weight:<?= $isDone ? '400' : '500' ?>;text-decoration:<?= $isDone ? 'line-through' : 'none' ?>;color:<?= $isDone ? 'var(--bs-secondary)' : 'inherit' ?>"><?= View::e($t['title']) ?></span>
              <?php if ($isRecur): ?>
                <span class="badge rounded-pill text-bg-light border" style="font-size:.63rem"><i class="bi bi-arrow-repeat"></i> <?= View::e($recurLbl) ?></span>
              <?php endif; ?>
              <?php if ($t['missed_count'] > 0): ?>
                <span class="badge rounded-pill text-bg-danger" style="font-size:.63rem">พลาด <?= (int) $t['missed_count'] ?> ครั้ง</span>
              <?php endif; ?>
            </div>

            <?php if ($t['note']): ?>
              <div class="text-body-secondary mt-1" style="font-size:.76rem;white-space:pre-wrap"><?= View::e(mb_strimwidth($t['note'], 0, 120, '…')) ?></div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-3 mt-1 flex-wrap" style="font-size:.74rem;color:var(--bs-secondary)">
              <?php if ($due): ?>
                <span style="color:<?= $isOverdue ? '#dc2626' : 'inherit' ?>;font-weight:<?= $isOverdue ? '600' : '400' ?>">
                  <i class="bi bi-clock<?= $isOverdue ? '-fill' : '' ?>"></i>
                  <?= $isOverdue ? 'เกินกำหนด ' : '' ?><?= $due->format('d/m/Y H:i') ?>
                </span>
              <?php endif; ?>
              <?php if ($isDone && $t['done_at']): ?>
                <span><i class="bi bi-check-all"></i> เสร็จ <?= (new DateTimeImmutable($t['done_at']))->format('d/m/Y H:i') ?></span>
              <?php endif; ?>
              <?php if ($t['recur_end_at']): ?>
                <span><i class="bi bi-flag"></i> สิ้นสุด <?= date('d/m/Y', strtotime($t['recur_end_at'])) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$isDone): ?>
          <div class="d-flex gap-1 align-items-center pt-1">
            <?php if ($isOverdue): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      data-post="/todos/<?= (int) $t['id'] ?>/miss"
                      data-reload-on-success="1"
                      data-confirm="บันทึกรายการนี้เป็นพลาดและ<?= $isRecur ? 'ขยับไปรอบถัดไป' : 'ปิดรายการ' ?>?"
                      style="font-size:.72rem" title="บันทึกเป็นพลาด">
                <i class="bi bi-x-circle"></i>
              </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-open-todo="<?= (int) $t['id'] ?>"
                    style="font-size:.72rem" title="แก้ไข">
              <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-post="/todos/<?= (int) $t['id'] ?>/delete"
                    data-reload-on-success="1"
                    data-confirm="ลบรายการ '<?= View::e($t['title']) ?>' ?"
                    style="font-size:.72rem" title="ลบ">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
