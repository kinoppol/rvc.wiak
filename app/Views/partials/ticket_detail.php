<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Ticket;
/** @var array $t */
/** @var array $files */
/** @var array $questions */
/** @var array $timeline */
/** @var array $durations */
/** @var bool $isAssigner */
/** @var bool $isOwnerAssigner */
/** @var bool $isAssignee */
/** @var array $people */

[$stLabel, $stBg, $stFg] = Ticket::STATUS[$t['status']];
[$priLabel, $priBg, $priFg] = Ticket::PRIORITY[$t['priority']];
$closed = in_array($t['status'], ['done', 'forced'], true);
$tid = (int) $t['id'];
?>
<div class="detail-overlay" data-close-overlay>
  <div class="detail-panel">
    <div class="detail-head">
      <div class="min-w-0 flex-fill">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span style="font-family:ui-monospace,monospace;font-size:.78rem;background:rgba(255,255,255,.12);padding:2px 8px;border-radius:6px"><?= View::e($t['code']) ?></span>
          <span class="badge rounded-pill" style="background:<?= $stBg ?>;color:<?= $stFg ?>;font-weight:500"><?= $stLabel ?></span>
          <span class="badge" style="background:<?= $priBg ?>;color:<?= $priFg ?>;font-weight:500">ความสำคัญ: <?= $priLabel ?></span>
          <?php if ($t['is_cross']): ?><span class="badge rounded-pill" style="background:rgba(217,119,6,.25);color:#fde68a;font-weight:500"><i class="bi bi-diagram-3"></i> ข้ามขั้น</span><?php endif; ?>
        </div>
        <div style="font-size:1.12rem;font-weight:600;margin-top:7px"><?= View::e($t['title']) ?></div>
        <div style="color:#94a3b8;font-size:.78rem;margin-top:3px">มอบหมายโดย <?= View::e($t['from_name']) ?> → ผู้รับผิดชอบ <?= View::e($t['to_name']) ?><?php if ($t['due_at']): ?> · กำหนดส่ง <?= date('d/m/Y H:i', strtotime($t['due_at'])) ?><?php endif; ?></div>
      </div>
      <button type="button" class="btn btn-sm btn-outline-light" data-close-overlay><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="detail-body">
      <div class="d-flex flex-column gap-3">
        <div class="card border-0 shadow-sm" style="border-radius:12px">
          <div class="card-body">
            <div style="font-weight:600;font-size:.88rem;margin-bottom:8px">รายละเอียดงาน</div>
            <div class="text-body-secondary" style="font-size:.85rem;line-height:1.75;white-space:pre-wrap"><?= View::e($t['description'] ?: 'ไม่มีรายละเอียดเพิ่มเติม') ?></div>
          </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:12px">
          <div class="card-body">
            <div style="font-weight:600;font-size:.88rem;margin-bottom:12px">เอกสารแนบ และลิงก์ภายนอก</div>
            <div class="d-flex flex-column gap-2">
              <?php if (!$files): ?><div class="text-body-secondary" style="font-size:.8rem">ยังไม่มีไฟล์แนบ</div><?php endif; ?>
              <?php foreach ($files as $f): ?>
                <div class="d-flex align-items-center gap-2" style="border:1px solid var(--bs-border-color);border-radius:9px;padding:9px 12px">
                  <i class="bi bi-<?= $f['icon'] ?>" style="font-size:1.15rem;color:<?= $f['color'] ?>"></i>
                  <div class="min-w-0 flex-fill">
                    <div style="font-size:.82rem;font-weight:500;word-break:break-word"><?= View::e($f['name']) ?></div>
                    <div class="text-body-secondary" style="font-size:.72rem;word-break:break-all"><?= View::e($f['meta']) ?></div>
                  </div>
                  <?php if ($f['is_link']): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= View::e($f['url']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>
                  <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= Url::to('/tickets/' . $tid . '/files/' . $f['id']) ?>"><i class="bi bi-download"></i></a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (($isAssignee || $isOwnerAssigner) && !$closed): ?>
            <form data-ajax-form action="<?= Url::to('/tickets/' . $tid . '/files') ?>" data-refresh-ticket="<?= $tid ?>" enctype="multipart/form-data" class="mt-3 d-flex flex-column gap-2">
              <?= Csrf::field() ?>
              <div class="d-flex gap-2 flex-wrap">
                <input type="file" name="file" class="form-control form-control-sm" style="max-width:240px">
                <input type="url" name="url" class="form-control form-control-sm" placeholder="หรือวางลิงก์ภายนอก" style="max-width:220px">
                <input type="text" name="link_name" class="form-control form-control-sm" placeholder="ชื่อลิงก์ (ถ้ามี)" style="max-width:180px">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip"></i> แนบไฟล์ / เพิ่มลิงก์</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:12px">
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-3">
              <div style="font-weight:600;font-size:.88rem">กระดานซักถาม / ขอทบทวนคำสั่ง</div>
              <span class="badge rounded-pill text-bg-secondary" style="font-weight:500"><?= count($questions) ?> ข้อ</span>
            </div>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($questions as $q): $answered = $q['answer'] !== null; ?>
                <div style="border:1px solid var(--bs-border-color);border-radius:11px;padding:13px 14px;background:var(--bs-tertiary-bg)">
                  <div class="d-flex gap-2 align-items-start">
                    <span class="badge text-bg-primary" style="font-weight:600">ข้อ <?= (int) $q['no'] ?></span>
                    <div class="min-w-0 flex-fill">
                      <div style="font-size:.85rem;line-height:1.6"><?= nl2br(View::e($q['text'])) ?></div>
                      <div class="text-body-secondary" style="font-size:.72rem;margin-top:4px">ถามโดย <?= View::e($q['by_name']) ?> · <?= date('d/m/Y H:i', strtotime($q['at'])) ?></div>
                    </div>
                    <span class="badge rounded-pill" style="background:<?= $answered ? 'rgba(16,185,129,.15)' : 'rgba(217,119,6,.15)' ?>;color:<?= $answered ? '#047857' : '#b45309' ?>;font-weight:500"><?= $answered ? 'ตอบแล้ว' : 'รอคำตอบ' ?></span>
                  </div>
                  <?php if ($answered): ?>
                    <div style="margin-top:10px;margin-left:12px;padding:10px 12px;border-left:3px solid #2563eb;background:var(--bs-body-bg);border-radius:0 9px 9px 0">
                      <div style="font-size:.83rem;line-height:1.6"><?= nl2br(View::e($q['answer'])) ?></div>
                      <div class="text-body-secondary" style="font-size:.72rem;margin-top:4px">ตอบโดย <?= View::e($q['answer_by_name']) ?> · <?= date('d/m/Y H:i', strtotime($q['answer_at'])) ?></div>
                    </div>
                  <?php elseif ($isOwnerAssigner && !$closed): ?>
                    <form data-ajax-form action="<?= Url::to('/tickets/' . $tid . '/questions/' . $q['id'] . '/answer') ?>" data-refresh-ticket="<?= $tid ?>" class="mt-2 d-flex gap-2 align-items-start">
                      <?= Csrf::field() ?>
                      <textarea name="answer" class="form-control form-control-sm" rows="2" placeholder="พิมพ์คำตอบสำหรับข้อนี้…" required></textarea>
                      <button type="submit" class="btn btn-sm btn-primary text-nowrap">ตอบข้อนี้</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($isAssignee && !$closed): ?>
              <div class="mt-3 pt-3" style="border-top:1px dashed var(--bs-border-color)">
                <div style="font-size:.82rem;font-weight:600;margin-bottom:7px">ขอทบทวนคำสั่ง — ส่งคำถามเป็นข้อๆ</div>
                <form data-ajax-form action="<?= Url::to('/tickets/' . $tid . '/questions') ?>" data-refresh-ticket="<?= $tid ?>">
                  <?= Csrf::field() ?>
                  <textarea name="text" class="form-control form-control-sm" rows="3" placeholder="พิมพ์คำถาม 1 ข้อต่อการส่ง เช่น ขอทราบรูปแบบรายงานที่ต้องการ…" required></textarea>
                  <button type="submit" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-question-circle"></i> ส่งคำถามเพิ่ม (เป็นข้อ)</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="d-flex flex-column gap-3 detail-side">
        <div class="card border-0 shadow-sm" style="border-radius:12px">
          <div class="card-body">
            <div style="font-weight:600;font-size:.88rem;margin-bottom:12px">การดำเนินการ</div>
            <div class="d-flex flex-column gap-2">
              <?php if ($closed): ?>
                <div class="text-body-secondary" style="font-size:.82rem">งานนี้ปิดแล้ว ไม่สามารถดำเนินการเพิ่มเติมได้</div>
              <?php elseif ($isOwnerAssigner): ?>
                <button type="button" class="btn btn-sm btn-primary" data-post="/tickets/<?= $tid ?>/approve" data-refresh-ticket="<?= $tid ?>"><i class="bi bi-check2-circle"></i> อนุมัติผลงาน / ปิดตั๋ว</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-post="/tickets/<?= $tid ?>/force-close" data-confirm="ยืนยันสั่งปิดงานทันที (Force Close)? คำถามที่ยังไม่ได้ตอบจะถูกปิดพร้อมกัน" data-refresh-ticket="<?= $tid ?>"><i class="bi bi-lock"></i> สั่งปิดงานทันที (Force Close)</button>
                <details>
                  <summary class="btn btn-sm btn-outline-secondary w-100 text-start"><i class="bi bi-person-plus"></i> มอบหมายต่อ / เปลี่ยนผู้รับผิดชอบ</summary>
                  <form data-ajax-form action="<?= Url::to('/tickets/' . $tid . '/reassign') ?>" data-refresh-ticket="<?= $tid ?>" class="mt-2 d-flex gap-2">
                    <?= Csrf::field() ?>
                    <select name="to_user_id" class="form-select form-select-sm" required>
                      <option value="">เลือกผู้รับมอบหมาย…</option>
                      <?php foreach ($people as $p): if ((int) $p['id'] === (int) $t['to_user_id']) { continue; } ?>
                        <option value="<?= (int) $p['id'] ?>"><?= View::e($p['full_name']) ?> (<?= View::e($p['roles'] ? implode(', ', $p['roles']) : '') ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary text-nowrap">มอบหมาย</button>
                  </form>
                </details>
              <?php elseif ($isAssignee): ?>
                <?php if ($t['status'] === 'new'): ?>
                  <button type="button" class="btn btn-sm btn-primary" data-post="/tickets/<?= $tid ?>/acknowledge" data-refresh-ticket="<?= $tid ?>"><i class="bi bi-hand-thumbs-up"></i> กดรับทราบ (Acknowledge)</button>
                <?php endif; ?>
                <?php if (in_array($t['status'], ['ack', 'new'], true)): ?>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-post="/tickets/<?= $tid ?>/start" data-refresh-ticket="<?= $tid ?>"><i class="bi bi-play-circle"></i> เริ่มดำเนินการ</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-post="/tickets/<?= $tid ?>/request-review" data-refresh-ticket="<?= $tid ?>"><i class="bi bi-question-circle"></i> ขอทบทวนคำสั่ง (Request Review)</button>
                <?php if (in_array($t['status'], ['ack', 'doing', 'review'], true)): ?>
                  <button type="button" class="btn btn-sm btn-outline-success" data-post="/tickets/<?= $tid ?>/submit" data-refresh-ticket="<?= $tid ?>"><i class="bi bi-upload"></i> ส่งงาน / รายงานผลต่อผู้บริหาร</button>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-body-secondary" style="font-size:.82rem">คุณไม่มีสิทธิ์ดำเนินการในตั๋วงานนี้ ในบทบาทปัจจุบัน</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:12px">
          <div class="card-body">
            <div style="font-weight:600;font-size:.88rem;margin-bottom:12px">สรุปเวลาที่ใช้ (Duration Summary)</div>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($durations as $d): ?>
                <div class="d-flex justify-content-between gap-2" style="font-size:.8rem;padding-bottom:8px;border-bottom:1px dashed var(--bs-border-color)">
                  <span class="text-body-secondary"><?= View::e($d['label']) ?></span>
                  <strong><?= View::e($d['value']) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:12px">
          <div class="card-body">
            <div style="font-weight:600;font-size:.88rem;margin-bottom:14px">Timeline การดำเนินงาน</div>
            <div>
              <?php foreach ($timeline as $e): ?>
                <div class="tl-item">
                  <span class="tl-dot"></span>
                  <div style="font-size:.83rem;font-weight:500"><?= View::e($e['label']) ?></div>
                  <div class="text-body-secondary" style="font-size:.74rem"><?= date('d/m/Y H:i', strtotime($e['at'])) ?> · โดย <?= View::e($e['by_name'] ?? 'ระบบ') ?></div>
                  <?php if ($e['gap']): ?><div style="font-size:.72rem;color:#2563eb"><?= View::e($e['gap']) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
