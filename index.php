<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\AvatarController;
use App\Controllers\CalendarController;
use App\Controllers\DashboardController;
use App\Controllers\OrgController;
use App\Controllers\ProfileController;
use App\Controllers\SettingsController;
use App\Controllers\TicketController;
use App\Core\Request;
use App\Core\Router;

$router = new Router();

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/board', [DashboardController::class, 'board']);

$router->get('/calendar', [CalendarController::class, 'index']);

$router->post('/role-switch', [ProfileController::class, 'switchRole']);
$router->get('/preferences', [ProfileController::class, 'preferences']);
$router->post('/preferences', [ProfileController::class, 'updatePreferences']);

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

$router->get('/admin/users', [AdminController::class, 'manageUsers']);
$router->get('/admin/users/{id}/roles', [AdminController::class, 'editRolesForm']);
$router->post('/admin/users/{id}/roles', [AdminController::class, 'updateRoles']);
$router->post('/admin/impersonate/{id}', [AdminController::class, 'impersonate']);
$router->post('/admin/impersonate-stop', [AdminController::class, 'stopImpersonating']);
$router->get('/admin/audit', [AdminController::class, 'auditLog']);

$router->get('/avatar/{id}', [AvatarController::class, 'show']);

$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings/url', [SettingsController::class, 'updateUrl']);
$router->post('/admin/settings/sync', [SettingsController::class, 'sync']);
$router->post('/admin/settings/notify', [SettingsController::class, 'updateNotify']);

// {type} is one of OrgUnit::TYPES — validated in the controller before use.
$router->get('/admin/org', [OrgController::class, 'index']);
$router->post('/admin/org/{type}', [OrgController::class, 'store']);
$router->post('/admin/org/{type}/{id}', [OrgController::class, 'update']);
$router->post('/admin/org/{type}/{id}/delete', [OrgController::class, 'delete']);

$router->dispatch(Request::method(), Request::path());
