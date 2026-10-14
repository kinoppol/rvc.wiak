<?php
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Url;
use App\Core\View;
/** @var string $baseUrl */
/** @var string|null $lastRunAt */
/** @var array|null $lastSummary */
/** @var bool $urlUpdated */
/** @var bool $synced */
/** @var string $syncError */
/** @var bool $oaEnabled */
/** @var string $oaButtonLabel */
/** @var string $oaButtonLabelDefault */
/** @var string $oaAuthorizeUrl */
/** @var string $oaVerifyUrl */
/** @var string $oaClientId */
/** @var string $oaRedirectUri */
/** @var bool $oaSaved */
/** @var string $oaError */
/** @var string $oaStartUrl */
/** @var string $oaUsersUrl */
/** @var string $oaSyncType */
/** @var bool $oaSecretSet */
/** @var string|null $oaSyncSince */
/** @var string|null $oaSyncLastRunAt */
/** @var array|null $oaSyncSummary */
/** @var bool $oaSynced */
/** @var string $oaSyncError */
?>
<div class="d-flex flex-column gap-3" style="max-width:760px">

  <?php if ($urlUpdated): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> บันทึก URL ของระบบภายนอกแล้ว</div>
  <?php endif; ?>
  <?php if (Request::str('urlError') === '1'): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> URL ไม่ถูกต้อง กรุณาระบุในรูปแบบ http:// หรือ https://</div>
  <?php endif; ?>
  <?php if ($synced): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> ซิงค์ข้อมูลผู้ใช้เรียบร้อยแล้ว</div>
  <?php endif; ?>
  <?php if ($syncError): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> ซิงค์ข้อมูลไม่สำเร็จ: <?= View::e($syncError) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-hdd-network"></i> การเชื่อมต่อระบบภายนอก (RMS)</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">ที่อยู่เซิร์ฟเวอร์ต้นทางสำหรับโอนข้อมูลผู้ใช้ (เฉพาะโปรโตคอล+โดเมน) — เส้นทาง API และพารามิเตอร์ถูกกำหนดไว้ในโค้ดของระบบแล้ว ไม่ต้องระบุซ้ำที่นี่</div>

      <form method="post" action="<?= Url::to('/admin/settings/url') ?>" class="d-flex flex-column gap-2">
        <?= Csrf::field() ?>
        <label class="form-label" style="font-size:.82rem">Base URL</label>
        <div class="d-flex gap-2 flex-wrap">
          <input type="text" name="base_url" class="form-control form-control-sm" style="max-width:360px" value="<?= View::e($baseUrl) ?>" placeholder="http://rms.rvc.ac.th" required>
          <button type="submit" class="btn btn-sm btn-primary">บันทึก</button>
        </div>
        <div class="text-body-secondary" style="font-size:.72rem;font-family:ui-monospace,monospace">endpoint: <?= View::e($baseUrl) ?>/api_connection.php?app_name=nutty&amp;data=people</div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-shield-lock"></i> การเข้าสู่ระบบผ่าน RVC-ONE (SSO)</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">เชื่อมโยงหน้าเข้าสู่ระบบกับ RVC-ONE gateway — ผู้ใช้ยืนยันตัวตนที่ gateway (รหัสผ่าน + OTP ถ้าเปิดใช้) แล้วระบบสร้าง session ให้อัตโนมัติ</div>

      <?php if ($oaSaved): ?>
        <div class="alert alert-success py-2" style="font-size:.85rem"><i class="bi bi-check-circle"></i> บันทึกการตั้งค่า SSO แล้ว</div>
      <?php endif; ?>
      <?php if ($oaError): ?>
        <div class="alert alert-danger py-2" style="font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> <?= View::e($oaError) ?></div>
      <?php endif; ?>

      <div class="mb-3" style="font-size:.8rem;background:var(--bs-tertiary-bg);border-radius:9px;padding:10px 12px">
        <div class="mb-1" style="font-weight:600"><i class="bi bi-link-45deg"></i> Callback URL — นำค่านี้ไปลงทะเบียน (redirect_uri) ในระบบ RVC-ONE</div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <input type="text" id="oa-callback" class="form-control form-control-sm" style="max-width:420px;font-family:ui-monospace,monospace" value="<?= View::e($oaRedirectUri) ?>" readonly onclick="this.select()">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#oa-callback"><i class="bi bi-clipboard"></i> คัดลอก</button>
        </div>
        <div class="text-body-secondary mt-1" style="font-size:.72rem">ต้องตรงกับที่ลงทะเบียนไว้กับ gateway ทุกตัวอักษร (รวม scheme / เครื่องหมาย / ไม่มี “/” ท้าย)</div>

        <div class="mb-1 mt-3" style="font-weight:600"><i class="bi bi-box-arrow-in-right"></i> URL เริ่ม SSO ของแอป — นำไปกรอกที่หน้าแก้ไขแอปใน RVC-ONE</div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <input type="text" id="oa-start" class="form-control form-control-sm" style="max-width:420px;font-family:ui-monospace,monospace" value="<?= View::e($oaStartUrl) ?>" readonly onclick="this.select()">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#oa-start"><i class="bi bi-clipboard"></i> คัดลอก</button>
        </div>
        <div class="text-body-secondary mt-1" style="font-size:.72rem">ให้ไทล์ของแอปนี้ในหน้า Workspace พาผู้ใช้เข้าระบบได้ในคลิกเดียว</div>
      </div>

      <form method="post" action="<?= Url::to('/admin/settings/oauth') ?>" class="d-flex flex-column gap-2">
        <?= Csrf::field() ?>
        <div class="form-check form-switch">
          <input type="hidden" name="enabled" value="0">
          <input class="form-check-input" type="checkbox" role="switch" id="oa-enabled" name="enabled" value="1" <?= $oaEnabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="oa-enabled" style="font-size:.85rem">แสดงปุ่มเข้าสู่ระบบผ่าน RVC-ONE ในหน้าเข้าสู่ระบบ</label>
        </div>

        <label class="form-label mt-1" style="font-size:.82rem">ข้อความบนปุ่ม</label>
        <input type="text" name="button_label" maxlength="120" class="form-control form-control-sm" style="max-width:420px" value="<?= View::e($oaButtonLabel) ?>" placeholder="<?= View::e($oaButtonLabelDefault) ?>">
        <div class="text-body-secondary" style="font-size:.72rem">เว้นว่างเพื่อใช้ข้อความเริ่มต้น: “<?= View::e($oaButtonLabelDefault) ?>”</div>

        <label class="form-label mt-1" style="font-size:.82rem">Authorization endpoint</label>
        <input type="text" name="authorize_url" class="form-control form-control-sm" style="max-width:420px;font-family:ui-monospace,monospace" value="<?= View::e($oaAuthorizeUrl) ?>" placeholder="http://workspace.rvc.ac.th/oa/index.php" required>

        <label class="form-label mt-1" style="font-size:.82rem">Token verify endpoint</label>
        <input type="text" name="verify_token_url" class="form-control form-control-sm" style="max-width:420px;font-family:ui-monospace,monospace" value="<?= View::e($oaVerifyUrl) ?>" placeholder="http://workspace.rvc.ac.th/oa/api/verify_token.php" required>

        <label class="form-label mt-1" style="font-size:.82rem">client_id</label>
        <input type="text" name="client_id" class="form-control form-control-sm" style="max-width:220px" value="<?= View::e($oaClientId) ?>" required>

        <label class="form-label mt-1" style="font-size:.82rem">redirect_uri</label>
        <input type="text" name="redirect_uri" class="form-control form-control-sm" style="max-width:420px;font-family:ui-monospace,monospace" value="<?= View::e($oaRedirectUri) ?>" required>

        <div><button type="submit" class="btn btn-sm btn-primary mt-2">บันทึกการตั้งค่า SSO</button></div>
      </form>
      <div class="form-text mt-2" style="font-size:.72rem">ค่าเริ่มต้นมาจากไฟล์ <code>config/oa.php</code> — ค่าที่บันทึกที่นี่จะถูกใช้แทน</div>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-people"></i> ซิงค์รายชื่อผู้ใช้จาก RVC-ONE</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        ดึงรายชื่อผู้ใช้ประเภท <code><?= View::e($oaSyncType) ?></code> ผ่าน User-sync API แล้วจับคู่กับบัญชีในระบบด้วย id ของ RVC-ONE (id เดียวกับการเข้าสู่ระบบ SSO) —
        ผู้ใช้ที่ถูกระงับใน RVC-ONE จะถูกปิดการใช้งาน ผู้ใช้ใหม่ได้บทบาทเริ่มต้นเป็น "เจ้าหน้าที่ในงาน (Staff)" และบทบาทของผู้ใช้เดิมจะไม่ถูกแก้ไข
      </div>

      <?php if ($oaSynced): ?>
        <div class="alert alert-success py-2" style="font-size:.85rem"><i class="bi bi-check-circle"></i> ซิงค์รายชื่อจาก RVC-ONE เรียบร้อยแล้ว</div>
      <?php endif; ?>
      <?php if ($oaSyncError): ?>
        <div class="alert alert-danger py-2" style="font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> ซิงค์ไม่สำเร็จ: <?= View::e($oaSyncError) ?></div>
      <?php endif; ?>
      <?php if (!$oaSecretSet): ?>
        <div class="alert alert-warning py-2" style="font-size:.82rem"><i class="bi bi-key"></i> ยังไม่ได้ตั้งค่า <code>client_secret</code> — กำหนดตัวแปรสภาพแวดล้อม <code>OA_CLIENT_SECRET</code> หรือเพิ่ม <code>'oa' =&gt; ['client_secret' =&gt; '...']</code> ใน <code>config/config.php</code> บนเซิร์ฟเวอร์ (ห้าม commit ค่านี้)</div>
      <?php endif; ?>

      <div class="mb-3" style="font-size:.8rem;background:var(--bs-tertiary-bg);border-radius:9px;padding:10px 12px">
        <div style="font-family:ui-monospace,monospace;font-size:.74rem">endpoint: <?= View::e($oaUsersUrl) ?></div>
        <div class="mt-1">ซิงค์ครั้งถัดไปจะดึงเฉพาะที่เปลี่ยนตั้งแต่: <strong><?= $oaSyncSince ? View::e($oaSyncSince) : 'ยังไม่เคยซิงค์ครบรอบ (ดึงทั้งหมด)' ?></strong></div>
        <?php if ($oaSyncLastRunAt): ?>
          <div class="mt-1">ทำงานล่าสุด: <strong><?= date('d/m/Y H:i:s', strtotime($oaSyncLastRunAt)) ?></strong>
            <?php if ($oaSyncSummary): ?>(<?= View::e((string) ($oaSyncSummary['mode'] ?? '')) ?>, <?= (int) ($oaSyncSummary['pages'] ?? 0) ?> หน้า)<?php endif; ?>
          </div>
          <?php if ($oaSyncSummary): ?>
            <?php if (!empty($oaSyncSummary['error'])): ?>
              <div class="mt-1 text-danger">ล้มเหลว: <?= View::e((string) $oaSyncSummary['error']) ?> — ยังไม่ขยับจุดเริ่มซิงค์</div>
            <?php endif; ?>
            <div class="mt-1">ทั้งหมด <?= (int) $oaSyncSummary['total'] ?> รายการ · สร้างใหม่ <strong class="text-success"><?= (int) $oaSyncSummary['created'] ?></strong> · อัปเดต <strong class="text-primary"><?= (int) $oaSyncSummary['updated'] ?></strong> · ระงับ <strong class="text-danger"><?= (int) $oaSyncSummary['suspended'] ?></strong> · ข้าม <strong class="text-warning"><?= (int) $oaSyncSummary['skipped'] ?></strong><?php if (!empty($oaSyncSummary['failed'])): ?> · บันทึกไม่สำเร็จ <strong class="text-danger"><?= (int) $oaSyncSummary['failed'] ?></strong><?php endif; ?></div>
            <?php if (!empty($oaSyncSummary['errors'])): ?>
              <details class="mt-2">
                <summary style="cursor:pointer;font-size:.76rem">รายละเอียด (<?= count($oaSyncSummary['errors']) ?>)</summary>
                <ul class="mb-0 mt-1" style="font-size:.74rem">
                  <?php foreach ($oaSyncSummary['errors'] as $err): ?>
                    <li><?= View::e((string) $err) ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <form method="post" action="<?= Url::to('/admin/settings/oa-sync') ?>">
          <?= Csrf::field() ?>
          <button type="submit" class="btn btn-sm btn-primary" <?= $oaSecretSet ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat"></i> ซิงค์เฉพาะที่เปลี่ยนแปลง</button>
        </form>
        <form method="post" action="<?= Url::to('/admin/settings/oa-sync') ?>" onsubmit="return confirm('ดึงรายชื่อผู้ใช้ทั้งหมดจาก RVC-ONE ใหม่?')">
          <?= Csrf::field() ?>
          <input type="hidden" name="full" value="1">
          <button type="submit" class="btn btn-sm btn-outline-primary" <?= $oaSecretSet ? '' : 'disabled' ?>><i class="bi bi-cloud-download"></i> ซิงค์ทั้งหมดใหม่</button>
        </form>
      </div>
      <div class="form-text mt-2" style="font-size:.72rem">ตั้งเวลาอัตโนมัติด้วย cron บนเซิร์ฟเวอร์: <code>php <?= View::e(APP_ROOT) ?>/cli/oa-user-sync.php</code> (เพิ่ม <code>--full</code> เพื่อดึงทั้งหมด)</div>
    </div>
  </div>

  <?php if ($notifySaved): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> บันทึกค่าเริ่มต้นการแจ้งเตือนแล้ว</div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-bell"></i> ค่าเริ่มต้นการแจ้งเตือนงานใกล้ถึงกำหนด</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        ค่าเริ่มต้นสำหรับผู้ใช้ทุกคน — ผู้ใช้แต่ละคนสามารถกำหนดจำนวนวันแจ้งเตือนล่วงหน้าของตนเองทับค่านี้ได้ที่เมนู "การแจ้งเตือนของฉัน"
      </div>
      <form method="post" action="<?= Url::to('/admin/settings/notify') ?>" class="d-flex gap-3 flex-wrap align-items-end">
        <?= Csrf::field() ?>
        <div>
          <label class="form-label" style="font-size:.82rem">เตือนล่วงหน้า (วัน)</label>
          <input type="number" name="warn_days_default" min="1" max="60" class="form-control form-control-sm" style="max-width:120px" value="<?= (int) $warnDaysDefault ?>" required>
        </div>
        <div>
          <label class="form-label" style="font-size:.82rem">แจ้งเตือนระดับด่วนที่สุด (ชั่วโมง)</label>
          <input type="number" name="urgent_hours" min="1" max="720" class="form-control form-control-sm" style="max-width:140px" value="<?= (int) $urgentHours ?>" required>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">บันทึก</button>
      </form>
      <div class="form-text mt-2">
        งานที่ถูกมอบหมายมาแบบกระชั้นชิดตั้งแต่ต้น (ระยะเวลาจากวันที่มอบหมายถึงกำหนดส่งสั้นกว่าเกณฑ์) จะไม่ถูกแจ้งเตือนในระดับนั้น
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:14px">
    <div class="card-body">
      <div style="font-weight:600;font-size:.95rem;margin-bottom:4px"><i class="bi bi-arrow-repeat"></i> โอนข้อมูลผู้ใช้จากระบบภายนอก</div>
      <div class="text-body-secondary mb-3" style="font-size:.78rem">
        โอนเฉพาะรายชื่อที่มี <code>people_exit = 0</code> — ผู้ใช้ที่มีอยู่แล้วจะอัปเดตชื่อ/อีเมล/รหัสผ่านให้ตรงกับต้นทาง (วันที่สร้างบัญชีเดิมจะไม่ถูกแก้ไข) ผู้ใช้ใหม่จะได้รับบทบาทเริ่มต้นเป็น "เจ้าหน้าที่ในงาน (Staff)"
      </div>

      <?php if ($lastRunAt): ?>
        <div class="mb-3" style="font-size:.8rem;background:var(--bs-tertiary-bg);border-radius:9px;padding:10px 12px">
          <div>ซิงค์ล่าสุด: <strong><?= date('d/m/Y H:i:s', strtotime($lastRunAt)) ?></strong></div>
          <?php if ($lastSummary): ?>
            <div class="mt-1">ทั้งหมด <?= (int) $lastSummary['total'] ?> รายการ · สร้างใหม่ <strong class="text-success"><?= (int) $lastSummary['created'] ?></strong> · อัปเดต <strong class="text-primary"><?= (int) $lastSummary['updated'] ?></strong> · ข้าม <strong class="text-warning"><?= (int) $lastSummary['skipped'] ?></strong></div>
            <?php if (!empty($lastSummary['errors'])): ?>
              <details class="mt-2">
                <summary style="cursor:pointer;font-size:.76rem">รายละเอียดที่ถูกข้าม (<?= count($lastSummary['errors']) ?>)</summary>
                <ul class="mb-0 mt-1" style="font-size:.74rem">
                  <?php foreach ($lastSummary['errors'] as $err): ?>
                    <li><?= View::e($err) ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="text-body-secondary mb-3" style="font-size:.8rem">ยังไม่เคยซิงค์ข้อมูล</div>
      <?php endif; ?>

      <form method="post" action="<?= Url::to('/admin/settings/sync') ?>">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-cloud-download"></i> ซิงค์ข้อมูลผู้ใช้ตอนนี้</button>
      </form>
    </div>
  </div>
</div>
