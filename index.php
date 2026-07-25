<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ProfileController;
use App\Controllers\TicketController;
use App\Core\Request;
use App\Core\Router;

$router = new Router();

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/board', [DashboardController::class, 'board']);

$router->post('/role-switch', [ProfileController::class, 'switchRole']);

$router->get('/tickets/new', [TicketController::class, 'newForm']);
$router->post('/tickets', [TicketController::class, 'store']);
$router->get('/tickets/{id}', [TicketController::class, 'show']);
$router->post('/tickets/{id}/acknowledge', [TicketController::class, 'acknowledge']);
$router->post('/tickets/{id}/start', [TicketController::class, 'start']);
$router->post('/tickets/{id}/request-review', [TicketController::class, 'requestReview']);
$router->post('/tickets/{id}/submit', [TicketController::class, 'submit']);
$router->post('/tickets/{id}/approve', [TicketController::class, 'approve']);
$router->post('/tickets/{id}/force-close', [TicketController::class, 'forceClose']);
$router->post('/tickets/{id}/reassign', [TicketController::class, 'reassign']);
$router->post('/tickets/{id}/questions', [TicketController::class, 'addQuestion']);
$router->post('/tickets/{id}/questions/{qid}/answer', [TicketController::class, 'answerQuestion']);
$router->post('/tickets/{id}/files', [TicketController::class, 'uploadFile']);
$router->get('/tickets/{id}/files/{fileId}', [TicketController::class, 'downloadFile']);

$router->get('/admin/impersonate', [AdminController::class, 'impersonatePicker']);
$router->post('/admin/impersonate/{id}', [AdminController::class, 'impersonate']);
$router->post('/admin/impersonate-stop', [AdminController::class, 'stopImpersonating']);
$router->get('/admin/audit', [AdminController::class, 'auditLog']);

$router->dispatch(Request::method(), Request::path());
