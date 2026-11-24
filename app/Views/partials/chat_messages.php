<?php
use App\Core\View;
use App\Models\Chat;
/** @var array<int,array> $messages */
/** @var int $me */
/** @var array $refs */
foreach ($messages as $m):
    $mine = (int) $m['sender_id'] === $me;
?>
  <div class="chat-msg<?= $mine ? ' is-mine' : '' ?>" data-msg-id="<?= (int) $m['id'] ?>">
    <div class="chat-bubble"><?= Chat::renderBody((string) $m['body'], $refs) ?></div>
    <div class="chat-meta"><?= $mine ? 'คุณ' : View::e($m['sender_name']) ?> · <?= View::e(Chat::timeLabel((string) $m['created_at'])) ?></div>
  </div>
<?php endforeach; ?>
