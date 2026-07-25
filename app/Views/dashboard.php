<?php
use App\Core\View;
/** @var string $filter */
/** @var string $q */
$filterOptions = [
    ['all', 'ทั้งหมด'],
    ['open', 'ยังไม่เสร็จ'],
    ['wait', 'รอฉันดำเนินการ'],
    ['done', 'ปิดงานแล้ว'],
    ['cross', 'สั่งข้ามขั้น'],
];
?>
<form id="board-filters">
  <input type="hidden" id="board-filter-input" name="filter" value="<?= View::e($filter) ?>">
  <div class="card border-0 shadow-sm mb-3" style="border-radius:14px">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <div class="btn-group btn-group-sm flex-wrap">
        <?php foreach ($filterOptions as [$id, $label]): ?>
          <button type="button" class="btn <?= $filter === $id ? 'btn-primary' : 'btn-outline-secondary' ?>" data-board-filter="<?= $id ?>"><?= $label ?></button>
        <?php endforeach; ?>
      </div>
      <div class="input-group input-group-sm" style="max-width:280px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="board-query" name="q" class="form-control" placeholder="ค้นหารหัสตั๋ว / หัวข้องาน" value="<?= View::e($q) ?>">
      </div>
      <div class="flex-fill"></div>
      <button type="button" class="btn btn-sm btn-primary" data-open-new-ticket><i class="bi bi-plus-lg"></i> สร้างตั๋วงานใหม่</button>
    </div>
  </div>
</form>

<div id="board-root">
  <?= View::render('partials/board', get_defined_vars()) ?>
</div>
