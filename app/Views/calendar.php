<?php
use App\Core\Url;
use App\Core\View;
use App\Models\Ticket;
/** @var int $year */
/** @var int $month */
/** @var string $monthLabel */
/** @var int $leadingBlanks */
/** @var int $daysInMonth */
/** @var array<int,array> $byDay */
/** @var string $prevMonth */
/** @var string $nextMonth */
/** @var int|null $todayDay */
/** @var array<int,array{type:string,id:int,name:string}> $userUnits */
/** @var array{type:string,id:int}|null $activeUnit */
/** @var string $unitParam */

$dayNames = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
$totalCells = (int) (ceil(($leadingBlanks + $daysInMonth) / 7) * 7);
$monthTotal = array_sum(array_map('count', $byDay));

$activeUnitName = '';
if ($activeUnit) {
    foreach ($userUnits as $u) {
        if ($u['type'] === $activeUnit['type'] && $u['id'] === $activeUnit['id']) {
            $activeUnitName = $u['name'];
            break;
        }
    }
}
?>
<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent d-flex align-items-center gap-2 flex-wrap" style="padding:14px 18px">
    <div>
      <div style="font-weight:600;font-size:.95rem"><?= View::e($monthLabel) ?></div>
      <div class="text-body-secondary" style="font-size:.76rem">กำหนดส่งงาน <?= $monthTotal ?> รายการในเดือนนี้<?php if ($activeUnitName): ?> · <span style="font-weight:500"><?= View::e($activeUnitName) ?></span><?php endif; ?></div>
    </div>
    <div class="flex-fill"></div>

    <?php if (count($userUnits) > 1): ?>
    <div class="d-flex align-items-center gap-1 flex-wrap" style="font-size:.73rem">
      <span class="text-body-secondary me-1" style="white-space:nowrap">สายงาน:</span>
      <a href="<?= Url::to('/calendar?month=' . $year . '-' . $month) ?>"
         class="badge rounded-pill text-decoration-none <?= $activeUnit === null ? 'text-bg-primary' : 'text-bg-light border' ?>"
         style="font-size:.7rem;font-weight:500;padding:4px 10px">ทั้งหมด</a>
      <?php foreach ($userUnits as $u): ?>
        <?php $isActive = $activeUnit && $activeUnit['type'] === $u['type'] && $activeUnit['id'] === $u['id']; ?>
        <a href="<?= Url::to('/calendar?month=' . $year . '-' . $month . '&unit=' . $u['type'] . ':' . $u['id']) ?>"
           class="badge rounded-pill text-decoration-none <?= $isActive ? 'text-bg-primary' : 'text-bg-light border' ?>"
           style="font-size:.7rem;font-weight:500;padding:4px 10px"><?= View::e($u['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="d-flex align-items-center gap-2 flex-wrap" style="font-size:.74rem">
      <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#1d4ed8;vertical-align:middle"></span> งานที่ได้รับมอบหมาย</span>
      <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#94a3b8;vertical-align:middle"></span> งานที่ฉันมอบหมาย</span>
    </div>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary" href="<?= Url::to('/calendar?month=' . $prevMonth . $unitParam) ?>"><i class="bi bi-chevron-left"></i></a>
      <a class="btn btn-outline-secondary" href="<?= Url::to('/calendar' . ($unitParam ? '?' . ltrim($unitParam, '&') : '')) ?>">เดือนนี้</a>
      <a class="btn btn-outline-secondary" href="<?= Url::to('/calendar?month=' . $nextMonth . $unitParam) ?>"><i class="bi bi-chevron-right"></i></a>
    </div>
  </div>

  <div class="card-body" style="overflow-x:auto">
    <div style="display:grid;grid-template-columns:repeat(7,minmax(104px,1fr));gap:6px;min-width:760px">
      <?php foreach ($dayNames as $i => $dn): ?>
        <div class="text-body-secondary" style="font-size:.74rem;font-weight:600;text-align:center;padding-bottom:2px;<?= $i === 0 || $i === 6 ? 'color:#dc2626' : '' ?>"><?= $dn ?></div>
      <?php endforeach; ?>

      <?php for ($cell = 0; $cell < $totalCells; $cell++): ?>
        <?php
          $day = $cell - $leadingBlanks + 1;
          $inMonth = $day >= 1 && $day <= $daysInMonth;
          $items = $inMonth ? ($byDay[$day] ?? []) : [];
          $isToday = $inMonth && $day === $todayDay;
        ?>
        <div style="min-height:96px;border:1px solid <?= $isToday ? '#2563eb' : 'var(--bs-border-color)' ?>;border-radius:9px;padding:5px 6px;background:<?= $inMonth ? 'transparent' : 'var(--bs-tertiary-bg)' ?>">
          <?php if ($inMonth): ?>
            <div style="font-size:.76rem;font-weight:<?= $isToday ? '700' : '500' ?>;color:<?= $isToday ? '#2563eb' : 'inherit' ?>;margin-bottom:3px"><?= $day ?></div>
            <div class="d-flex flex-column gap-1">
              <?php foreach ($items as $t): ?>
                <?php [$stLabel, $stBg, $stFg] = Ticket::STATUS[$t['status']]; ?>
                <button type="button" data-open-ticket="<?= (int) $t['id'] ?>"
                        title="<?= View::e($t['code'] . ' · ' . $t['title'] . ' · ' . $stLabel . ' · ' . ($t['isMine'] ? 'ผู้รับผิดชอบ: ฉัน' : 'มอบหมายให้ ' . $t['to_name'])) ?>"
                        style="border:0;text-align:left;border-radius:6px;padding:3px 5px;font-size:.7rem;line-height:1.3;cursor:pointer;background:<?= $stBg ?>;color:<?= $stFg ?>;overflow:hidden">
                  <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= $t['isMine'] ? '#1d4ed8' : '#94a3b8' ?>;vertical-align:middle;margin-right:3px"></span>
                  <?= date('H:i', strtotime($t['due_at'])) ?>
                  <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500"><?= View::e($t['title']) ?></div>
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>
