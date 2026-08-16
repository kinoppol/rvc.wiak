<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;
use App\Models\Todo;

final class TodoController extends Controller
{
    public function index(): void
    {
        $user = $this->requireLogin();
        $uid  = (int) $user['id'];

        Todo::advanceMissed($uid);

        $todos = Todo::forUser($uid);

        $now      = new \DateTimeImmutable();
        $todayEnd = new \DateTimeImmutable('today 23:59:59');
        $soonEnd  = new \DateTimeImmutable('+7 days 23:59:59');

        $groups = ['overdue' => [], 'today' => [], 'soon' => [], 'later' => [], 'nodue' => [], 'done' => []];
        foreach ($todos as $t) {
            if ($t['is_done']) {
                $groups['done'][] = $t;
            } elseif (!$t['due_at']) {
                $groups['nodue'][] = $t;
            } else {
                $due = new \DateTimeImmutable($t['due_at']);
                if ($due < $now) {
                    $groups['overdue'][] = $t;
                } elseif ($due <= $todayEnd) {
                    $groups['today'][] = $t;
                } elseif ($due <= $soonEnd) {
                    $groups['soon'][] = $t;
                } else {
                    $groups['later'][] = $t;
                }
            }
        }

        $this->page('todos', [
            'pageTitle' => 'งานส่วนตัว (To-Do)',
            'groups'    => $groups,
        ]);
    }

    public function newForm(): void
    {
        $this->requireLogin();
        $this->html(View::render('partials/todo_form', ['todo' => null]));
    }

    public function editForm(array $args): void
    {
        $user = $this->requireLogin();
        $id = (int) $args['id'];
        $todo = Todo::find($id);
        if (!$todo || (int) $todo['user_id'] !== (int) $user['id']) {
            $this->json(['ok' => false, 'error' => 'ไม่พบรายการ'], 404);
        }
        $logs = Todo::recentLogs($id);
        $this->html(View::render('partials/todo_form', ['todo' => $todo, 'logs' => $logs]));
    }

    public function store(): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();

        $title = trim(Request::str('title'));
        if ($title === '') {
            $this->json(['ok' => false, 'error' => 'กรุณากรอกชื่องาน']);
        }

        Todo::create((int) $user['id'], $_POST);
        $this->json(['ok' => true]);
    }

    public function update(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();

        $id = (int) $args['id'];
        $todo = Todo::find($id);
        if (!$todo || (int) $todo['user_id'] !== (int) $user['id']) {
            $this->json(['ok' => false, 'error' => 'ไม่พบรายการ'], 404);
        }

        $title = trim(Request::str('title'));
        if ($title === '') {
            $this->json(['ok' => false, 'error' => 'กรุณากรอกชื่องาน']);
        }

        Todo::update($id, $_POST);
        $this->json(['ok' => true]);
    }

    public function markDone(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $ok = Todo::markDone((int) $args['id'], (int) $user['id']);
        $this->json($ok ? ['ok' => true] : ['ok' => false, 'error' => 'ไม่พบรายการ'], $ok ? 200 : 404);
    }

    public function markMiss(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $ok = Todo::markMiss((int) $args['id'], (int) $user['id']);
        $this->json($ok ? ['ok' => true] : ['ok' => false, 'error' => 'ไม่พบรายการ'], $ok ? 200 : 404);
    }

    public function delete(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $ok = Todo::delete((int) $args['id'], (int) $user['id']);
        $this->json($ok ? ['ok' => true] : ['ok' => false, 'error' => 'ไม่พบรายการ'], $ok ? 200 : 404);
    }
}
