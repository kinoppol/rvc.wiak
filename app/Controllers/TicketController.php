<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Upload;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Picker;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketEdit;
use App\Models\TicketFile;
use App\Models\TicketQuestion;
use App\Models\TicketTimeline;
use App\Models\User;

final class TicketController extends Controller
{
    public function show(array $args): void
    {
        $user = $this->requireLogin();
        $id = (int) $args['id'];
        $t = Ticket::find($id);
        if (!$t) {
            $this->html('<div class="p-4 text-danger">ไม่พบตั๋วงานนี้</div>', 404);
        }
        if (!$this->canView($t, $user)) {
            $this->html('<div class="modal-overlay"><div class="card border-0 shadow-lg p-4" style="border-radius:14px">'
                . '<div class="mb-3">คุณไม่มีสิทธิ์ดูตั๋วงานนี้ (ไม่ได้เป็นผู้มอบหมายหรือผู้รับมอบหมาย)</div>'
                . '<div><button type="button" class="btn btn-sm btn-outline-secondary" data-close-overlay>ปิด</button></div>'
                . '</div></div>', 403);
        }

        Ticket::markOpenedIfNeeded($id, (int) $user['id']);
        $t = Ticket::find($id);

        $activeRole = Auth::activeRole() ?? 'staff';
        $isAssigner = Role::isAssigner($activeRole);
        $isAssignee = (int) $t['to_user_id'] === (int) $user['id'];
        // The ticket's own assigner (or an admin) manages it, whatever role is
        // active now — หัวหน้างาน isn't flagged is_assigner but still assigns work.
        // An admin who is the assignee gets the assignee actions instead, so
        // they can submit their own work rather than approve it.
        $isOwnerAssigner = (int) $t['from_user_id'] === (int) $user['id']
            || (!$isAssignee && in_array('admin', User::rolesFor((int) $user['id']), true));

        $this->html(View::render('partials/ticket_detail', [
            't' => $t,
            'files' => TicketFile::forTicket($id),
            'questions' => TicketQuestion::forTicket($id),
            'edits' => TicketEdit::forTicket($id),
            'unseenEdits' => $isAssignee ? TicketEdit::unseenForTicket($id, (int) $user['id']) : [],
            'editsReady' => TicketEdit::ready(),
            'timeline' => TicketTimeline::forTicket($id),
            'durations' => Ticket::durations($t),
            'isAssigner' => $isAssigner,
            'isOwnerAssigner' => $isOwnerAssigner,
            'isAssignee' => $isAssignee,
            'activeRole' => $activeRole,
            'personItems' => Picker::people((int) $t['to_user_id'], (int) $user['id'], $activeRole),
            'unitItems' => Picker::units(),
        ]));
    }

    public function newForm(): void
    {
        $this->requireLogin();
        $this->html(View::render('partials/ticket_new', [
            'personItems' => Picker::people(null, (int) Auth::user()['id'], Auth::activeRole()),
            'unitItems' => Picker::units(),
            'defaultDue' => Ticket::defaultDue(),
        ]));
    }

    public function store(): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();

        $title = Request::str('title');
        $toUserId = Request::int('to_user_id');
        $priority = Request::str('priority', 'normal');
        $description = Request::str('description');
        $meta = Request::str('meta');
        $dueRaw = Request::str('due_at');

        if ($title === '' || $toUserId <= 0) {
            $this->json(['ok' => false, 'error' => 'กรุณากรอกหัวข้องานและเลือกผู้รับมอบหมาย'], 422);
        }
        if (!in_array($priority, array_keys(Ticket::PRIORITY), true)) {
            $priority = 'normal';
        }

        $toUser = User::findById($toUserId);
        if (!$toUser) {
            $this->json(['ok' => false, 'error' => 'ไม่พบผู้รับมอบหมายที่เลือก'], 422);
        }
        $toRoles = User::rolesFor($toUserId);

        $id = Ticket::create([
            'title' => $title,
            'meta' => $meta ?: null,
            'description' => $description ?: null,
            'from_user_id' => $user['id'],
            'to_user_id' => $toUserId,
            'priority' => $priority,
            'due_at' => $dueRaw !== '' ? date('Y-m-d H:i:s', strtotime($dueRaw)) : null,
            'from_role' => Auth::activeRole() ?? 'staff',
            'to_role' => $toRoles[0] ?? 'staff',
        ]);

        AuditLog::record((int) Auth::realUser()['id'], Auth::isImpersonating() ? (int) $user['id'] : null, 'ticket.create', 'ticket', $id);

        // Attachments supplied in the create form are best-effort: the ticket is
        // already saved, so a rejected file (too large, etc.) doesn't fail it.
        $this->storeAttachments($id, (int) $user['id']);

        $this->json(['ok' => true, 'id' => $id]);
    }

    /**
     * Stores every file and link attached in the request onto the ticket.
     * Accepts the multi-file input (`files[]`) and repeatable link rows
     * (`links[]` / `link_names[]`), and still honours the older single
     * `file` / `url` / `link_name` fields.
     *
     * @return array{added:int,errors:string[]}
     */
    private function storeAttachments(int $ticketId, int $userId): array
    {
        $added = 0;
        $errors = [];

        $files = [];
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
            $f = $_FILES['files'];
            for ($i = 0, $n = count($f['name']); $i < $n; $i++) {
                if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $files[] = [
                    'name' => $f['name'][$i],
                    'type' => $f['type'][$i] ?? '',
                    'tmp_name' => $f['tmp_name'][$i] ?? '',
                    'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $f['size'][$i] ?? 0,
                ];
            }
        }
        if ($single = Request::file('file')) {
            $files[] = $single;
        }
        foreach ($files as $file) {
            $stored = Upload::store($file, $ticketId);
            if ($stored['ok']) {
                TicketFile::addUpload($ticketId, $stored, $userId);
                $added++;
            } else {
                $errors[] = $stored['error'] ?? 'อัปโหลดไฟล์ล้มเหลว';
            }
        }

        $links = $_POST['links'] ?? [];
        $linkNames = $_POST['link_names'] ?? [];
        if (!is_array($links)) {
            $links = [$links];
            $linkNames = is_array($linkNames) ? $linkNames : [$linkNames];
        }
        if (($legacy = Request::str('url')) !== '') {
            $links[] = $legacy;
            $linkNames[] = Request::str('link_name');
        }
        foreach ($links as $i => $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $name = trim((string) ($linkNames[$i] ?? '')) ?: $url;
            TicketFile::addLink($ticketId, $name, $url, $userId);
            $added++;
        }

        return ['added' => $added, 'errors' => $errors];
    }

    private function loadForAction(int $id): array
    {
        $t = Ticket::find($id);
        if (!$t) {
            $this->json(['ok' => false, 'error' => 'ไม่พบตั๋วงานนี้'], 404);
        }
        return $t;
    }

    /** Only the ticket's assigner, its assignee, or an admin may see it. */
    private function canView(array $t, array $user): bool
    {
        return Ticket::involves($t, (int) $user['id'])
            || in_array('admin', User::rolesFor((int) $user['id']), true);
    }

    private function assertAssignee(array $t, array $user): void
    {
        if ((int) $t['to_user_id'] !== (int) $user['id']) {
            $this->json(['ok' => false, 'error' => 'คุณไม่ใช่ผู้รับมอบหมายของงานนี้'], 403);
        }
    }

    private function assertAssignerOwner(array $t, array $user): void
    {
        $isAdmin = in_array('admin', User::rolesFor((int) $user['id']), true);
        if (!$isAdmin && (int) $t['from_user_id'] !== (int) $user['id']) {
            $this->json(['ok' => false, 'error' => 'คุณไม่มีสิทธิ์ดำเนินการนี้'], 403);
        }
    }

    public function acknowledge(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);
        Ticket::setStatus((int) $t['id'], 'ack', (int) $user['id'], 'กดรับทราบ (Acknowledge) และเริ่มดำเนินงาน');
        $this->json(['ok' => true]);
    }

    public function start(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);
        Ticket::setStatus((int) $t['id'], 'doing', (int) $user['id'], 'เริ่มดำเนินการ');
        $this->json(['ok' => true]);
    }

    public function requestReview(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);
        Ticket::setStatus((int) $t['id'], 'review', (int) $user['id'], 'ขอทบทวนคำสั่ง (Request Review)');
        $this->json(['ok' => true]);
    }

    public function submit(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);
        if (in_array($t['status'], ['done', 'forced', 'cancelled'], true)) {
            $this->json(['ok' => false, 'error' => 'งานนี้ปิดแล้ว'], 422);
        }
        // Files/links picked in the attachment form travel with the submission.
        $result = $this->storeAttachments((int) $t['id'], (int) $user['id']);
        if ($result['errors']) {
            $this->json(['ok' => false, 'error' => $result['errors'][0]], 422);
        }
        Ticket::setStatus(
            (int) $t['id'],
            'submitted',
            (int) $user['id'],
            'ส่งงาน / รายงานผลต่อผู้บริหาร' . ($result['added'] ? ' (แนบ ' . $result['added'] . ' รายการ)' : '')
        );
        $this->json(['ok' => true]);
    }

    public function approve(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);
        if (in_array($t['status'], ['done', 'forced', 'cancelled'], true)) {
            $this->json(['ok' => false, 'error' => 'งานนี้ปิดแล้ว'], 422);
        }
        Ticket::setStatus((int) $t['id'], 'done', (int) $user['id'], 'อนุมัติผลงาน / ปิดตั๋ว');
        $this->json(['ok' => true]);
    }

    public function forceClose(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);
        if (in_array($t['status'], ['done', 'forced', 'cancelled'], true)) {
            $this->json(['ok' => false, 'error' => 'งานนี้ปิดแล้ว'], 422);
        }
        $reason = Request::str('reason');
        if ($reason === '') {
            $this->json(['ok' => false, 'error' => 'กรุณาระบุเหตุผลในการปิด/จบงาน'], 422);
        }
        Ticket::setStatus((int) $t['id'], 'forced', (int) $user['id'], 'ปิด/จบงานโดยคำสั่ง — เหตุผล: ' . $reason);
        AuditLog::record((int) Auth::realUser()['id'], Auth::isImpersonating() ? (int) $user['id'] : null, 'ticket.force_close', 'ticket', (int) $t['id'], 'reason=' . $reason);
        $this->json(['ok' => true]);
    }

    public function cancel(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);
        if (in_array($t['status'], ['done', 'forced', 'cancelled'], true)) {
            $this->json(['ok' => false, 'error' => 'งานนี้ปิดแล้ว'], 422);
        }
        $reason = Request::str('reason');
        Ticket::setStatus((int) $t['id'], 'cancelled', (int) $user['id'], 'ยกเลิกงาน' . ($reason !== '' ? ' — เหตุผล: ' . $reason : ''));
        AuditLog::record((int) Auth::realUser()['id'], Auth::isImpersonating() ? (int) $user['id'] : null, 'ticket.cancel', 'ticket', (int) $t['id'], $reason !== '' ? 'reason=' . $reason : null);
        $this->json(['ok' => true]);
    }

    public function update(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);
        if (in_array($t['status'], ['done', 'forced', 'cancelled'], true)) {
            $this->json(['ok' => false, 'error' => 'งานนี้ปิดแล้ว แก้ไขไม่ได้'], 422);
        }
        if (!TicketEdit::ready()) {
            $this->json(['ok' => false, 'error' => 'ยังไม่ได้รัน migration สำหรับการแก้ไขตั๋วงาน (ผู้ดูแลระบบ: เมนู Migration ฐานข้อมูล)'], 503);
        }

        $title = Request::str('title');
        if ($title === '') {
            $this->json(['ok' => false, 'error' => 'กรุณากรอกหัวข้องาน'], 422);
        }
        $priority = Request::str('priority', 'normal');
        if (!isset(Ticket::PRIORITY[$priority])) {
            $priority = 'normal';
        }
        $dueRaw = Request::str('due_at');
        if ($dueRaw !== '' && strtotime($dueRaw) === false) {
            $this->json(['ok' => false, 'error' => 'รูปแบบวันกำหนดส่งไม่ถูกต้อง'], 422);
        }

        $changes = Ticket::updateFields((int) $t['id'], [
            'title' => mb_substr($title, 0, 255),
            'meta' => mb_substr(Request::str('meta'), 0, 255),
            'priority' => $priority,
            'due_at' => $dueRaw !== '' ? date('Y-m-d H:i:s', strtotime($dueRaw)) : null,
            'description' => (string) ($_POST['description'] ?? ''),
        ], (int) $user['id']);

        if (!$changes) {
            $this->json(['ok' => false, 'error' => 'ไม่มีข้อมูลที่เปลี่ยนแปลง'], 422);
        }
        AuditLog::record(
            (int) Auth::realUser()['id'],
            Auth::isImpersonating() ? (int) $user['id'] : null,
            'ticket.update',
            'ticket',
            (int) $t['id'],
            'fields=' . implode(',', array_column($changes, 'field'))
        );
        $this->json(['ok' => true]);
    }

    public function acknowledgeEdits(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);
        if (TicketEdit::markSeen((int) $t['id'], (int) $user['id']) > 0) {
            TicketTimeline::add((int) $t['id'], 'ผู้รับมอบหมายรับทราบการแก้ไขตั๋วงาน', (int) $user['id']);
        }
        $this->json(['ok' => true]);
    }

    public function reassign(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);

        $newToUserId = Request::int('to_user_id');
        $newTo = User::findById($newToUserId);
        if (!$newTo) {
            $this->json(['ok' => false, 'error' => 'ไม่พบผู้รับมอบหมายที่เลือก'], 422);
        }
        $toRoles = User::rolesFor($newToUserId);
        Ticket::reassign((int) $t['id'], $newToUserId, $toRoles[0] ?? 'staff', (int) $user['id']);
        $this->json(['ok' => true]);
    }

    public function addQuestion(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);

        $text = Request::str('text');
        if ($text === '') {
            $this->json(['ok' => false, 'error' => 'กรุณาพิมพ์คำถามก่อนส่ง'], 422);
        }
        TicketQuestion::add((int) $t['id'], $text, (int) $user['id']);
        Ticket::setStatus((int) $t['id'], 'review', (int) $user['id'], 'ส่งคำถามขอทบทวนคำสั่งเพิ่มเติม');
        $this->json(['ok' => true]);
    }

    public function answerQuestion(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);

        $q = TicketQuestion::find((int) $args['qid']);
        if (!$q || (int) $q['ticket_id'] !== (int) $t['id']) {
            $this->json(['ok' => false, 'error' => 'ไม่พบคำถามนี้'], 404);
        }
        $answer = Request::str('answer');
        if ($answer === '') {
            $this->json(['ok' => false, 'error' => 'กรุณาพิมพ์คำตอบก่อน'], 422);
        }
        TicketQuestion::answer((int) $q['id'], $answer, (int) $user['id']);
        TicketTimeline::add((int) $t['id'], 'ผู้มอบหมายตอบคำถามข้อ ' . $q['no'], (int) $user['id']);
        $this->json(['ok' => true]);
    }

    public function uploadFile(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        if ((int) $t['to_user_id'] !== (int) $user['id'] && (int) $t['from_user_id'] !== (int) $user['id']
            && !in_array('admin', User::rolesFor((int) $user['id']), true)) {
            $this->json(['ok' => false, 'error' => 'คุณไม่มีสิทธิ์แนบไฟล์ในตั๋วงานนี้'], 403);
        }

        $result = $this->storeAttachments((int) $t['id'], (int) $user['id']);
        if ($result['added'] === 0) {
            $error = $result['errors'][0] ?? 'กรุณาเลือกไฟล์ หรือใส่ลิงก์';
            $this->json(['ok' => false, 'error' => $error], 422);
        }
        $this->json(['ok' => true]);
    }

    public function downloadFile(array $args): void
    {
        $user = $this->requireLogin();
        $f = TicketFile::find((int) $args['fileId']);
        if (!$f || (int) $f['ticket_id'] !== (int) $args['id'] || $f['is_link']) {
            http_response_code(404);
            exit;
        }
        $t = Ticket::find((int) $f['ticket_id']);
        if (!$t || !$this->canView($t, $user)) {
            http_response_code(403);
            exit;
        }
        $path = Upload::absolutePath($f['stored_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        // ?inline=1 opens PDFs/images in the browser tab instead of downloading.
        $inline = Request::str('inline') === '1' && TicketFile::isPreviewable($f);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']) ?: 'file';
        header('Content-Type: ' . ($inline ? $f['mime'] : ($f['mime'] ?: 'application/octet-stream')));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
