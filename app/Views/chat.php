<?php
use App\Core\Csrf;
use App\Core\Url;
use App\Core\View;
use App\Models\Chat;
/** @var bool $ready */
/** @var array<int,array> $conversations */
/** @var int|null $activeId */
/** @var array|null $other */
/** @var string $messagesHtml */
/** @var int $lastId */
/** @var bool $canWrite */
/** @var array $personItems */
$avatar = static function (?array $p, int $size = 40): string {
    if ($p && !empty($p['avatar'])) {
        return '<img class="chat-avatar" style="width:' . $size . 'px;height:' . $size . 'px" src="' . View::e(Url::to('/avatar/' . (int) $p['id'])) . '" alt="">';
    }
    $initial = mb_substr(trim((string) ($p['name'] ?? '?')), 0, 1);
    return '<span class="chat-avatar chat-avatar-initial" style="width:' . $size . 'px;height:' . $size . 'px">' . View::e($initial) . '</span>';
};
?>
<?php if (!$ready): ?>
  <div class="alert alert-warning mb-0">ระบบสนทนายังไม่พร้อม ผู้ดูแลระบบต้องรัน migration ก่อน</div>
<?php else: ?>
<div class="chat-shell card border-0<?= $activeId !== null ? ' has-active' : '' ?>">
  <aside class="chat-list">
    <div class="chat-list-head">
      <div class="chat-title"><i class="bi bi-chat-dots"></i> สนทนา</div>
      <?php if ($canWrite): ?>
        <form method="post" action="<?= Url::to('/chat/start') ?>" class="chat-new">
          <?= Csrf::field() ?>
          <?= View::render('partials/combo', ['name' => '', 'valueName' => 'to_user_id', 'items' => $personItems, 'placeholder' => 'เริ่มสนทนากับ… พิมพ์ชื่อ', 'required' => true]) ?>
          <button type="submit" class="btn btn-sm btn-primary" aria-label="เริ่มสนทนา"><i class="bi bi-pencil-square"></i></button>
        </form>
      <?php endif; ?>
    </div>
    <nav class="chat-items" aria-label="รายการสนทนา">
      <?php if (!$conversations): ?>
        <div class="chat-empty-list">ยังไม่มีบทสนทนา<br>ค้นหาชื่อด้านบนเพื่อเริ่มคุย</div>
      <?php endif; ?>
      <?php foreach ($conversations as $c): $isActive = (int) $c['id'] === $activeId; ?>
        <a class="chat-item<?= $isActive ? ' active' : '' ?>" href="<?= Url::to('/chat/' . (int) $c['id']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
          <?= $avatar(['id' => $c['other_id'], 'name' => $c['other_name'], 'avatar' => $c['other_avatar']]) ?>
          <span class="chat-item-body">
            <span class="chat-item-top">
              <span class="chat-item-name"><?= View::e($c['other_name']) ?></span>
              <span class="chat-item-time"><?= View::e(Chat::timeLabel((string) $c['activity_at'])) ?></span>
            </span>
            <span class="chat-item-bottom">
              <span class="chat-item-preview"><?php if ($c['last_body'] === null): ?>เริ่มบทสนทนา<?php else: ?><?= (int) $c['last_sender'] === (int) \App\Core\Auth::user()['id'] ? 'คุณ: ' : '' ?><?= View::e(Chat::preview((string) $c['last_body'], 60)) ?><?php endif; ?></span>
              <?php if ((int) $c['unread'] > 0): ?><span class="chat-unread"><?= (int) $c['unread'] ?></span><?php endif; ?>
            </span>
          </span>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <section class="chat-thread-pane">
    <?php if ($activeId === null): ?>
      <div class="chat-placeholder">
        <i class="bi bi-chat-square-text"></i>
        <div>เลือกบทสนทนา หรือค้นหาชื่อเพื่อเริ่มคุย</div>
        <div class="text-body-secondary" style="font-size:.8rem">พิมพ์ <kbd>#</kbd> ในข้อความเพื่ออ้างถึงตั๋วงาน</div>
      </div>
    <?php else: ?>
      <header class="chat-thread-head">
        <a class="btn btn-sm btn-outline-secondary chat-back" href="<?= Url::to('/chat') ?>" aria-label="กลับไปรายการสนทนา"><i class="bi bi-arrow-left"></i></a>
        <?= $avatar($other ? ['id' => $other['id'], 'name' => $other['full_name'], 'avatar' => $other['avatar_path']] : null, 36) ?>
        <div class="chat-thread-name"><?= View::e($other['full_name'] ?? 'ผู้ใช้') ?></div>
      </header>
      <div class="chat-thread" data-chat-thread data-conversation="<?= (int) $activeId ?>" data-last-id="<?= (int) $lastId ?>" aria-live="polite">
        <?php if ($messagesHtml === '' || trim($messagesHtml) === ''): ?>
          <div class="chat-thread-empty">ยังไม่มีข้อความ เริ่มทักทายได้เลย</div>
        <?php endif; ?>
        <?= $messagesHtml ?>
      </div>
      <?php if ($canWrite): ?>
        <form class="chat-composer" data-chat-form action="/chat/<?= (int) $activeId ?>/messages">
          <div class="chat-suggest" data-chat-suggest role="listbox" hidden></div>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-chat-hash title="อ้างถึงตั๋วงาน" aria-label="อ้างถึงตั๋วงาน"><i class="bi bi-hash"></i></button>
          <textarea name="body" rows="1" class="form-control" data-chat-input maxlength="<?= Chat::MAX_BODY ?>" placeholder="พิมพ์ข้อความ… (# อ้างถึงตั๋ว)" aria-label="ข้อความ"></textarea>
          <button type="submit" class="btn btn-primary" aria-label="ส่งข้อความ"><i class="bi bi-send-fill"></i></button>
        </form>
      <?php else: ?>
        <div class="chat-readonly">กำลังสวมสิทธิ์ผู้ใช้อื่น ดูข้อความได้แต่ส่งไม่ได้</div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>
