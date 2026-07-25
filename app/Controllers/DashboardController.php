<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\View;
use App\Models\Role;
use App\Models\Ticket;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = $this->requireLogin();
        $activeRole = Auth::activeRole() ?? 'staff';
        $vars = $this->boardVars($activeRole);
        $vars['pageTitle'] = 'Dashboard ภาพรวม';
        $this->page('dashboard', $vars);
    }

    public function board(): void
    {
        $this->requireLogin();
        $activeRole = Auth::activeRole() ?? 'staff';
        $vars = $this->boardVars($activeRole);
        $this->html(View::render('partials/board', $vars));
    }

    private function boardVars(string $activeRole): array
    {
        $filter = Request::str('filter', 'all');
        $q = Request::str('q', '');

        $rows = Ticket::search(['filter' => $filter, 'q' => $q]);
        $stats = Ticket::stats();

        return [
            'rows' => $rows,
            'stats' => $stats,
            'filter' => $filter,
            'q' => $q,
            'isAssigner' => Role::isAssigner($activeRole),
        ];
    }
}
