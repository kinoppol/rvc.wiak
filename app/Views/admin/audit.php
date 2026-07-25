<?php
use App\Core\View;
/** @var array $logs */
?>
<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent" style="font-weight:600;font-size:.95rem;padding:14px 18px">Audit Log (ล่าสุด <?= count($logs) ?> รายการ)</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr style="font-size:.75rem">
          <th style="padding-left:18px">เวลา</th>
          <th>ผู้กระทำ</th>
          <th>สวมสิทธิ์เป็น</th>
          <th>การกระทำ</th>
          <th>เป้าหมาย</th>
          <th>รายละเอียด</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?><tr><td colspan="7" class="text-center text-body-secondary py-4">ยังไม่มีรายการ</td></tr><?php endif; ?>
        <?php foreach ($logs as $l): ?>
          <tr style="font-size:.8rem">
            <td style="padding-left:18px;white-space:nowrap"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
            <td><?= View::e($l['actor_name']) ?></td>
            <td><?= View::e($l['acting_as_name'] ?? '—') ?></td>
            <td><code><?= View::e($l['action']) ?></code></td>
            <td><?= View::e(($l['entity_type'] ?? '') . ($l['entity_id'] ? ' #' . $l['entity_id'] : '')) ?></td>
            <td><?= View::e($l['details'] ?? '') ?></td>
            <td><?= View::e($l['ip'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
