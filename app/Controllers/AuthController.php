<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Oa;
use App\Core\Request;
use App\Models\User;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::user()) {
            $this->redirect('/');
        }
        $this->page('auth/login', ['error' => null, 'oaEnabled' => Oa::isEnabled()]);
    }

    public function login(): void
    {
        Csrf::verifyRequestOrFail();
        $username = Request::str('username');
        $password = (string) Request::input('password', '');

        $user = User::findByUsername($username);
        if (!$user || !$user['is_active'] || !User::verifyPassword($user, $password)) {
            $this->page('auth/login', ['error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'oaEnabled' => Oa::isEnabled()]);
            return;
        }
        Auth::login($user);
        $this->redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login');
    }
}
