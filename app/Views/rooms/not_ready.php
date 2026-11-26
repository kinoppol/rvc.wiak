<?php
use App\Core\Url;
?>
<div class="card border-0 shadow-sm text-center" style="border-radius:14px;padding:48px 24px">
  <i class="bi bi-database-exclamation" style="font-size:2.4rem;color:#ca8a04"></i>
  <div class="mt-2" style="font-weight:600">ระบบจองห้องประชุมยังไม่พร้อมใช้งาน</div>
  <div class="text-body-secondary" style="font-size:.84rem">
    ผู้ดูแลระบบต้องรัน migration <code>20261126_add_meeting_rooms.sql</code> ที่หน้า
    <a href="<?= Url::to('/admin/migrations') ?>">Migration ฐานข้อมูล</a> ก่อน
  </div>
</div>
