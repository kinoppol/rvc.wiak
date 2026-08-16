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
/** @var string|null $show */
/** @var string $showParam */
/** @var string $dateMode */
/** @var string $dateParam */

$dayNames = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
$totalCells = (int) (ceil(($leadingBlanks + $daysInMonth) / 7) * 7);
$monthTotal = array_sum(array_map('count', $byDay));
$curMonthSlug = $year . '-' . $month;
// Persistent query params that travel with every link on this page.
$persistParams = $unitParam . $showParam . $dateParam;

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
      <div class="text-body-secondary" style="font-size:.76rem"><?= $dateMode === 'created' ? 'งานมอบหมาย' : 'กำหนดส่งงาน' ?> <?= $monthTotal ?> รายการในเดือนนี้<?php if ($activeUnitName): ?> · <span style="font-weight:500"><?= View::e($activeUnitName) ?></span><?php endif; ?></div>
    </div>
    <div class="flex-fill"></div>

    <?php if (count($userUnits) > 1): ?>
    <div class="d-flex align-items-center gap-1 flex-wrap" style="font-size:.73rem">
      <span class="text-body-secondary me-1" style="white-space:nowrap">สายงาน:</span>
      <a href="<?= Url::to('/calendar?month=' . $curMonthSlug . $showParam . $dateParam) ?>"
         class="badge rounded-pill text-decoration-none <?= $activeUnit === null ? 'text-bg-primary' : 'text-bg-light border' ?>"
         style="font-size:.7rem;font-weight:500;padding:4px 10px">ทั้งหมด</a>
      <?php foreach ($userUnits as $u): ?>
        <?php $isActive = $activeUnit && $activeUnit['type'] === $u['type'] && $activeUnit['id'] === $u['id']; ?>
        <a href="<?= Url::to('/calendar?month=' . $curMonthSlug . '&unit=' . $u['type'] . ':' . $u['id'] . $showParam . $dateParam) ?>"
           class="badge rounded-pill text-decoration-none <?= $isActive ? 'text-bg-primary' : 'text-bg-light border' ?>"
           style="font-size:.7rem;font-weight:500;padding:4px 10px"><?= View::e($u['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="d-flex align-items-center gap-1 flex-wrap" style="font-size:.74rem">
      <?php
        // Clicking an active show-pill deactivates it (toggle off); clicking inactive activates.
        $mineHref  = Url::to('/calendar?month=' . $curMonthSlug . $unitParam . ($show === 'mine'  ? '' : '&show=mine')  . $dateParam);
        $byMeHref  = Url::to('/calendar?month=' . $curMonthSlug . $unitParam . ($show === 'by_me' ? '' : '&show=by_me') . $dateParam);
      ?>
      <a href="<?= $mineHref ?>" class="text-decoration-none d-flex align-items-center gap-1"
         style="border-radius:20px;padding:3px 9px;font-size:.73rem;font-weight:<?= $show === 'mine' ? '600' : '500' ?>;border:1.5px solid <?= $show === 'mine' ? '#1d4ed8' : 'var(--bs-border-color)' ?>;background:<?= $show === 'mine' ? 'rgba(29,78,216,.1)' : 'transparent' ?>;color:<?= $show === 'mine' ? '#1d4ed8' : 'inherit' ?>">
        <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#1d4ed8;flex-shrink:0"></span>
        งานที่ได้รับมอบหมาย
      </a>
      <a href="<?= $byMeHref ?>" class="text-decoration-none d-flex align-items-center gap-1"
         style="border-radius:20px;padding:3px 9px;font-size:.73rem;font-weight:<?= $show === 'by_me' ? '600' : '500' ?>;border:1.5px solid <?= $show === 'by_me' ? '#64748b' : 'var(--bs-border-color)' ?>;background:<?= $show === 'by_me' ? 'rgba(100,116,139,.12)' : 'transparent' ?>;color:<?= $show === 'by_me' ? '#475569' : 'inherit' ?>">
        <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#94a3b8;flex-shrink:0"></span>
        งานที่ฉันมอบหมาย
      </a>
    </div>
    <div class="btn-group btn-group-sm me-1">
      <?php
        $dueModeHref     = Url::to('/calendar?month=' . $curMonthSlug . $unitParam . $showParam);
        $createdModeHref = Url::to('/calendar?month=' . $curMonthSlug . $unitParam . $showParam . '&date=created');
      ?>
      <a class="btn btn-sm <?= $dateMode === 'due'     ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= $dueModeHref ?>">กำหนดส่ง</a>
      <a class="btn btn-sm <?= $dateMode === 'created' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= $createdModeHref ?>">วันมอบหมาย</a>
    </div>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary" href="<?= Url::to('/calendar?month=' . $prevMonth . $persistParams) ?>"><i class="bi bi-chevron-left"></i></a>
      <a class="btn btn-outline-secondary" href="<?= Url::to('/calendar' . ($persistParams ? '?' . ltrim($persistParams, '&') : '')) ?>">เดือนนี้</a>
      <a class="btn btn-outline-secondary" href="<?= Url::to('/calendar?month=' . $nextMonth . $persistParams) ?>"><i class="bi bi-chevron-right"></i></a>
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
                  <?= date('H:i', strtotime($t['_displayDate'])) ?>
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
