<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\OrgUnit;
/** @var array $divisions */
/** @var array $works */
/** @var array $departments */
/** @var bool $deptHasDivision */
/** @var array $counts  type => [unit id => people] */
/** @var string $notice */
/** @var string $error */

// Group งาน / แผนก under their ฝ่าย (0 = no ฝ่าย yet).
$byDiv = [];
foreach ($works as $w) {
    $byDiv[(int) ($w['division_id'] ?? 0)]['work'][] = $w;
}
foreach ($departments as $d) {
    $byDiv[$deptHasDivision ? (int) ($d['division_id'] ?? 0) : 0]['department'][] = $d;
}

$divisionSelect = function (?int $selected, string $attrs = '') use ($divisions): void { ?>
  <select name="division_id" class="form-select form-select-sm" <?= $attrs ?>>
    <option value="">— ไม่ระบุฝ่าย —</option>
    <?php foreach ($divisions as $d): ?>
      <option value="<?= (int) $d['id'] ?>" <?= $selected === (int) $d['id'] ? 'selected' : '' ?>><?= View::e($d['name']) ?></option>
    <?php endforeach; ?>
  </select>
<?php };

/** One compact line per unit; the pencil swaps it for an inline edit form. */
$row = function (string $type, array $u) use ($counts, $divisionSelect): void {
    $id = (int) $u['id'];
    $people = $counts[$type][$id] ?? 0;
    $icon = ['work' => 'bi-briefcase', 'department' => 'bi-buildings'][$type];
    ?>
    <div class="org-row" id="u-<?= $type ?>-<?= $id ?>" data-org-row data-org-name="<?= View::e(mb_strtolower($u['name'])) ?>">
      <div class="org-row-view">
        <i class="bi <?= $icon ?> org-row-icon"></i>
        <span class="org-row-name"><?= View::e($u['name']) ?></span>
        <span class="org-people<?= $people ? '' : ' is-empty' ?>" title="ผู้ที่มีบทบาทใน<?= OrgUnit::label($type) ?>นี้"><i class="bi bi-people"></i> <?= $people ?></span>
        <button type="button" class="btn btn-sm btn-link org-act" data-org-edit title="แก้ไข"><i class="bi bi-pencil"></i></button>
        <form method="post" action="<?= Url::to('/admin/org/' . $type . '/' . $id . '/delete') ?>" class="d-inline"
              onsubmit="return confirm('ยืนยันการลบ <?= View::e(addslashes($u['name'])) ?> ?')">
          <?= Csrf::field() ?>
          <button type="submit" class="btn btn-sm btn-link org-act text-danger" title="ลบ"<?= $people ? ' disabled' : '' ?>><i class="bi bi-trash"></i></button>
        </form>
      </div>
      <form method="post" action="<?= Url::to('/admin/org/' . $type . '/' . $id) ?>" class="org-row-edit" hidden>
        <?= Csrf::field() ?>
        <input type="text" name="name" class="form-control form-control-sm" value="<?= View::e($u['name']) ?>" required>
        <?php if (OrgUnit::hasDivision($type)) { $divisionSelect(isset($u['division_id']) ? (int) $u['division_id'] : null); } ?>
        <button type="submit" class="btn btn-sm btn-primary" title="บันทึก"><i class="bi bi-check2"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-org-edit title="ยกเลิก"><i class="bi bi-x-lg"></i></button>
      </form>
    </div>
    <?php
};

/** The งาน and แผนก columns inside one ฝ่าย card. */
$body = function (array $group) use ($row): void { ?>
  <div class="org-cols">
    <?php foreach (['work' => 'งาน', 'department' => 'แผนก'] as $type => $label): $list = $group[$type] ?? []; ?>
      <div class="org-col">
        <div class="org-col-head"><?= $label ?> <span><?= count($list) ?></span></div>
        <?php if (!$list): ?><div class="org-empty">—</div><?php endif; ?>
        <?php foreach ($list as $u) { $row($type, $u); } ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php };

$total = ['division' => count($divisions), 'work' => count($works), 'department' => count($departments)];
?>
<?php if ($notice): ?>
  <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> <?= View::e($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= View::e($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm org-toolbar mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="org-search flex-fill">
        <i class="bi bi-search"></i>
        <input type="search" class="form-control form-control-sm" placeholder="ค้นหาฝ่าย / งาน / แผนก…" data-org-filter>
      </div>
      <div class="org-totals">
        <span><b><?= $total['division'] ?></b> ฝ่าย</span>
        <span><b><?= $total['work'] ?></b> งาน</span>
        <span><b><?= $total['department'] ?></b> แผนก</span>
      </div>
      <a href="<?= Url::to('/admin/org-import') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-cloud-download"></i> นำเข้าจาก RMS</a>
    </div>

    <form method="post" action="<?= Url::to('/admin/org') ?>" class="org-add" data-org-add<?= $deptHasDivision ? '' : ' data-dept-no-division' ?>>
      <?= Csrf::field() ?>
      <select name="type" class="form-select form-select-sm" data-org-add-type aria-label="ประเภท">
        <option value="work">งาน</option>
        <option value="department">แผนก</option>
        <option value="division">ฝ่าย</option>
      </select>
      <input type="text" name="name" class="form-control form-control-sm" placeholder="ชื่อหน่วยงานใหม่" required>
      <?php $divisionSelect(null, 'data-org-add-division aria-label="สังกัดฝ่าย"'); ?>
      <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
    </form>
    <?php if (!$deptHasDivision): ?>
      <div class="form-text mt-1"><i class="bi bi-info-circle"></i> ยังผูกแผนกกับฝ่ายไม่ได้ จนกว่าจะรัน migration <code>20261118_department_division.sql</code> ที่หน้า <a href="<?= Url::to('/admin/migrations') ?>">Migrations</a></div>
    <?php endif; ?>
  </div>
</div>

<div class="d-flex flex-column gap-3" data-org-groups>
  <?php foreach ($divisions as $d):
    $did = (int) $d['id'];
    $g = $byDiv[$did] ?? [];
    $people = $counts['division'][$did] ?? 0; ?>
    <details class="card border-0 shadow-sm org-group" id="u-division-<?= $did ?>"<?= $g ? ' open' : '' ?> data-org-group data-org-name="<?= View::e(mb_strtolower($d['name'])) ?>">
      <summary class="org-group-head">
        <i class="bi bi-chevron-right org-caret"></i>
        <i class="bi bi-diagram-2 org-group-icon"></i>
        <span class="org-group-name"><?= View::e($d['name']) ?></span>
        <span class="org-group-meta"><?= count($g['work'] ?? []) ?> งาน · <?= count($g['department'] ?? []) ?> แผนก</span>
        <span class="org-people<?= $people ? '' : ' is-empty' ?>" title="รองผู้อำนวยการในฝ่ายนี้"><i class="bi bi-person-badge"></i> <?= $people ?></span>
        <span class="ms-auto"></span>
        <button type="button" class="btn btn-sm btn-link org-act" data-org-edit-group title="แก้ไขชื่อฝ่าย"><i class="bi bi-pencil"></i></button>
        <form method="post" action="<?= Url::to('/admin/org/division/' . $did . '/delete') ?>" class="d-inline"
              onsubmit="return confirm('ยืนยันการลบฝ่าย <?= View::e(addslashes($d['name'])) ?> ? (งาน/แผนกในฝ่ายนี้จะกลายเป็นไม่ระบุฝ่าย)')">
          <?= Csrf::field() ?>
          <button type="submit" class="btn btn-sm btn-link org-act text-danger" title="ลบ"<?= $people ? ' disabled' : '' ?>><i class="bi bi-trash"></i></button>
        </form>
      </summary>
      <form method="post" action="<?= Url::to('/admin/org/division/' . $did) ?>" class="org-group-edit" hidden>
        <?= Csrf::field() ?>
        <input type="text" name="name" class="form-control form-control-sm" value="<?= View::e($d['name']) ?>" required>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> บันทึก</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-org-edit-group>ยกเลิก</button>
      </form>
      <?php if ($g) { $body($g); } else { ?><div class="org-empty org-cols">ยังไม่มีงานหรือแผนกในฝ่ายนี้ — เพิ่มด้านบนแล้วเลือกฝ่ายนี้</div><?php } ?>
    </details>
  <?php endforeach; ?>

  <?php if (!empty($byDiv[0])): ?>
    <details class="card border-0 shadow-sm org-group org-group-loose" open data-org-group data-org-name="">
      <summary class="org-group-head">
        <i class="bi bi-chevron-right org-caret"></i>
        <i class="bi bi-question-circle org-group-icon"></i>
        <span class="org-group-name">ยังไม่ระบุฝ่าย</span>
        <span class="org-group-meta"><?= count($byDiv[0]['work'] ?? []) ?> งาน · <?= count($byDiv[0]['department'] ?? []) ?> แผนก — กดดินสอเพื่อเลือกฝ่าย</span>
      </summary>
      <?php $body($byDiv[0]); ?>
    </details>
  <?php endif; ?>

  <?php if (!$divisions && !$works && !$departments): ?>
    <div class="text-center text-body-secondary py-5">ยังไม่มีข้อมูลหน่วยงาน — เพิ่มด้านบน หรือ <a href="<?= Url::to('/admin/org-import') ?>">นำเข้าจาก RMS</a></div>
  <?php endif; ?>
  <div class="text-center text-body-secondary py-4" data-org-nohit hidden>ไม่พบหน่วยงานที่ตรงกับคำค้น</div>
</div>
