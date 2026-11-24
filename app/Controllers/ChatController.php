<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;
use App\Models\Chat;
use App\Models\Picker;
use App\Models\User;

/**
 * Direct messages. Reading while impersonating is allowed but never marks
 * anything read, and sending is refused: an admin must not speak as someone else.
 */
final class ChatController extends Controller
{
    public function index(): void
    {
        $user = $this->requireLogin();
        $this->renderPage($user, null);
    }

    public function show(array $args): void
    {
        $user = $this->requireLogin();
        $id = (int) $args['id'];
        if (!Chat::ready() || !Chat::isMember($id, (int) $user['id'])) {
            $this->redirect('/chat');
        }
        $this->renderPage($user, $id);
    }

    public function start(): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $this->assertCanWrite();
        $other = User::findById(Request::int('to_user_id'));
        if (!$other || (int) $other['id'] === (int) $user['id'] || (int) $other['is_active'] !== 1) {
            $this->redirect('/chat');
        }
        $this->redirect('/chat/' . Chat::directWith((int) $user['id'], (int) $other['id']));
    }

    /** POST body (+ after = last message the page shows) → the new messages as HTML. */
    public function send(array $args): void
    {
        $user = $this->requireLogin();
        Csrf::verifyRequestOrFail();
        $this->assertCanWrite();
        $id = $this->memberConversation($args, $user);
        $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
        if ($body === '') {
            $this->json(['ok' => false, 'error' => 'กรุณาพิมพ์ข้อความ'], 422);
        }
        if (mb_strlen($body) > Chat::MAX_BODY) {
            $this->json(['ok' => false, 'error' => 'ข้อความยาวเกิน ' . number_format(Chat::MAX_BODY) . ' ตัวอักษร'], 422);
        }
        Chat::send($id, (int) $user['id'], $body);
        $this->json(['ok' => true] + $this->newMessages($id, $user, max(0, Request::int('after'))));
    }

    /** GET ?after= → messages since then; marks them read for the reader. */
    public function poll(array $args): void
    {
        $user = $this->requireLogin();
        $id = $this->memberConversation($args, $user);
        header('Cache-Control: no-store');
        $this->json(['ok' => true] + $this->newMessages($id, $user, max(0, Request::int('after'))));
    }

    /** GET ?q= → tickets the user may mention. */
    public function tickets(): void
    {
        $user = $this->requireLogin();
        header('Cache-Control: no-store');
        if (!Chat::ready()) {
            $this->json(['ok' => true, 'items' => []]);
        }
        $this->json(['ok' => true, 'items' => Chat::searchTickets((int) $user['id'], $this->isAdmin($user), Request::str('q'))]);
    }

    private function renderPage(array $user, ?int $activeId): void
    {
        $ready = Chat::ready();
        $me = (int) $user['id'];
        $vars = [
            'pageTitle' => 'สนทนา',
            'ready' => $ready,
            'conversations' => $ready ? Chat::conversationsFor($me) : [],
            'activeId' => $activeId,
            'other' => null,
            'messagesHtml' => '',
            'lastId' => 0,
            'canWrite' => !Auth::isImpersonating(),
            'personItems' => $ready ? Picker::people($me, $me, Auth::activeRole()) : [],
        ];
        if ($activeId !== null) {
            $vars['other'] = Chat::otherMember($activeId, $me);
            $rendered = $this->newMessages($activeId, $user, 0);
            $vars['messagesHtml'] = $rendered['html'];
            $vars['lastId'] = $rendered['last_id'];
            // The list was read before marking this conversation read.
            foreach ($vars['conversations'] as &$c) {
                if ((int) $c['id'] === $activeId && !Auth::isImpersonating()) {
                    $c['unread'] = 0;
                }
            }
            unset($c);
        }
        $this->page('chat', $vars);
    }

    /** @return array{html:string,last_id:int} */
    private function newMessages(int $conversationId, array $user, int $after): array
    {
        $me = (int) $user['id'];
        $messages = Chat::messages($conversationId, $after);
        $last = $after;
        foreach ($messages as $m) {
            $last = max($last, (int) $m['id']);
        }
        if ($last > 0 && !Auth::isImpersonating()) {
            Chat::markRead($conversationId, $me, $last);
        }
        $refs = Chat::ticketRefs(array_column($messages, 'body'), $me, $this->isAdmin($user));
        return [
            'html' => View::render('partials/chat_messages', ['messages' => $messages, 'me' => $me, 'refs' => $refs]),
            'last_id' => $last,
        ];
    }

    private function memberConversation(array $args, array $user): int
    {
        $id = (int) $args['id'];
        if (!Chat::ready() || !Chat::isMember($id, (int) $user['id'])) {
            $this->json(['ok' => false, 'error' => 'ไม่พบบทสนทนานี้'], 404);
        }
        return $id;
    }

    private function assertCanWrite(): void
    {
        if (Auth::isImpersonating()) {
            $this->json(['ok' => false, 'error' => 'ไม่สามารถส่งข้อความระหว่างสวมสิทธิ์ผู้ใช้อื่น'], 403);
        }
        if (!Chat::ready()) {
            $this->json(['ok' => false, 'error' => 'ระบบสนทนายังไม่พร้อม ผู้ดูแลระบบต้องรัน migration ก่อน'], 503);
        }
    }

    private function isAdmin(array $user): bool
    {
        return in_array('admin', User::rolesFor((int) $user['id']), true);
    }
}
