<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;

final class ProfileController extends Controller
{
    public function switchRole(): void
    {
        $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $role = Request::str('role');
        $ok = Auth::setActiveRole($role);
        $this->json(['ok' => $ok]);
    }
}
