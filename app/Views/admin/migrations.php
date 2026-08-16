<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Migration;
/** @var array $pending */
/** @var array $history */
/** @var int $ranCount */
/** @var string $runError */
?>
<div class="d-flex flex-column gap-3" style="max-width:900px">

  <?php if ($ranCount > 0): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> รัน migration สำเร็จ <?= (int) $ranCount ?> ไฟล์</div>
  <?php endif; ?>
  <?php if ($runError): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> รัน migration ไม่สำเร็จ: <?= View::e($runError) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-database-gear"></i> Migration ที่รอดำเนินการ</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        ไฟล์ในโฟลเดอร์ <code>database/migrations/</code> ที่ยังไม่ถูกรันบนฐานข้อมูลนี้ — เรียงตามชื่อไฟล์ (แนะนำให้ตั้งชื่อขึ้นต้นด้วยวันที่ เช่น <code>20260816_add_x.sql</code>) แต่ละไฟล์ควรเขียนให้รันซ้ำได้อย่างปลอดภัย (<code>IF NOT EXISTS</code>) เช่นเดียวกับ <code>database/schema.sql</code> เพราะถ้ารันแล้วมีคำสั่งใดล้มเหลว ไฟล์นั้นจะไม่ถูกบันทึกว่าสำเร็จ และการรันซ้ำจะเริ่มจากคำสั่งแรกใหม่ทั้งหมด
      </div>

      <?php if (!$pending): ?>
        <div class="text-body-secondary" style="font-size:.82rem"><i class="bi bi-check2-circle"></i> ไม่มี migration ค้างอยู่ ฐานข้อมูลเป็นปัจจุบันแล้ว</div>
      <?php else: ?>
        <div class="list-group mb-3">
          <?php foreach ($pending as $f): ?>
            <div class="list-group-item d-flex align-items-center gap-2" style="font-size:.82rem">
              <i class="bi bi-file-earmark-code text-warning"></i>
              <code class="flex-fill"><?= View::e($f) ?></code>
              <form method="post" action="<?= Url::to('/admin/migrations/run') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="filename" value="<?= View::e($f) ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary"
                        onclick="return confirm('รัน migration &quot;<?= View::e(addslashes($f)) ?>&quot; บนฐานข้อมูลจริงหรือไม่?')">
                  <i class="bi bi-play-fill"></i> รัน
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="post" action="<?= Url::to('/admin/migrations/run') ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="filename" value="all">
          <button type="submit" class="btn btn-sm btn-primary"
                  onclick="return confirm('รัน migration ที่ค้างอยู่ทั้งหมด (<?= count($pending) ?> ไฟล์) ตามลำดับหรือไม่?')">
            <i class="bi bi-play-circle"></i> รันทั้งหมด (<?= count($pending) ?>)
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-header bg-transparent" style="font-weight:600;font-size:.95rem;padding:14px 18px">ประวัติการรัน Migration</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr style="font-size:.75rem">
            <th style="padding-left:18px">ไฟล์</th>
            <th>รันเมื่อ</th>
            <th>รันโดย</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$history): ?><tr><td colspan="3" class="text-center text-body-secondary py-4">ยังไม่เคยรัน migration ใด ๆ</td></tr><?php endif; ?>
          <?php foreach ($history as $h): ?>
            <tr style="font-size:.8rem">
              <td style="padding-left:18px"><code><?= View::e($h['filename']) ?></code></td>
              <td style="white-space:nowrap"><?= date('d/m/Y H:i:s', strtotime($h['applied_at'])) ?></td>
              <td><?= View::e($h['applied_by_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
