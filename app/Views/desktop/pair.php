<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
/** @var array{challenge:string,state:string,port:int,device_name:string} $pair */
$cancelUrl = 'http://127.0.0.1:' . (int) $pair['port'] . '/callback?' . http_build_query(['error' => 'access_denied', 'state' => $pair['state']]);
?>
<div style="max-width:520px">
  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:1rem;margin-bottom:6px"><i class="bi bi-window-desktop"></i> เชื่อมต่อแอปแจ้งเตือนบน Windows</div>
      <p class="text-body-secondary" style="font-size:.85rem">
        อนุญาตให้อุปกรณ์ <strong><?= View::e($pair['device_name']) ?></strong>
        รับแจ้งเตือนงานของ <strong><?= View::e(Auth::user()['full_name']) ?></strong>
      </p>
      <div class="alert alert-warning py-2" style="font-size:.8rem">
        กดอนุญาตเฉพาะเมื่อคุณเพิ่งเปิดแอปแจ้งเตือนบนเครื่องนี้ด้วยตัวเอง
      </div>
      <form method="post" action="<?= Url::to('/desktop/pair') ?>" class="d-flex gap-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="challenge" value="<?= View::e($pair['challenge']) ?>">
        <input type="hidden" name="state" value="<?= View::e($pair['state']) ?>">
        <input type="hidden" name="port" value="<?= (int) $pair['port'] ?>">
        <input type="hidden" name="device_name" value="<?= View::e($pair['device_name']) ?>">
        <button type="submit" class="btn btn-primary btn-sm">อนุญาต</button>
        <a href="<?= View::e($cancelUrl) ?>" class="btn btn-outline-secondary btn-sm">ยกเลิก</a>
      </form>
    </div>
  </div>
</div>
