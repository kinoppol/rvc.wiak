<?php
use App\Core\Url;
use App\Core\View;
/** @var array $people */
/** @var int $total */
/** @var int $page */
/** @var int $lastPage */
/** @var string $q */
/** @var string $role */
/** @var array $roles keyed by code */
?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px">
  <div class="card-body">
    <form method="get" action="<?= Url::to('/admin/users') ?>" class="d-flex gap-2 flex-wrap align-items-end">
      <div>
        <label class="form-label" style="font-size:.8rem">ค้นหา</label>
        <input type="text" name="q" class="form-control form-control-sm" style="min-width:220px" placeholder="ชื่อ / ชื่อผู้ใช้ / อีเมล" value="<?= View::e($q) ?>">
      </div>
      <div>
        <label class="form-label" style="font-size:.8rem">บทบาท</label>
        <select name="role" class="form-select form-select-sm" style="min-width:180px">
          <option value="">ทุกบทบาท</option>
          <?php foreach ($roles as $code => $r): ?>
            <option value="<?= View::e($code) ?>" <?= $role === $code ? 'selected' : '' ?>><?= View::e($r['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> ค้นหา</button>
      <?php if ($q !== '' || $role !== ''): ?>
        <a href="<?= Url::to('/admin/users') ?>" class="btn btn-sm btn-outline-secondary">ล้างตัวกรอง</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px">
  <div class="card-header bg-transparent" style="padding:14px 18px">
    <div style="font-weight:600;font-size:.95rem">รายชื่อผู้ใช้ (พบ <?= (int) $total ?> รายการ)</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr style="font-size:.75rem">
          <th style="padding-left:18px">ชื่อ-นามสกุล</th>
          <th>ชื่อผู้ใช้</th>
          <th>อีเมล</th>
          <th>บทบาท</th>
          <th>สถานะ</th>
          <th style="padding-right:18px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$people): ?>
          <tr><td colspan="6" class="text-center text-body-secondary py-4">ไม่พบผู้ใช้ที่ตรงกับเงื่อนไข</td></tr>
        <?php endif; ?>
        <?php foreach ($people as $p): ?>
          <tr>
            <td style="padding-left:18px">
              <div class="d-flex align-items-center gap-2">
                <div style="width:32px;height:32px;border-radius:50%;background:rgba(30,58,138,.12);color:#1e3a8a;display:grid;place-items:center;flex:0 0 auto"><i class="bi bi-<?= View::e($p['icon']) ?>"></i></div>
                <div style="font-size:.85rem;font-weight:500"><?= View::e($p['full_name']) ?></div>
              </div>
            </td>
            <td style="font-family:ui-monospace,monospace;font-size:.8rem"><?= View::e($p['username']) ?></td>
            <td style="font-size:.8rem"><?= View::e($p['email'] ?? '—') ?></td>
            <td>
              <?php foreach ($p['roles'] as $rc): $r = $roles[$rc] ?? null; if (!$r) { continue; } ?>
                <span class="badge rounded-pill" style="background:<?= View::e($r['chip_bg']) ?>;color:<?= View::e($r['chip_fg']) ?>;font-weight:500;font-size:.66rem;margin-right:2px"><i class="bi bi-<?= View::e($r['icon']) ?>"></i> <?= View::e($r['short_label']) ?></span>
              <?php endforeach; ?>
            </td>
            <td>
              <?php if ($p['is_active']): ?>
                <span class="badge rounded-pill text-bg-success" style="font-weight:500">ใช้งานอยู่</span>
              <?php else: ?>
                <span class="badge rounded-pill text-bg-secondary" style="font-weight:500">ปิดใช้งาน</span>
              <?php endif; ?>
            </td>
            <td style="padding-right:18px;text-align:right">
              <?php if (!$p['isSelf']): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" data-impersonate-user="<?= (int) $p['id'] ?>"><i class="bi bi-person-badge"></i> สวมสิทธิ์</button>
              <?php else: ?>
                <span class="text-body-secondary" style="font-size:.75rem">คุณ</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($lastPage > 1): ?>
    <div class="card-footer bg-transparent d-flex align-items-center justify-content-between flex-wrap gap-2" style="padding:12px 18px">
      <div class="text-body-secondary" style="font-size:.78rem">หน้า <?= (int) $page ?> จาก <?= (int) $lastPage ?></div>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php
            $qs = fn (int $p) => '?' . http_build_query(['q' => $q, 'role' => $role, 'page' => $p]);
          ?>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= Url::to('/admin/users' . $qs($page - 1)) ?>">ก่อนหน้า</a>
          </li>
          <?php for ($i = max(1, $page - 2); $i <= min($lastPage, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= Url::to('/admin/users' . $qs($i)) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= Url::to('/admin/users' . $qs($page + 1)) ?>">ถัดไป</a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>
