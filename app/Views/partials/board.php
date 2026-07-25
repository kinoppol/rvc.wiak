<?php
use App\Core\View;
use App\Models\Ticket;
/** @var array $rows */
/** @var array $stats */

$statCards = [
    ['label' => 'ตั๋วงานทั้งหมด', 'value' => $stats['total'], 'hint' => 'ปีการศึกษา ' . ((int) date('Y') + 543), 'icon' => 'ticket-detailed-fill', 'bg' => 'rgba(30,58,138,.12)', 'fg' => '#1e3a8a'],
    ['label' => 'กำลังดำเนินงาน', 'value' => $stats['doing'], 'hint' => 'เกินกำหนด ' . $stats['overdue'] . ' รายการ', 'icon' => 'hourglass-split', 'bg' => 'rgba(217,119,6,.14)', 'fg' => '#b45309'],
    ['label' => 'เสร็จสิ้น / อนุมัติแล้ว', 'value' => $stats['done'], 'hint' => 'จากทั้งหมด ' . $stats['total'] . ' รายการ', 'icon' => 'check2-circle', 'bg' => 'rgba(16,185,129,.14)', 'fg' => '#047857'],
    ['label' => 'งานที่สั่งข้ามขั้น', 'value' => $stats['cross'], 'hint' => $stats['total'] > 0 ? round($stats['cross'] / $stats['total'] * 100, 1) . '% ของงานทั้งหมด' : '—', 'icon' => 'diagram-3-fill', 'bg' => 'rgba(14,165,233,.14)', 'fg' => '#0369a1'],
];
?>
<div class="row g-3 mb-3">
  <?php foreach ($statCards as $s): ?>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;overflow:hidden">
        <div class="card-body d-flex gap-3 align-items-start">
          <div style="width:44px;height:44px;border-radius:12px;background:<?= $s['bg'] ?>;color:<?= $s['fg'] ?>;display:grid;place-items:center;font-size:1.15rem"><i class="bi bi-<?= $s['icon'] ?>"></i></div>
          <div class="min-w-0">
            <div class="text-body-secondary" style="font-size:.76rem"><?= $s['label'] ?></div>
            <div style="font-size:1.6rem;font-weight:600;line-height:1.2"><?= (int) $s['value'] ?></div>
            <div class="text-body-secondary" style="font-size:.72rem"><?= $s['hint'] ?></div>
          </div>
        </div>
        <div style="height:4px;background:<?= $s['fg'] ?>"></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent" style="padding:14px 18px">
    <div style="font-weight:600;font-size:.95rem">รายการตั๋วงานล่าสุด (<?= count($rows) ?>)</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr style="font-size:.75rem">
          <th style="padding-left:18px">รหัสตั๋ว</th>
          <th>หัวข้องาน</th>
          <th>ผู้มอบหมาย → ผู้รับมอบหมาย</th>
          <th>ความสำคัญ</th>
          <th>สถานะ</th>
          <th>เวลาที่ใช้ไป</th>
          <th style="padding-right:18px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-body-secondary py-4">ไม่พบตั๋วงาน</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $t): [$stLabel, $stBg, $stFg] = Ticket::STATUS[$t['status']]; [$priLabel, $priBg, $priFg] = Ticket::PRIORITY[$t['priority']]; ?>
          <tr class="row-hover" data-open-ticket="<?= (int) $t['id'] ?>">
            <td style="padding-left:18px;font-family:ui-monospace,monospace;font-size:.78rem;white-space:nowrap"><?= View::e($t['code']) ?></td>
            <td>
              <div style="font-weight:500;font-size:.85rem"><?= View::e($t['title']) ?></div>
              <div class="text-body-secondary" style="font-size:.72rem"><?= View::e($t['meta'] ?? '') ?></div>
            </td>
            <td style="font-size:.78rem;white-space:nowrap">
              <div><?= View::e($t['from_name']) ?></div>
              <div class="text-body-secondary"><i class="bi bi-arrow-return-right"></i> <?= View::e($t['to_name']) ?></div>
            </td>
            <td><span class="badge" style="background:<?= $priBg ?>;color:<?= $priFg ?>;font-weight:500"><?= $priLabel ?></span></td>
            <td>
              <span class="badge rounded-pill" style="background:<?= $stBg ?>;color:<?= $stFg ?>;font-weight:500"><?= $stLabel ?></span>
              <?php if ($t['is_cross']): ?>
                <span class="badge rounded-pill" style="background:rgba(217,119,6,.15);color:#b45309;font-weight:500;margin-left:4px" title="สั่งงานข้ามขั้น"><i class="bi bi-diagram-3"></i> ข้ามขั้น</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap"><?= Ticket::elapsedLabel($t) ?></td>
            <td style="padding-right:18px;text-align:right"><button type="button" class="btn btn-sm btn-outline-primary" data-open-ticket="<?= (int) $t['id'] ?>">เปิดตั๋ว</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
