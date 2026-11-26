<?php
use App\Core\Url;
use App\Core\View;
use App\Models\RoomBooking;
/** @var string $mode month|week (not $view: that name is View::render's own parameter) */
/** @var string $label */
/** @var DateTimeImmutable $from */
/** @var DateTimeImmutable $to */
/** @var DateTimeImmutable $anchor */
/** @var int|null $monthOf */
/** @var string $prevDate */
/** @var string $nextDate */
/** @var array<int,array> $rooms */
/** @var int $roomId */
/** @var array<string,array> $byDay */
/** @var int $minHour */
/** @var int $maxHour */
/** @var int $userId */
/** @var bool $isApprover */

$dayNames = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];
$today = date('Y-m-d');
$nowHour = (int) date('G');
$roomParam = $roomId ? '&room=' . $roomId : '';
$link = fn(string $v, string $d, int $room = -1) => Url::to('/rooms?view=' . $v . '&date=' . $d . ($room < 0 ? $roomParam : ($room ? '&room=' . $room : '')));
$hourPx = 46;
$total = array_sum(array_map('count', $byDay));

// Room tint used for a booking chip/block. Pending requests get a dashed
// outline and a paler fill so they never read as confirmed.
$chipStyle = function (array $b): string {
    $c = $b['room_color'] ?: '#2563eb';
    $pending = $b['status'] === 'pending';
    return 'background:' . $c . ($pending ? '14' : '26') . ';border-left:3px solid ' . $c . ';'
        . ($pending ? 'outline:1.5px dashed ' . $c . '99;outline-offset:-1.5px;' : '');
};
$newUrl = fn(string $date, ?string $start = null) => '/rooms/bookings/new?date=' . $date
    . ($start ? '&start=' . $start : '') . ($roomId ? '&room=' . $roomId : '');
?>
<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent d-flex align-items-center gap-2 flex-wrap" style="padding:14px 18px">
    <div>
      <div style="font-weight:600;font-size:.95rem"><?= View::e($label) ?></div>
      <div class="text-body-secondary" style="font-size:.76rem">การจอง <?= $total ?> รายการในช่วงนี้</div>
    </div>
    <div class="flex-fill"></div>
    <div class="btn-group btn-group-sm">
      <a class="btn <?= $mode === 'month' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= $link('month', $anchor->format('Y-m-d')) ?>"><i class="bi bi-calendar3"></i> รายเดือน</a>
      <a class="btn <?= $mode === 'week' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= $link('week', $anchor->format('Y-m-d')) ?>"><i class="bi bi-calendar-week"></i> รายสัปดาห์</a>
    </div>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary" href="<?= $link($mode, $prevDate) ?>" title="ก่อนหน้า"><i class="bi bi-chevron-left"></i></a>
      <a class="btn btn-outline-secondary" href="<?= $link($mode, $today) ?>"><?= $mode === 'week' ? 'สัปดาห์นี้' : 'เดือนนี้' ?></a>
      <a class="btn btn-outline-secondary" href="<?= $link($mode, $nextDate) ?>" title="ถัดไป"><i class="bi bi-chevron-right"></i></a>
    </div>
    <button type="button" class="btn btn-sm btn-primary" data-open-url="<?= View::e($newUrl(max($today, $anchor->format('Y-m-d')))) ?>">
      <i class="bi bi-plus-lg"></i> ขอใช้ห้องประชุม
    </button>
  </div>

  <div class="d-flex align-items-center gap-1 flex-wrap" style="padding:10px 18px 0;font-size:.73rem">
    <span class="text-body-secondary me-1">ห้อง:</span>
    <a href="<?= $link($mode, $anchor->format('Y-m-d'), 0) ?>"
       class="badge rounded-pill text-decoration-none <?= $roomId === 0 ? 'text-bg-primary' : 'text-bg-light border' ?>"
       style="font-size:.72rem;font-weight:500;padding:5px 11px">ทุกห้อง</a>
    <?php foreach ($rooms as $r): ?>
      <a href="<?= $link($mode, $anchor->format('Y-m-d'), (int) $r['id']) ?>"
         class="badge rounded-pill text-decoration-none <?= $roomId === (int) $r['id'] ? 'text-bg-primary' : 'text-bg-light border' ?>"
         style="font-size:.72rem;font-weight:500;padding:5px 11px;<?= (int) $r['is_active'] ? '' : 'opacity:.65' ?>"
         <?= (int) $r['is_active'] ? '' : 'title="ปิดใช้งาน' . (!empty($r['inactive_reason']) ? ': ' . View::e($r['inactive_reason']) : '') . '"' ?>>
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= View::e($r['color']) ?>;margin-right:3px"></span><?= View::e($r['name']) ?><?= (int) $r['is_active'] ? '' : ' <i class="bi bi-slash-circle"></i>' ?>
      </a>
    <?php endforeach; ?>
    <div class="flex-fill"></div>
    <span class="text-body-secondary d-flex align-items-center gap-2">
      <span><span style="display:inline-block;width:18px;height:10px;border-radius:3px;background:#2563eb26;border-left:3px solid #2563eb;vertical-align:middle"></span> อนุมัติแล้ว</span>
      <span><span style="display:inline-block;width:18px;height:10px;border-radius:3px;background:#2563eb14;border-left:3px solid #2563eb;outline:1.5px dashed #2563eb99;outline-offset:-1.5px;vertical-align:middle"></span> รออนุมัติ</span>
    </span>
  </div>

  <?php if (!$rooms): ?>
    <div class="card-body text-center text-body-secondary" style="padding:40px">
      <i class="bi bi-door-closed" style="font-size:2rem"></i>
      <div class="mt-2">ยังไม่มีห้องประชุมในระบบ<?= $isApprover ? ' — เพิ่มห้องได้ที่ "จัดการห้องประชุม"' : '' ?></div>
    </div>
  <?php elseif ($mode === 'month'): ?>
  <div class="card-body" style="overflow-x:auto">
    <div style="display:grid;grid-template-columns:repeat(7,minmax(110px,1fr));gap:6px;min-width:780px">
      <?php foreach ($dayNames as $i => $dn): ?>
        <div class="text-body-secondary" style="font-size:.74rem;font-weight:600;text-align:center;<?= $i >= 5 ? 'color:#dc2626' : '' ?>"><?= $dn ?></div>
      <?php endforeach; ?>
      <?php for ($d = $from; $d <= $to; $d = $d->modify('+1 day')): ?>
        <?php
          $key = $d->format('Y-m-d');
          $inMonth = (int) $d->format('n') === $monthOf;
          $items = $byDay[$key] ?? [];
          $isToday = $key === $today;
          $shown = array_slice($items, 0, 4);
        ?>
        <div class="room-day" style="min-height:104px;border:1px solid <?= $isToday ? '#2563eb' : 'var(--bs-border-color)' ?>;border-radius:9px;padding:5px 6px;background:<?= $inMonth ? 'transparent' : 'var(--bs-tertiary-bg)' ?>">
          <div class="d-flex align-items-center" style="margin-bottom:3px">
            <a href="<?= $link('week', $key) ?>" class="text-decoration-none" style="font-size:.76rem;font-weight:<?= $isToday ? '700' : '500' ?>;color:<?= $isToday ? '#2563eb' : ($inMonth ? 'inherit' : 'var(--bs-secondary-color)') ?>" title="ดูรายสัปดาห์"><?= (int) $d->format('j') ?></a>
            <div class="flex-fill"></div>
            <?php if ($key >= $today): ?>
              <button type="button" class="btn btn-link p-0 room-day-add" style="font-size:.8rem;line-height:1" title="ขอใช้ห้องวันนี้"
                      data-open-url="<?= View::e($newUrl($key)) ?>"><i class="bi bi-plus-circle"></i></button>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-column gap-1">
            <?php foreach ($shown as $b): ?>
              <button type="button" data-open-url="/rooms/bookings/<?= (int) $b['id'] ?>"
                      title="<?= View::e($b['room_name'] . ' · ' . RoomBooking::timeLabel($b) . ' · ' . $b['purpose'] . ' · ' . RoomBooking::STATUS[$b['status']][0]) ?>"
                      style="border:0;text-align:left;border-radius:6px;padding:3px 5px;font-size:.69rem;line-height:1.3;cursor:pointer;color:inherit;overflow:hidden;<?= $chipStyle($b) ?>">
                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><b><?= substr($b['start_time'], 0, 5) ?></b> <?= View::e($b['room_name']) ?></div>
                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:.85"><?= View::e($b['purpose']) ?></div>
              </button>
            <?php endforeach; ?>
            <?php if (count($items) > count($shown)): ?>
              <a href="<?= $link('week', $key) ?>" class="text-decoration-none" style="font-size:.68rem">+ อีก <?= count($items) - count($shown) ?> รายการ</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php else: ?>
  <?php
    // Lay out each day's bookings in side-by-side lanes where they overlap.
    $toMin = fn(string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
    $layout = function (array $items) use ($toMin): array {
        usort($items, fn($a, $b) => [$a['start_time'], $a['end_time']] <=> [$b['start_time'], $b['end_time']]);
        $out = [];
        $cluster = [];
        $clusterEnd = -1;
        $laneEnds = [];
        $flush = function () use (&$cluster, &$out, &$laneEnds) {
            $n = count($laneEnds);
            foreach ($cluster as $c) {
                $c['_lanes'] = max(1, $n);
                $out[] = $c;
            }
            $cluster = [];
            $laneEnds = [];
        };
        foreach ($items as $b) {
            $s = $toMin($b['start_time']);
            $e = $toMin($b['end_time']);
            if ($cluster && $s >= $clusterEnd) {
                $flush();
                $clusterEnd = -1;
            }
            $lane = null;
            foreach ($laneEnds as $i => $end) {
                if ($end <= $s) { $lane = $i; break; }
            }
            if ($lane === null) { $lane = count($laneEnds); }
            $laneEnds[$lane] = $e;
            $b['_lane'] = $lane;
            $b['_s'] = $s;
            $b['_e'] = $e;
            $cluster[] = $b;
            $clusterEnd = max($clusterEnd, $e);
        }
        if ($cluster) { $flush(); }
        return $out;
    };
    $gridH = ($maxHour - $minHour) * $hourPx;
  ?>
  <div class="card-body" style="overflow-x:auto">
    <div style="min-width:820px">
      <div style="display:grid;grid-template-columns:52px repeat(7,1fr);gap:0 4px;position:sticky;top:0">
        <div></div>
        <?php for ($i = 0, $d = $from; $i < 7; $i++, $d = $d->modify('+1 day')): ?>
          <?php $isToday = $d->format('Y-m-d') === $today; ?>
          <div style="text-align:center;padding:4px 0 8px;font-size:.76rem;<?= $i >= 5 ? 'color:#dc2626;' : '' ?>">
            <div style="font-weight:600"><?= $dayNames[$i] ?></div>
            <div style="display:inline-block;min-width:28px;padding:1px 6px;border-radius:14px;font-weight:<?= $isToday ? '700' : '500' ?>;<?= $isToday ? 'background:#2563eb;color:#fff' : '' ?>"><?= (int) $d->format('j') ?></div>
          </div>
        <?php endfor; ?>
      </div>
      <div style="display:grid;grid-template-columns:52px repeat(7,1fr);gap:0 4px">
        <div style="position:relative;height:<?= $gridH ?>px">
          <?php for ($h = $minHour; $h < $maxHour; $h++): ?>
            <div class="text-body-secondary" style="position:absolute;top:<?= ($h - $minHour) * $hourPx - 7 ?>px;right:6px;font-size:.68rem"><?= sprintf('%02d:00', $h) ?></div>
          <?php endfor; ?>
        </div>
        <?php for ($i = 0, $d = $from; $i < 7; $i++, $d = $d->modify('+1 day')): ?>
          <?php $key = $d->format('Y-m-d'); ?>
          <div style="position:relative;height:<?= $gridH ?>px;border-left:1px solid var(--bs-border-color);background:<?= $key === $today ? 'rgba(37,99,235,.05)' : 'transparent' ?>">
            <?php for ($h = $minHour; $h < $maxHour; $h++): ?>
              <?php $slotOpen = $key > $today || ($key === $today && $h >= $nowHour); ?>
              <div class="room-slot<?= $slotOpen ? ' open' : '' ?>" style="position:absolute;left:0;right:0;top:<?= ($h - $minHour) * $hourPx ?>px;height:<?= $hourPx ?>px;border-top:1px solid var(--bs-border-color-translucent)"
                   <?php if ($slotOpen): ?>data-open-url="<?= View::e($newUrl($key, sprintf('%02d:00', $h))) ?>" title="ขอใช้ห้อง <?= sprintf('%02d:00', $h) ?> น."<?php endif; ?>></div>
            <?php endfor; ?>
            <?php foreach ($layout($byDay[$key] ?? []) as $b): ?>
              <?php
                $top = ($b['_s'] - $minHour * 60) * $hourPx / 60;
                $height = max(20, ($b['_e'] - $b['_s']) * $hourPx / 60 - 2);
                $w = 100 / $b['_lanes'];
              ?>
              <button type="button" data-open-url="/rooms/bookings/<?= (int) $b['id'] ?>"
                      title="<?= View::e($b['room_name'] . ' · ' . RoomBooking::timeLabel($b) . ' · ' . $b['purpose'] . ' · ' . $b['user_name'] . ' · ' . RoomBooking::STATUS[$b['status']][0]) ?>"
                      style="position:absolute;top:<?= round($top, 1) ?>px;height:<?= round($height, 1) ?>px;left:calc(<?= round($w * $b['_lane'], 3) ?>% + 2px);width:calc(<?= round($w, 3) ?>% - 4px);border:0;border-radius:6px;padding:2px 5px;text-align:left;font-size:.68rem;line-height:1.25;color:inherit;overflow:hidden;cursor:pointer;z-index:2;backdrop-filter:blur(2px);<?= $chipStyle($b) ?>">
                <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= RoomBooking::timeLabel($b) ?><?= $b['status'] === 'pending' ? ' · รออนุมัติ' : '' ?></div>
                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= View::e($b['room_name']) ?></div>
                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:.85"><?= View::e($b['purpose']) ?></div>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <div class="text-body-secondary mt-2" style="font-size:.72rem"><i class="bi bi-hand-index"></i> คลิกช่องว่างในตารางเพื่อขอใช้ห้องในชั่วโมงนั้น</div>
  </div>
  <?php endif; ?>
</div>

<?php if ($rooms): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent d-flex align-items-center gap-2" style="padding:12px 18px">
    <i class="bi bi-door-open" style="color:#1e3a8a"></i>
    <div style="font-weight:600;font-size:.9rem" class="flex-fill">ห้องประชุมและอุปกรณ์สื่อ</div>
    <a href="<?= Url::to('/rooms/requests') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-ul"></i> คำขอของฉัน</a>
    <?php if ($isApprover): ?>
      <a href="<?= Url::to('/rooms/manage') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> จัดการห้อง</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row g-2">
      <?php foreach ($rooms as $r): ?>
        <div class="col-12 col-md-6 col-xl-4">
          <div style="border:1px solid var(--bs-border-color);border-left:4px solid <?= View::e($r['color']) ?>;border-radius:10px;padding:10px 12px;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div style="font-weight:600;font-size:.88rem" class="flex-fill"><?= View::e($r['name']) ?></div>
              <?php if (!(int) $r['is_active']): ?><span class="badge text-bg-secondary" style="font-size:.66rem"><i class="bi bi-slash-circle"></i> ปิดใช้งาน</span><?php endif; ?>
              <?php if ($r['capacity']): ?><span class="badge text-bg-light border" style="font-size:.66rem"><i class="bi bi-people"></i> <?= (int) $r['capacity'] ?> คน</span><?php endif; ?>
            </div>
            <?php if (!(int) $r['is_active']): ?>
              <div class="text-danger" style="font-size:.74rem"><i class="bi bi-info-circle"></i> งดรับคำขอใช้ห้อง<?= !empty($r['inactive_reason']) ? ': ' . View::e($r['inactive_reason']) : '' ?></div>
            <?php endif; ?>
            <?php if ($r['location']): ?><div class="text-body-secondary" style="font-size:.74rem"><i class="bi bi-geo-alt"></i> <?= View::e($r['location']) ?></div><?php endif; ?>
            <div class="d-flex flex-wrap gap-1 mt-2">
              <?php foreach ($r['equipment'] as $e): ?>
                <span class="badge rounded-pill text-bg-light border" style="font-size:.67rem;font-weight:500"><i class="bi bi-<?= View::e($e['icon']) ?>"></i> <?= View::e($e['name']) ?><?= (int) $e['quantity'] > 1 ? ' ×' . (int) $e['quantity'] : '' ?></span>
              <?php endforeach; ?>
              <?php if (!$r['equipment']): ?><span class="text-body-secondary" style="font-size:.72rem">ไม่มีอุปกรณ์สื่อ</span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
