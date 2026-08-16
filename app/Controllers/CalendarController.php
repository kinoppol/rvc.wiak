<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\OrgUnit;
use App\Models\Ticket;

final class CalendarController extends Controller
{
    private const THAI_MONTHS = [
        1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    public function index(): void
    {
        $user = $this->requireLogin();

        // ?month=YYYY-MM, defaulting to the current month.
        $raw = Request::str('month');
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            $year = (int) $m[1];
            $month = max(1, min(12, (int) $m[2]));
        } else {
            $year = (int) date('Y');
            $month = (int) date('n');
        }

        // Parse optional unit filter: ?unit=work:3 or ?unit=department:2
        $activeUnit = null;
        $rawUnit = Request::str('unit');
        if (preg_match('/^(work|department|division):(\d+)$/', $rawUnit, $um) && OrgUnit::isType($um[1])) {
            $activeUnit = ['type' => $um[1], 'id' => (int) $um[2]];
        }
        $unitParam = $activeUnit ? ('&unit=' . $activeUnit['type'] . ':' . $activeUnit['id']) : '';

        // Units this user belongs to — used to render the filter pills.
        $userUnits = OrgUnit::unitsForUser((int) $user['id']);

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $daysInMonth = (int) $first->format('t');

        // Group this month's tickets by day-of-month so the grid can index by day.
        $byDay = [];
        foreach (Ticket::forMonth((int) $user['id'], $year, $month, $activeUnit['type'] ?? null, $activeUnit['id'] ?? null) as $t) {
            $day = (int) (new \DateTimeImmutable($t['due_at']))->format('j');
            $t['isMine'] = (int) $t['to_user_id'] === (int) $user['id'];
            $byDay[$day][] = $t;
        }

        $this->page('calendar', [
            'pageTitle' => 'ปฏิทินกำหนดส่งงาน',
            'year' => $year,
            'month' => $month,
            'monthLabel' => self::THAI_MONTHS[$month] . ' ' . ($year + 543),
            'leadingBlanks' => (int) $first->format('w'), // 0 = Sunday
            'daysInMonth' => $daysInMonth,
            'byDay' => $byDay,
            'prevMonth' => $first->modify('-1 month')->format('Y-n'),
            'nextMonth' => $first->modify('+1 month')->format('Y-n'),
            'todayDay' => ($year === (int) date('Y') && $month === (int) date('n')) ? (int) date('j') : null,
            'userUnits' => $userUnits,
            'activeUnit' => $activeUnit,
            'unitParam' => $unitParam,
        ]);
    }
}
