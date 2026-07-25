<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function requireLogin(): array
    {
        $user = Auth::user();
        if (!$user) {
            if (Request::isAjax()) {
                $this->json(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบ'], 401);
            }
            header('Location: ' . Url::to('/login'));
            exit;
        }
        return $user;
    }

    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    protected function page(string $view, array $vars = []): void
    {
        $this->html(View::layout($view, $vars));
    }

    protected function redirect(string $to): void
    {
        header('Location: ' . Url::to($to));
        exit;
    }
}
