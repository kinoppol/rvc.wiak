<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\AvatarController;
use App\Controllers\CalendarController;
use App\Controllers\DashboardController;
use App\Controllers\DesktopApiController;
use App\Controllers\DesktopController;
use App\Controllers\DesktopReleaseController;
use App\Controllers\MigrationController;
use App\Controllers\OaAuthController;
use App\Controllers\OrgController;
use App\Controllers\OrgImportController;
use App\Controllers\ProfileController;
use App\Controllers\RoomController;
use App\Controllers\SettingsController;
use App\Controllers\TicketController;
use App\Controllers\TodoController;
use App\Core\Request;
use App\Core\Router;

$router = new Router();

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

// Open Authenticator (SSO). The registered redirect_uri is
// https://wiak.rvc.ac.th/web/api/callback.php ; the app's base path in
// production is "/web", which Request::path() strips, so the route is
// registered app-relative as /api/callback.php.
$router->get('/auth/oa/start', [OaAuthController::class, 'start']);
// The RVC-ONE app tile / SSO start URL is configured as .../web/api/login.php
// (same api/*.php convention as the callback) — alias of /auth/oa/start.
$router->get('/api/login.php', [OaAuthController::class, 'start']);
$router->get('/api/callback.php', [OaAuthController::class, 'callback']);
$router->post('/api/callback.php', [OaAuthController::class, 'callback']);

// Windows desktop notifier: JSON API (bearer token, no session) and browser side.
$router->post('/api/desktop/token', [DesktopApiController::class, 'token']);
$router->get('/api/desktop/notifications', [DesktopApiController::class, 'notifications']);
$router->post('/api/desktop/notifications/read', [DesktopApiController::class, 'markRead']);
$router->post('/api/desktop/revoke', [DesktopApiController::class, 'revoke']);
$router->get('/open/ticket/{id}', [DesktopController::class, 'openTicket']);
$router->get('/desktop/pair', [DesktopController::class, 'pairForm']);
$router->post('/desktop/pair', [DesktopController::class, 'pairApprove']);
$router->post('/desktop/devices/{id}/revoke', [DesktopController::class, 'revokeDevice']);
$router->get('/desktop/download', [DesktopReleaseController::class, 'downloadPage']);
$router->get('/desktop/download/{id}', [DesktopReleaseController::class, 'download']);
$router->get('/desktop/updates/releases.win.json', [DesktopReleaseController::class, 'updateFeed']);
$router->get('/desktop/updates/{file}', [DesktopReleaseController::class, 'updatePackage']);
$router->get('/admin/desktop-releases', [DesktopReleaseController::class, 'index']);
$router->post('/admin/desktop-releases/upload', [DesktopReleaseController::class, 'upload']);
$router->post('/admin/desktop-releases/{id}/current', [DesktopReleaseController::class, 'makeCurrent']);
$router->post('/admin/desktop-releases/{id}/delete', [DesktopReleaseController::class, 'delete']);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/board', [DashboardController::class, 'board']);

$router->get('/calendar', [CalendarController::class, 'index']);

$router->get('/todos', [TodoController::class, 'index']);
$router->get('/todos/new', [TodoController::class, 'newForm']);
$router->post('/todos', [TodoController::class, 'store']);
$router->get('/todos/{id}/edit', [TodoController::class, 'editForm']);
$router->post('/todos/{id}', [TodoController::class, 'update']);
$router->post('/todos/{id}/done', [TodoController::class, 'markDone']);
$router->post('/todos/{id}/miss', [TodoController::class, 'markMiss']);
$router->post('/todos/{id}/delete', [TodoController::class, 'delete']);

// Meeting-room booking (RoomBooking::isApprover gates approve/reject and /rooms/manage).
$router->get('/rooms', [RoomController::class, 'index']);
$router->get('/rooms/requests', [RoomController::class, 'requests']);
$router->get('/rooms/bookings/new', [RoomController::class, 'newForm']);
$router->post('/rooms/bookings', [RoomController::class, 'store']);
$router->get('/rooms/bookings/{id}', [RoomController::class, 'show']);
$router->get('/rooms/bookings/{id}/files/{fileId}', [RoomController::class, 'downloadFile']);
$router->post('/rooms/bookings/{id}/approve', [RoomController::class, 'approve']);
$router->post('/rooms/bookings/{id}/reject', [RoomController::class, 'reject']);
$router->post('/rooms/bookings/{id}/cancel', [RoomController::class, 'cancel']);
$router->get('/rooms/manage', [RoomController::class, 'manage']);
$router->post('/rooms/manage/rooms', [RoomController::class, 'saveRoom']);
$router->post('/rooms/manage/rooms/{id}', [RoomController::class, 'saveRoom']);
$router->post('/rooms/manage/rooms/{id}/delete', [RoomController::class, 'deleteRoom']);
$router->post('/rooms/manage/equipment', [RoomController::class, 'saveEquipment']);
$router->post('/rooms/manage/equipment/{id}', [RoomController::class, 'saveEquipment']);
$router->post('/rooms/manage/equipment/{id}/delete', [RoomController::class, 'deleteEquipment']);
$router->post('/rooms/manage/media-work', [RoomController::class, 'setMediaWork']);
$router->post('/rooms/manage/approvers', [RoomController::class, 'addApprover']);
$router->post('/rooms/manage/approvers/{id}/delete', [RoomController::class, 'removeApprover']);

$router->post('/role-switch', [ProfileController::class, 'switchRole']);
$router->post('/role-primary', [ProfileController::class, 'setPrimaryRole']);
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
$router->post('/tickets/{id}/cancel', [TicketController::class, 'cancel']);
$router->post('/tickets/{id}/reassign', [TicketController::class, 'reassign']);
$router->post('/tickets/{id}/update', [TicketController::class, 'update']);
$router->post('/tickets/{id}/edits/acknowledge', [TicketController::class, 'acknowledgeEdits']);
$router->post('/tickets/{id}/questions', [TicketController::class, 'addQuestion']);
$router->post('/tickets/{id}/questions/{qid}/answer', [TicketController::class, 'answerQuestion']);
$router->post('/tickets/{id}/files', [TicketController::class, 'uploadFile']);
$router->get('/tickets/{id}/files/{fileId}', [TicketController::class, 'downloadFile']);

$router->get('/admin/users', [AdminController::class, 'manageUsers']);
$router->get('/admin/users/duplicates', [AdminController::class, 'duplicates']);
$router->post('/admin/users/duplicates/merge', [AdminController::class, 'mergeAllDuplicates']);
$router->post('/admin/users/duplicates/{id}/merge', [AdminController::class, 'mergeDuplicate']);
$router->get('/admin/users/{id}/roles', [AdminController::class, 'editRolesForm']);
$router->post('/admin/users/{id}/roles', [AdminController::class, 'updateRoles']);
$router->post('/admin/impersonate/{id}', [AdminController::class, 'impersonate']);
$router->post('/admin/impersonate-stop', [AdminController::class, 'stopImpersonating']);
$router->get('/admin/audit', [AdminController::class, 'auditLog']);

$router->get('/admin/migrations', [MigrationController::class, 'index']);
$router->post('/admin/migrations/run', [MigrationController::class, 'run']);

$router->get('/avatar/{id}', [AvatarController::class, 'show']);

$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings/url', [SettingsController::class, 'updateUrl']);
$router->post('/admin/settings/sync', [SettingsController::class, 'sync']);
$router->post('/admin/settings/notify', [SettingsController::class, 'updateNotify']);
$router->post('/admin/settings/general', [SettingsController::class, 'updateGeneral']);
$router->post('/admin/settings/oauth', [SettingsController::class, 'updateOauth']);
$router->post('/admin/settings/oa-sync', [SettingsController::class, 'oaSync']);
$router->post('/admin/settings/oa-secret', [SettingsController::class, 'updateOaSecret']);

// {type} is one of OrgUnit::TYPES — validated in the controller before use.
$router->get('/admin/org', [OrgController::class, 'index']);
$router->get('/admin/org-import', [OrgImportController::class, 'index']);
$router->post('/admin/org-import/preview', [OrgImportController::class, 'preview']);
$router->post('/admin/org-import/apply', [OrgImportController::class, 'apply']);
$router->post('/admin/org', [OrgController::class, 'store']); // type in the form (single add form)
$router->post('/admin/org/{type}', [OrgController::class, 'store']);
$router->post('/admin/org/{type}/{id}', [OrgController::class, 'update']);
$router->post('/admin/org/{type}/{id}/delete', [OrgController::class, 'delete']);

$router->dispatch(Request::method(), Request::path());
