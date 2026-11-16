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
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketChange;
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

        Ticket::markOpenedIfNeeded($id, (int) $user['id']);
        $t = Ticket::find($id);

        $activeRole = Auth::activeRole() ?? 'staff';
        $isAssigner = Role::isAssigner($activeRole);
        $isOwnerAssigner = $isAssigner && ((int) $t['from_user_id'] === (int) $user['id'] || in_array('admin', User::rolesFor((int) $user['id']), true));
        $isAssignee = (int) $t['to_user_id'] === (int) $user['id'];

        $this->html(View::render('partials/ticket_detail', [
            't' => $t,
            'files' => TicketFile::forTicket($id),
            'questions' => TicketQuestion::forTicket($id),
            'timeline' => TicketTimeline::forTicket($id),
            'durations' => Ticket::durations($t),
            'isAssigner' => $isAssigner,
            'isOwnerAssigner' => $isOwnerAssigner,
            'isAssignee' => $isAssignee,
            'activeRole' => $activeRole,
            'people' => User::allWithRoles(),
            'canEdit' => $this->canEdit($t, $user),
            'unseenChanges' => $isAssignee ? TicketChange::unseenFor($id, (int) $user['id']) : [],
        ]));
    }

    public function newForm(): void
    {
        $this->requireLogin();
        $this->html(View::render('partials/ticket_new', [
            'people' => User::allWithRoles(),
            'unitNames' => $this->orgUnitNames(),
        ]));
    }

    /** Distinct ฝ่าย/งาน/แผนก names, used as type-ahead suggestions for the หน่วยงาน field. */
    private function orgUnitNames(): array
    {
        $names = [];
        foreach (array_keys(OrgUnit::TYPES) as $type) {
            foreach (OrgUnit::all($type) as $u) {
                $n = trim((string) $u['name']);
                if ($n !== '') {
                    $names[$n] = true;
                }
            }
        }
        return array_keys($names);
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

        // Check every attachment up front so a bad file is refused before the
        // ticket exists, rather than leaving a ticket with half its files.
        $files = Request::files('files');
        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $this->json(['ok' => false, 'error' => 'อัปโหลดไฟล์ "' . $f['name'] . '" ล้มเหลว (รหัส ' . $f['error'] . ')'], 422);
            }
            if ($f['size'] > Upload::maxBytes()) {
                $this->json(['ok' => false, 'error' => 'ไฟล์ "' . $f['name'] . '" มีขนาดเกินที่กำหนด (สูงสุด ' . Upload::humanSize(Upload::maxBytes()) . ')'], 422);
            }
        }

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

        $failed = [];
        foreach ($files as $f) {
            $stored = Upload::store($f, $id);
            if ($stored['ok']) {
                TicketFile::addUpload($id, $stored, (int) $user['id']);
            } else {
                $failed[] = $f['name'] . ': ' . $stored['error'];
            }
        }

        $resp = ['ok' => true, 'id' => $id];
        if ($failed) {
            $resp['warning'] = "สร้างตั๋วงานแล้ว แต่แนบไฟล์ไม่สำเร็จ:\n" . implode("\n", $failed);
        }
        $this->json($resp);
    }

    public function editForm(array $args): void
    {
        $user = $this->requireLogin();
        $t = Ticket::find((int) $args['id']);
        if (!$t) {
            $this->html('<div class="p-4 text-danger">ไม่พบตั๋วงานนี้</div>', 404);
        }
        if (!$this->canEdit($t, $user)) {
            $this->html('<div class="p-4 text-danger">คุณไม่มีสิทธิ์แก้ไขตั๋วงานนี้</div>', 403);
        }
        $this->html(View::render('partials/ticket_edit', [
            't' => $t,
            'unitNames' => $this->orgUnitNames(),
        ]));
    }

    public function update(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        if (in_array($t['status'], ['done', 'forced'], true)) {
            $this->json(['ok' => false, 'error' => 'งานนี้ปิดแล้ว ไม่สามารถแก้ไขได้'], 422);
        }
        if (!$this->canEdit($t, $user)) {
            $this->json(['ok' => false, 'error' => 'คุณไม่มีสิทธิ์แก้ไขตั๋วงานนี้'], 403);
        }
        if (!TicketChange::available()) {
            $this->json(['ok' => false, 'error' => 'ยังไม่ได้รัน migration 20261116_add_ticket_changes.sql — กรุณาให้ผู้ดูแลระบบรันที่หน้า Migration ฐานข้อมูลก่อน'], 503);
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
        $meta = Request::str('meta');
        $description = Request::str('description');

        $changes = Ticket::updateDetails($t, [
            'title' => $title,
            'meta' => $meta !== '' ? $meta : null,
            'priority' => $priority,
            'due_at' => $dueRaw !== '' ? date('Y-m-d H:i:s', strtotime($dueRaw)) : null,
            'description' => $description !== '' ? $description : null,
        ]);
        if (!$changes) {
            $this->json(['ok' => true]);
        }

        $id = (int) $t['id'];
        // Only the assignee who has already read the ticket needs to be told
        // what changed — one who hasn't opened it yet will just see the new version.
        $notify = $t['opened_at'] !== null ? (int) $t['to_user_id'] : null;
        TicketChange::add($id, (int) $user['id'], $changes, $notify);
        TicketTimeline::add($id, 'ผู้มอบหมายแก้ไขตั๋วงาน: ' . implode(', ', array_column($changes, 'label')), (int) $user['id']);
        AuditLog::record((int) Auth::realUser()['id'], Auth::isImpersonating() ? (int) $user['id'] : null, 'ticket.update', 'ticket', $id, implode(',', array_column($changes, 'field')));

        $this->json(['ok' => true]);
    }

    public function acknowledgeChanges(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignee($t, $user);
        if (TicketChange::markSeen((int) $t['id'], (int) $user['id']) > 0) {
            TicketTimeline::add((int) $t['id'], 'ผู้รับมอบหมายรับทราบการแก้ไขตั๋วงาน', (int) $user['id']);
        }
        $this->json(['ok' => true]);
    }

    private function canEdit(array $t, array $user): bool
    {
        if (in_array($t['status'], ['done', 'forced'], true)) {
            return false;
        }
        // Whoever created the ticket may edit it, whatever role is active —
        // หัวหน้างาน creates tickets but isn't an is_assigner role.
        return (int) $t['from_user_id'] === (int) $user['id']
            || in_array('admin', User::rolesFor((int) $user['id']), true);
    }

    private function loadForAction(int $id): array
    {
        $t = Ticket::find($id);
        if (!$t) {
            $this->json(['ok' => false, 'error' => 'ไม่พบตั๋วงานนี้'], 404);
        }
        return $t;
    }

    private function assertAssignee(array $t, array $user): void
    {
        if ((int) $t['to_user_id'] !== (int) $user['id']) {
            $this->json(['ok' => false, 'error' => 'คุณไม่ใช่ผู้รับมอบหมายของงานนี้'], 403);
        }
    }

    private function assertAssignerOwner(array $t, array $user): void
    {
        $activeRole = Auth::activeRole() ?? 'staff';
        $isAdmin = in_array('admin', User::rolesFor((int) $user['id']), true);
        if (!Role::isAssigner($activeRole) || (!$isAdmin && (int) $t['from_user_id'] !== (int) $user['id'])) {
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
        Ticket::setStatus((int) $t['id'], 'submitted', (int) $user['id'], 'ส่งงาน / รายงานผลต่อผู้บริหาร');
        $this->json(['ok' => true]);
    }

    public function approve(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $t = $this->loadForAction((int) $args['id']);
        $this->assertAssignerOwner($t, $user);
        if (in_array($t['status'], ['done', 'forced'], true)) {
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
        Ticket::setStatus((int) $t['id'], 'forced', (int) $user['id'], 'สั่งปิดงานทันที (Force Close)');
        AuditLog::record((int) Auth::realUser()['id'], Auth::isImpersonating() ? (int) $user['id'] : null, 'ticket.force_close', 'ticket', (int) $t['id']);
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

        $link = Request::str('url');
        if ($link !== '') {
            $name = Request::str('link_name') ?: $link;
            TicketFile::addLink((int) $t['id'], $name, $link, (int) $user['id']);
            $this->json(['ok' => true]);
        }

        $file = Request::file('file');
        if (!$file) {
            $this->json(['ok' => false, 'error' => 'กรุณาเลือกไฟล์ หรือใส่ลิงก์'], 422);
        }
        $stored = Upload::store($file, (int) $t['id']);
        if (!$stored['ok']) {
            $this->json(['ok' => false, 'error' => $stored['error']], 422);
        }
        TicketFile::addUpload((int) $t['id'], $stored, (int) $user['id']);
        $this->json(['ok' => true]);
    }

    public function downloadFile(array $args): void
    {
        $this->requireLogin();
        $f = TicketFile::find((int) $args['fileId']);
        if (!$f || (int) $f['ticket_id'] !== (int) $args['id'] || $f['is_link']) {
            http_response_code(404);
            exit;
        }
        $path = Upload::absolutePath($f['stored_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($f['name']) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
