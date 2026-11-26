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
use App\Models\Picker;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\Setting;
use App\Models\TicketFile;
use App\Models\User;
use DateTimeImmutable;

/**
 * Meeting-room booking. Any signed-in user may request a room; the request
 * takes effect only once an approver (a named approver, หัวหน้างาน/เจ้าหน้าที่ of the media
 * งาน, or an admin — see RoomBooking::isApprover) approves it. Approvers
 * also maintain the rooms and their equipment on /rooms/manage.
 */
final class RoomController extends Controller
{
    private const THAI_MONTHS = [
        1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    /** Hours the week grid always shows; widened when a booking falls outside. */
    private const DAY_START_HOUR = 7;
    private const DAY_END_HOUR = 19;

    private function requireReady(): array
    {
        $user = $this->requireLogin();
        if (!RoomBooking::ready()) {
            if (Request::isAjax()) {
                $this->json(['ok' => false, 'error' => 'ระบบจองห้องประชุมยังไม่พร้อมใช้งาน (ผู้ดูแลระบบต้องรัน migration ก่อน)'], 503);
            }
            $this->page('rooms/not_ready', ['pageTitle' => 'จองห้องประชุม']);
        }
        return $user;
    }

    private function requireApprover(): array
    {
        $user = $this->requireReady();
        if (!RoomBooking::isApprover((int) $user['id'])) {
            if (Request::isAjax() || Request::method() === 'POST') {
                $this->json(['ok' => false, 'error' => 'เฉพาะผู้อนุมัติการใช้ห้องประชุมเท่านั้น'], 403);
            }
            $this->redirect('/rooms');
        }
        return $user;
    }

    private function isAdmin(): bool
    {
        $real = Auth::realUser();
        return $real && in_array('admin', User::rolesFor((int) $real['id']), true);
    }

    private function bookingOrFail(array $args): array
    {
        $b = RoomBooking::find((int) $args['id']);
        if (!$b) {
            $this->json(['ok' => false, 'error' => 'ไม่พบรายการจอง'], 404);
        }
        return $b;
    }

    private function actorIds(): array
    {
        $real = (int) Auth::realUser()['id'];
        $eff = (int) Auth::user()['id'];
        return [$real, $real !== $eff ? $eff : null];
    }

    // ------------------------------------------------------------------ calendar

    public function index(): void
    {
        $user = $this->requireReady();

        $view = Request::str('view') === 'week' ? 'week' : 'month';
        $raw = Request::str('date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: new DateTimeImmutable('today');

        $rooms = Room::all();
        $roomId = Request::int('room');
        if (!isset($rooms[$roomId])) {
            $roomId = 0;
        }

        if ($view === 'week') {
            // Weeks start on Monday — the working week reads left to right.
            $from = $date->modify('-' . (((int) $date->format('N')) - 1) . ' days');
            $to = $from->modify('+6 days');
            $prev = $from->modify('-7 days');
            $next = $from->modify('+7 days');
            $label = RoomBooking::thaiDate($from->format('Y-m-d')) . ' – ' . RoomBooking::thaiDate($to->format('Y-m-d'));
        } else {
            $first = $date->modify('first day of this month');
            $from = $first->modify('-' . (((int) $first->format('N')) - 1) . ' days');
            $last = $date->modify('last day of this month');
            $to = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');
            $prev = $first->modify('-1 month');
            $next = $first->modify('+1 month');
            $label = self::THAI_MONTHS[(int) $first->format('n')] . ' ' . ((int) $first->format('Y') + 543);
        }

        $bookings = RoomBooking::between($from->format('Y-m-d'), $to->format('Y-m-d'), $roomId ?: null);

        // Expand each booking into the days it occupies within the window.
        $byDay = [];
        $minHour = self::DAY_START_HOUR;
        $maxHour = self::DAY_END_HOUR;
        foreach ($bookings as $b) {
            $d = max($b['start_date'], $from->format('Y-m-d'));
            $end = min($b['end_date'], $to->format('Y-m-d'));
            for ($cur = new DateTimeImmutable($d); $cur->format('Y-m-d') <= $end; $cur = $cur->modify('+1 day')) {
                $byDay[$cur->format('Y-m-d')][] = $b;
            }
            $minHour = min($minHour, (int) substr($b['start_time'], 0, 2));
            $endH = (int) substr($b['end_time'], 0, 2) + ((int) substr($b['end_time'], 3, 2) > 0 ? 1 : 0);
            $maxHour = max($maxHour, $endH);
        }

        $this->page('rooms/calendar', [
            'pageTitle' => 'จองห้องประชุม',
            'mode' => $view,
            'label' => $label,
            'from' => $from,
            'to' => $to,
            'anchor' => $date,
            'monthOf' => $view === 'month' ? (int) $date->format('n') : null,
            'prevDate' => $prev->format('Y-m-d'),
            'nextDate' => $next->format('Y-m-d'),
            'rooms' => $rooms,
            'roomId' => $roomId,
            'byDay' => $byDay,
            'minHour' => max(0, $minHour),
            'maxHour' => min(24, $maxHour),
            'userId' => (int) $user['id'],
            'isApprover' => RoomBooking::isApprover((int) $user['id']),
        ]);
    }

    // ------------------------------------------------------------------ requests

    public function newForm(): void
    {
        $user = $this->requireReady();
        $uid = (int) $user['id'];
        $isApprover = RoomBooking::isApprover($uid);
        $rooms = Room::all(true);
        $date = Request::str('date');
        // Approvers may record past use, so they may open the form on a past day.
        if (!DateTimeImmutable::createFromFormat('!Y-m-d', $date) || (!$isApprover && $date < date('Y-m-d'))) {
            $date = date('Y-m-d');
        }
        $start = Request::str('start');
        if (!preg_match('/^([01]\d|2[0-3]):00$/', $start)) {
            $start = '09:00';
        }
        // Bookings run in whole hours (RoomBooking::FIRST_HOUR … LAST_HOUR).
        $startH = (int) substr($start, 0, 2);
        $now = new DateTimeImmutable();
        if (!$isApprover && new DateTimeImmutable($date . ' ' . $start) < $now) {
            // The slot (or "today" with the default 09:00) is already under
            // way: start at the next whole hour instead.
            $startH = (int) $now->format('G') + 1;
        }
        $startH = max(RoomBooking::FIRST_HOUR, min(RoomBooking::LAST_HOUR - 1, $startH));

        $this->html(View::render('partials/room_booking_form', [
            'rooms' => $rooms,
            'roomId' => Request::int('room'),
            'date' => $date,
            'start' => sprintf('%02d:00', $startH),
            'end' => sprintf('%02d:00', $startH + 1),
            'approvers' => RoomBooking::approvers(),
            'isApprover' => $isApprover,
            'user' => $user,
            'personItems' => $isApprover ? Picker::people() : [],
            'graphicsReady' => RoomBooking::graphicsReady(),
        ]));
    }

    public function store(): void
    {
        $user = $this->requireReady();
        Csrf::verifyRequestOrFail();
        $uid = (int) $user['id'];
        $isApprover = RoomBooking::isApprover($uid);

        $v = RoomBooking::validate($_POST, Room::all(true), $isApprover);
        if (is_string($v)) {
            $this->json(['ok' => false, 'error' => $v]);
        }

        // An approver may record the booking for someone else (the person
        // who actually used / will use the room).
        $forId = $uid;
        if ($isApprover && Request::int('for_user_id') > 0) {
            $for = User::findById(Request::int('for_user_id'));
            if (!$for || !(int) $for['is_active']) {
                $this->json(['ok' => false, 'error' => 'กรุณาเลือกผู้ใช้ห้องจากรายการ']);
            }
            $forId = (int) $for['id'];
        }

        // Sign-graphic reference files: checked up front so a bad file
        // refuses the whole request instead of leaving it half-saved.
        $files = $v['graphic_request'] !== null ? $this->uploadedFiles('graphic_files') : [];
        if (count($files) > RoomBooking::MAX_GRAPHIC_FILES) {
            $this->json(['ok' => false, 'error' => 'แนบไฟล์ได้ไม่เกิน ' . RoomBooking::MAX_GRAPHIC_FILES . ' ไฟล์']);
        }
        foreach ($files as $file) {
            if (($error = Upload::check($file)) !== null) {
                $this->json(['ok' => false, 'error' => $error]);
            }
        }

        $id = RoomBooking::create($forId, $v);

        $stored = [];
        foreach ($files as $file) {
            $s = Upload::storeIn($file, RoomBooking::fileDir($id));
            if (!$s['ok']) {
                foreach ($stored as $path) {
                    @unlink(Upload::absolutePath($path));
                }
                RoomBooking::delete($id);
                $this->json(['ok' => false, 'error' => $s['error'] ?? 'อัปโหลดไฟล์ล้มเหลว']);
            }
            $stored[] = $s['storedPath'];
            RoomBooking::addFile($id, $s, $uid);
        }

        // What an approver records is approved at once — there is nobody
        // else to ask. approve() re-checks clashes under a room lock.
        if ($isApprover) {
            $error = RoomBooking::approve($id, $uid, null);
            if ($error !== null) {
                foreach ($stored as $path) {
                    @unlink(Upload::absolutePath($path));
                }
                RoomBooking::delete($id);
                $this->json(['ok' => false, 'error' => $error]);
            }
            [$actor, $as] = $this->actorIds();
            $b = RoomBooking::find($id);
            AuditLog::record($actor, $as, 'room_booking.record', 'room_booking', $id,
                $b['room_name'] . ' · ' . RoomBooking::rangeLabel($b) . ($forId !== $uid ? ' · ผู้ใช้ห้อง ' . $b['user_name'] : ''));
        }
        $this->json(['ok' => true, 'id' => $id]);
    }

    /** @return array<int,array> the non-empty entries of a multiple file input, one array per file */
    private function uploadedFiles(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (!$f || !is_array($f['name'] ?? null)) {
            return [];
        }
        $out = [];
        for ($i = 0, $n = count($f['name']); $i < $n; $i++) {
            if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string) $f['name'][$i],
                'type' => $f['type'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /** A sign-graphic reference file — for the requester, the room user and approvers. */
    public function downloadFile(array $args): void
    {
        $user = $this->requireReady();
        $b = RoomBooking::find((int) $args['id']);
        $f = RoomBooking::findFile((int) $args['fileId']);
        if (!$b || !$f || (int) $f['booking_id'] !== (int) $b['id']) {
            http_response_code(404);
            exit;
        }
        if (!$this->canSeeGraphic($b, (int) $user['id'])) {
            http_response_code(403);
            exit;
        }
        $path = Upload::absolutePath($f['stored_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        $inline = Request::str('inline') === '1' && in_array((string) $f['mime'], TicketFile::PREVIEW_MIMES, true);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']) ?: 'file';
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    private function canSeeGraphic(array $b, int $uid): bool
    {
        return (int) $b['user_id'] === $uid || RoomBooking::isApprover($uid);
    }

    public function show(array $args): void
    {
        $user = $this->requireReady();
        $b = $this->bookingOrFail($args);
        $uid = (int) $user['id'];
        $isApprover = RoomBooking::isApprover($uid);

        $clashes = [];
        if (in_array($b['status'], ['pending', 'approved'], true)) {
            $clashes = RoomBooking::clashes(
                (int) $b['room_id'], $b['start_date'], $b['end_date'], $b['start_time'], $b['end_time'],
                (int) $b['id'], ['pending', 'approved']
            );
        }
        $this->html(View::render('partials/room_booking_detail', [
            'b' => $b,
            'isOwner' => (int) $b['user_id'] === $uid,
            'isApprover' => $isApprover,
            'clashes' => $clashes,
            'room' => Room::find((int) $b['room_id']),
            'canSeeGraphic' => $this->canSeeGraphic($b, $uid),
            'files' => RoomBooking::files((int) $b['id']),
        ]));
    }

    public function approve(array $args): void
    {
        $user = $this->requireApprover();
        Csrf::verifyRequestOrFail();
        $b = $this->bookingOrFail($args);

        $note = trim(Request::str('note')) ?: null;
        $error = RoomBooking::approve((int) $b['id'], (int) $user['id'], $note);
        if ($error !== null) {
            $this->json(['ok' => false, 'error' => $error]);
        }
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, 'room_booking.approve', 'room_booking', (int) $b['id'], $b['room_name'] . ' · ' . RoomBooking::rangeLabel($b));
        $this->json(['ok' => true]);
    }

    public function reject(array $args): void
    {
        $user = $this->requireApprover();
        Csrf::verifyRequestOrFail();
        $b = $this->bookingOrFail($args);

        $note = trim(Request::str('note'));
        if ($note === '') {
            $this->json(['ok' => false, 'error' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
        }
        if (!RoomBooking::reject((int) $b['id'], (int) $user['id'], mb_substr($note, 0, 500))) {
            $this->json(['ok' => false, 'error' => 'คำขอนี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว']);
        }
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, 'room_booking.reject', 'room_booking', (int) $b['id'], $b['room_name'] . ' · ' . RoomBooking::rangeLabel($b));
        $this->json(['ok' => true]);
    }

    /**
     * The requester may withdraw their own pending/approved booking. An
     * approver may also revoke one (e.g. the room is needed for an official
     * event) but must say why.
     */
    public function cancel(array $args): void
    {
        $user = $this->requireReady();
        Csrf::verifyRequestOrFail();
        $b = $this->bookingOrFail($args);
        $uid = (int) $user['id'];

        $isOwner = (int) $b['user_id'] === $uid;
        if (!$isOwner && !RoomBooking::isApprover($uid)) {
            $this->json(['ok' => false, 'error' => 'ไม่มีสิทธิ์ยกเลิกรายการนี้'], 403);
        }
        $note = trim(Request::str('note'));
        if (!$isOwner && $note === '') {
            $this->json(['ok' => false, 'error' => 'กรุณาระบุเหตุผลที่ยกเลิก']);
        }
        if ($b['end_date'] < date('Y-m-d')) {
            $this->json(['ok' => false, 'error' => 'การจองนี้ผ่านไปแล้ว']);
        }
        if (!RoomBooking::cancel((int) $b['id'], $uid, $note !== '' ? mb_substr($note, 0, 500) : null)) {
            $this->json(['ok' => false, 'error' => 'รายการนี้ถูกยกเลิกหรือไม่อนุมัติไปแล้ว']);
        }
        if (!$isOwner) {
            [$actor, $as] = $this->actorIds();
            AuditLog::record($actor, $as, 'room_booking.revoke', 'room_booking', (int) $b['id'], $b['room_name'] . ' · ' . RoomBooking::rangeLabel($b));
        }
        $this->json(['ok' => true]);
    }

    /** My requests, and — for approvers — the queue waiting on a decision. */
    public function requests(): void
    {
        $user = $this->requireReady();
        $uid = (int) $user['id'];
        $isApprover = RoomBooking::isApprover($uid);

        $this->page('rooms/requests', [
            'pageTitle' => 'คำขอใช้ห้องประชุม',
            'mine' => RoomBooking::forUser($uid),
            'isApprover' => $isApprover,
            'pending' => $isApprover ? RoomBooking::pending() : [],
            'decided' => $isApprover ? RoomBooking::recentlyDecided() : [],
            'tab' => Request::str('tab'),
        ]);
    }

    // ------------------------------------------------------------------ manage rooms

    public function manage(): void
    {
        $this->requireApprover();
        $isAdmin = $this->isAdmin();
        $named = RoomBooking::namedApprovers();
        $personItems = [];
        if ($isAdmin && RoomBooking::namedApproversReady()) {
            $namedIds = array_flip(array_map('strval', array_column($named, 'id')));
            $personItems = array_values(array_filter(Picker::people(), static fn ($p) => !isset($namedIds[$p['v']])));
        }
        $this->page('rooms/manage', [
            'pageTitle' => 'จัดการห้องประชุม',
            'rooms' => Room::all(),
            'equipment' => Room::equipmentTypes(),
            'isAdmin' => $isAdmin,
            'works' => OrgUnit::all('work'),
            'mediaWorkId' => RoomBooking::mediaWorkId(),
            'mediaGuessed' => RoomBooking::mediaWorkIsGuessed(),
            'approvers' => RoomBooking::mediaWorkApprovers(),
            'namedReady' => RoomBooking::namedApproversReady(),
            'namedApprovers' => $named,
            'personItems' => $personItems,
            'notice' => Request::str('notice'),
            'error' => Request::str('error'),
        ]);
    }

    public function saveRoom(array $args): void
    {
        $this->requireApprover();
        Csrf::verifyRequestOrFail();
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($id !== null && !Room::find($id)) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ไม่พบห้องประชุม'));
        }

        $name = trim(Request::str('name'));
        if ($name === '') {
            $this->redirect('/rooms/manage?error=' . rawurlencode('กรุณากรอกชื่อห้อง'));
        }
        if (Room::nameTaken($name, $id)) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('มีห้องชื่อ "' . $name . '" อยู่แล้ว'));
        }
        $color = Request::str('color');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = Room::COLORS[0];
        }
        $capacity = Request::int('capacity');
        $isActive = Request::str('is_active') === '1';
        $inactiveReason = mb_substr(trim(Request::str('inactive_reason')), 0, 255);
        if (!$isActive && $inactiveReason === '' && Room::hasInactiveReason()) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('กรุณาระบุสาเหตุที่ปิดการใช้งานห้อง "' . $name . '"') . ($id ? '#room-' . $id : ''));
        }

        $types = Room::equipmentTypes();
        $qty = (array) ($_POST['qty'] ?? []);
        $equipment = [];
        foreach ((array) ($_POST['equipment'] ?? []) as $eid) {
            $eid = (int) $eid;
            if (isset($types[$eid])) {
                $equipment[$eid] = max(1, min(999, (int) ($qty[$eid] ?? 1)));
            }
        }

        $id = Room::save($id, [
            'name' => mb_substr($name, 0, 150),
            'location' => mb_substr(trim(Request::str('location')), 0, 255),
            'capacity' => $capacity > 0 ? min($capacity, 65535) : null,
            'description' => trim(Request::str('description')),
            'color' => strtolower($color),
            'is_active' => $isActive,
            'inactive_reason' => $inactiveReason,
            'sort_order' => Request::int('sort_order'),
        ], $equipment);

        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, isset($args['id']) ? 'room.update' : 'room.create', 'meeting_room', $id, $name);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('บันทึกห้อง "' . $name . '" แล้ว') . '#room-' . $id);
    }

    public function deleteRoom(array $args): void
    {
        $this->requireApprover();
        Csrf::verifyRequestOrFail();
        $room = Room::find((int) $args['id']);
        if (!$room) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ไม่พบห้องประชุม'));
        }
        if (!Room::delete((int) $room['id'])) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ห้อง "' . $room['name'] . '" มีประวัติการจองแล้ว ลบไม่ได้ — ให้ปิดการใช้งานแทน'));
        }
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, 'room.delete', 'meeting_room', (int) $room['id'], $room['name']);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('ลบห้อง "' . $room['name'] . '" แล้ว'));
    }

    public function saveEquipment(array $args): void
    {
        $this->requireApprover();
        Csrf::verifyRequestOrFail();
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $types = Room::equipmentTypes();
        if ($id !== null && !isset($types[$id])) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ไม่พบอุปกรณ์'));
        }
        $name = mb_substr(trim(Request::str('name')), 0, 150);
        if ($name === '') {
            $this->redirect('/rooms/manage?error=' . rawurlencode('กรุณากรอกชื่ออุปกรณ์') . '#equipment');
        }
        if (Room::equipmentNameTaken($name, $id)) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('มีอุปกรณ์ชื่อ "' . $name . '" อยู่แล้ว') . '#equipment');
        }
        $icon = Request::str('icon');
        if (!isset(Room::ICONS[$icon])) {
            $icon = 'tools';
        }
        Room::saveEquipment($id, $name, $icon);
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, $id === null ? 'room_equipment.create' : 'room_equipment.update', 'room_equipment', $id, $name);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('บันทึกอุปกรณ์ "' . $name . '" แล้ว') . '#equipment');
    }

    public function deleteEquipment(array $args): void
    {
        $this->requireApprover();
        Csrf::verifyRequestOrFail();
        $types = Room::equipmentTypes();
        $e = $types[(int) $args['id']] ?? null;
        if (!$e) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ไม่พบอุปกรณ์') . '#equipment');
        }
        if (!Room::deleteEquipment((int) $e['id'])) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('อุปกรณ์ "' . $e['name'] . '" ยังมีห้องหรือการจองที่ใช้อยู่ ลบไม่ได้') . '#equipment');
        }
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, 'room_equipment.delete', 'room_equipment', (int) $e['id'], $e['name']);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('ลบอุปกรณ์ "' . $e['name'] . '" แล้ว') . '#equipment');
    }

    /** Which งาน's หัวหน้างาน/เจ้าหน้าที่ approve bookings — admins only. */
    public function setMediaWork(): void
    {
        $this->requireReady();
        if (!$this->isAdmin()) {
            $this->redirect('/rooms');
        }
        Csrf::verifyRequestOrFail();
        $workId = Request::int('work_id');
        if ($workId > 0 && !OrgUnit::find('work', $workId)) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ไม่พบงานที่เลือก') . '#approvers');
        }
        Setting::set(RoomBooking::SETTING_MEDIA_WORK, (string) max(0, $workId));
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, 'room.media_work', 'work', $workId ?: null, null);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('บันทึกงานผู้อนุมัติแล้ว') . '#approvers');
    }

    /**
     * Name a person as an approver for every room — admins only, since an
     * approver could otherwise hand the right on to anyone.
     */
    public function addApprover(): void
    {
        $this->requireReady();
        if (!$this->isAdmin()) {
            $this->redirect('/rooms');
        }
        Csrf::verifyRequestOrFail();
        if (!RoomBooking::namedApproversReady()) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ต้องรัน migration 20261126_add_room_approvers.sql ก่อน') . '#approvers');
        }
        $target = User::findById(Request::int('user_id'));
        if (!$target || !(int) $target['is_active']) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('กรุณาเลือกบุคคลจากรายการ') . '#approvers');
        }
        [$actor, $as] = $this->actorIds();
        if (!RoomBooking::addNamedApprover((int) $target['id'], $actor)) {
            $this->redirect('/rooms/manage?notice=' . rawurlencode($target['full_name'] . ' เป็นผู้อนุมัติอยู่แล้ว') . '#approvers');
        }
        AuditLog::record($actor, $as, 'room.approver_add', 'user', (int) $target['id'], $target['full_name']);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('เพิ่ม ' . $target['full_name'] . ' เป็นผู้อนุมัติแล้ว') . '#approvers');
    }

    public function removeApprover(array $args): void
    {
        $this->requireReady();
        if (!$this->isAdmin()) {
            $this->redirect('/rooms');
        }
        Csrf::verifyRequestOrFail();
        $target = User::findById((int) $args['id']);
        $name = $target['full_name'] ?? ('#' . (int) $args['id']);
        if (!RoomBooking::namedApproversReady() || !RoomBooking::removeNamedApprover((int) $args['id'])) {
            $this->redirect('/rooms/manage?error=' . rawurlencode('ไม่พบผู้อนุมัติที่เลือก') . '#approvers');
        }
        [$actor, $as] = $this->actorIds();
        AuditLog::record($actor, $as, 'room.approver_remove', 'user', (int) $args['id'], $name);
        $this->redirect('/rooms/manage?notice=' . rawurlencode('นำ ' . $name . ' ออกจากผู้อนุมัติแล้ว') . '#approvers');
    }
}
