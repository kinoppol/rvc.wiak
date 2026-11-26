<?php
use App\Core\Url;
use App\Core\View;
use App\Models\RoomBooking;
/** @var array $mine */
/** @var bool $isApprover */
/** @var array $pending */
/** @var array $decided */

$today = date('Y-m-d');
$list = function (array $rows, bool $showUser, string $empty) use ($today): string {
    if (!$rows) {
        return '<div class="text-center text-body-secondary" style="padding:34px 16px;font-size:.85rem"><i class="bi bi-inbox" style="font-size:1.6rem"></i><div class="mt-1">' . $empty . '</div></div>';
    }
    ob_start(); ?>
    <div class="list-group list-group-flush">
      <?php foreach ($rows as $b): ?>
        <?php [$stLabel, $stBg, $stFg, $stIcon] = RoomBooking::STATUS[$b['status']]; $past = $b['end_date'] < $today; ?>
        <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3" style="padding:10px 16px;<?= $past ? 'opacity:.6' : '' ?>"
                data-open-url="/rooms/bookings/<?= (int) $b['id'] ?>">
          <span style="width:4px;align-self:stretch;border-radius:4px;background:<?= View::e($b['room_color']) ?>"></span>
          <div class="flex-fill" style="min-width:0">
            <div style="font-weight:500;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= View::e($b['purpose']) ?></div>
            <div class="text-body-secondary" style="font-size:.76rem">
              <i class="bi bi-door-open"></i> <?= View::e($b['room_name']) ?>
              · <i class="bi bi-calendar-event"></i> <?= View::e(RoomBooking::rangeLabel($b)) ?>
              <?php if ($showUser): ?> · <i class="bi bi-person"></i> <?= View::e($b['user_name']) ?><?php endif; ?>
            </div>
            <?php if ($b['equipment']): ?>
              <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach ($b['equipment'] as $e): ?>
                  <span class="badge rounded-pill text-bg-light border" style="font-size:.64rem;font-weight:500"><i class="bi bi-<?= View::e($e['icon']) ?>"></i> <?= View::e($e['name']) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <span class="badge rounded-pill" style="background:<?= $stBg ?>;color:<?= $stFg ?>;font-weight:500;font-size:.7rem;white-space:nowrap"><i class="bi bi-<?= $stIcon ?>"></i> <?= $stLabel ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php return (string) ob_get_clean();
};
$livePending = count(array_filter($pending, fn($b) => $b['end_date'] >= $today));
?>
<div class="d-flex align-items-center gap-2 flex-wrap">
  <ul class="nav settings-tabs" role="tablist" data-tab-hash>
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#mine" data-hash="mine" type="button"><i class="bi bi-person"></i> คำขอของฉัน</button></li>
    <?php if ($isApprover): ?>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pending" data-hash="pending" type="button"><i class="bi bi-hourglass-split"></i> รออนุมัติ <?php if ($livePending): ?><span class="badge rounded-pill text-bg-danger"><?= $livePending ?></span><?php endif; ?></button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#decided" data-hash="decided" type="button"><i class="bi bi-clock-history"></i> ประวัติการพิจารณา</button></li>
    <?php endif; ?>
  </ul>
  <div class="flex-fill"></div>
  <a href="<?= Url::to('/rooms') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar3"></i> ปฏิทินห้องประชุม</a>
  <button type="button" class="btn btn-sm btn-primary" data-open-url="/rooms/bookings/new"><i class="bi bi-plus-lg"></i> ขอใช้ห้องประชุม</button>
</div>

<div class="tab-content">
  <div class="tab-pane fade show active" id="mine">
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden">
      <?= $list($mine, false, 'ยังไม่มีคำขอใช้ห้องประชุม') ?>
    </div>
  </div>
  <?php if ($isApprover): ?>
    <div class="tab-pane fade" id="pending">
      <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden">
        <?= $list($pending, true, 'ไม่มีคำขอที่รออนุมัติ') ?>
      </div>
    </div>
    <div class="tab-pane fade" id="decided">
      <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden">
        <?= $list($decided, true, 'ยังไม่มีประวัติการพิจารณา') ?>
      </div>
    </div>
  <?php endif; ?>
</div>
