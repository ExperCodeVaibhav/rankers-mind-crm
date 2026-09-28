<?php

use App\Controllers\AttendanceController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\CrudController;
use App\Controllers\DashboardController;
use App\Controllers\EstimateController;
use App\Controllers\InvoiceController;
use App\Controllers\LeadController;
use App\Controllers\NotificationController;
use App\Controllers\PayrollController;
use App\Controllers\PortalController;
use App\Controllers\ReportController;
use App\Controllers\SearchController;
use App\Controllers\SettingsController;
use App\Controllers\StaffController;
use App\Controllers\WorkController;
use App\Controllers\HealthController;

/** @var \App\Core\Router $router */
$router->get('/health', [HealthController::class, 'show']);

$router->get('/', static fn() => redirect('login'));
$router->get('/login', [AuthController::class,'login'], ['guest']);
$router->post('/login', [AuthController::class,'authenticate'], ['guest','csrf']);
$router->post('/logout', [AuthController::class,'logout'], ['auth','csrf']);
$router->get('/forgot-password', [AuthController::class,'forgot'], ['guest']);
$router->post('/forgot-password', [AuthController::class,'sendReset'], ['guest','csrf']);
$router->get('/reset-password', [AuthController::class,'reset'], ['guest']);
$router->post('/reset-password', [AuthController::class,'updatePassword'], ['guest','csrf']);
$router->get('/account/password', [AuthController::class,'passwordForm'], ['auth']);
$router->post('/account/password', [AuthController::class,'changePassword'], ['auth','csrf']);

$router->get('/dashboard', [DashboardController::class,'index'], ['auth']);
$router->get('/search', [SearchController::class,'index'], ['auth']);
$router->get('/favourites', [CrudController::class,'favourites'], ['auth']);

$router->get('/clients/create', [ClientController::class,'create'], ['auth']);
$router->post('/clients', [ClientController::class,'store'], ['auth','csrf']);
$router->get('/clients/{id}/edit', [ClientController::class,'edit'], ['auth']);
$router->post('/clients/{id}', [ClientController::class,'update'], ['auth','csrf']);
$router->post('/clients/{id}/delete', [ClientController::class,'delete'], ['auth','csrf']);

$router->get('/staff', [StaffController::class,'index'], ['auth']);
$router->get('/staff/create', [StaffController::class,'create'], ['auth']);
$router->post('/staff', [StaffController::class,'store'], ['auth','csrf']);
$router->get('/staff/{id}/edit', [StaffController::class,'edit'], ['auth']);
$router->post('/staff/{id}', [StaffController::class,'update'], ['auth','csrf']);

$router->get('/attendance', [AttendanceController::class,'index'], ['auth']);
$router->get('/attendance/punch', [AttendanceController::class,'punchPage'], ['auth']);
$router->post('/attendance/punch', [AttendanceController::class,'punch'], ['auth','csrf']);
$router->get('/attendance/photo/{id}/{type}', [AttendanceController::class,'photo'], ['auth']);

$router->get('/invoices', [InvoiceController::class,'index'], ['auth']);
$router->get('/invoices/create', [InvoiceController::class,'create'], ['auth']);
$router->post('/invoices', [InvoiceController::class,'store'], ['auth','csrf']);
$router->get('/invoices/{id}', [InvoiceController::class,'show'], ['auth']);
$router->post('/invoices/{id}/payments', [InvoiceController::class,'payment'], ['auth','csrf']);
$router->get('/invoices/{id}/print', [InvoiceController::class,'print'], ['auth']);
$router->get('/payments/{id}/receipt', [InvoiceController::class,'receipt'], ['auth']);

$router->get('/estimates', [EstimateController::class,'index'], ['auth']);
$router->get('/estimates/create', [EstimateController::class,'create'], ['auth']);
$router->post('/estimates', [EstimateController::class,'store'], ['auth','csrf']);
$router->get('/estimates/{id}', [EstimateController::class,'show'], ['auth']);
$router->post('/estimates/{id}/status', [EstimateController::class,'status'], ['auth','csrf']);
$router->post('/estimates/{id}/convert', [EstimateController::class,'convert'], ['auth','csrf']);
$router->get('/estimates/{id}/print', [EstimateController::class,'print'], ['auth']);

$router->get('/payroll', [PayrollController::class,'index'], ['auth']);
$router->post('/payroll/generate', [PayrollController::class,'generate'], ['auth','csrf']);
$router->post('/payroll/{id}/status', [PayrollController::class,'status'], ['auth','csrf']);
$router->get('/salary-slips/{id}', [PayrollController::class,'slip'], ['auth']);

$router->get('/portal/staff', [PortalController::class,'staff'], ['auth']);
$router->get('/portal/client', [PortalController::class,'client'], ['auth']);
$router->get('/portal/projects/{id}', [PortalController::class,'clientProject'], ['auth']);
$router->get('/portal/tickets/{id}', [PortalController::class,'ticket'], ['auth']);
$router->get('/portal/invoices/{id}', [PortalController::class,'invoice'], ['auth']);
$router->post('/portal/tickets', [PortalController::class,'createTicket'], ['auth','csrf']);
$router->post('/portal/documents', [PortalController::class,'uploadDocument'], ['auth','csrf']);
$router->post('/portal/staff/profile', [PortalController::class,'staffProfile'], ['auth','csrf']);
$router->post('/portal/client/profile', [PortalController::class,'clientProfile'], ['auth','csrf']);
$router->get('/portal/documents/{id}', [PortalController::class,'clientDocument'], ['auth']);
$router->get('/portal/project-files/{id}', [PortalController::class,'projectFile'], ['auth']);

$router->post('/leads/{id}/convert', [LeadController::class,'convert'], ['auth','csrf']);
$router->post('/leads/bulk', [LeadController::class,'bulk'], ['auth','csrf']);
$router->get('/leads/import', [LeadController::class,'importForm'], ['auth']);
$router->post('/leads/import', [LeadController::class,'import'], ['auth','csrf']);
$router->post('/tasks/{id}/comments', [WorkController::class,'taskComment'], ['auth','csrf']);
$router->post('/tasks/{id}/updates', [WorkController::class,'taskUpdate'], ['auth','csrf']);
$router->post('/tasks/{id}/checklists', [WorkController::class,'taskChecklist'], ['auth','csrf']);
$router->post('/tasks/{id}/checklists/{itemId}', [WorkController::class,'taskChecklistToggle'], ['auth','csrf']);
$router->post('/tasks/{id}/time-logs', [WorkController::class,'taskTime'], ['auth','csrf']);
$router->post('/tasks/{id}/files', [WorkController::class,'taskFile'], ['auth','csrf']);
$router->get('/task-files/{id}', [WorkController::class,'taskFileDownload'], ['auth']);
$router->post('/projects/{id}/updates', [WorkController::class,'projectUpdate'], ['auth','csrf']);
$router->post('/projects/{id}/milestones', [WorkController::class,'projectMilestone'], ['auth','csrf']);
$router->post('/projects/{id}/files', [WorkController::class,'projectFile'], ['auth','csrf']);
$router->get('/project-files/{id}', [WorkController::class,'projectFileDownload'], ['auth']);
$router->post('/leads/{id}/activities', [WorkController::class,'leadActivity'], ['auth','csrf']);
$router->post('/leads/{id}/files', [WorkController::class,'leadFile'], ['auth','csrf']);
$router->get('/lead-files/{id}', [WorkController::class,'leadFileDownload'], ['auth']);
$router->post('/leads/{id}/tags', [WorkController::class,'leadTag'], ['auth','csrf']);
$router->post('/tickets/{id}/reply', [WorkController::class,'ticketReply'], ['auth','csrf']);
$router->post('/tickets/{id}/resolve', [WorkController::class,'ticketResolve'], ['auth','csrf']);
$router->post('/portal/tickets/{id}/confirmation', [PortalController::class,'ticketConfirmation'], ['auth','csrf']);

$router->get('/reports', [ReportController::class,'index'], ['auth']);
$router->get('/reports/export', [ReportController::class,'export'], ['auth']);
$router->get('/reports/print', [ReportController::class,'print'], ['auth']);
$router->get('/settings', [SettingsController::class,'index'], ['auth']);
$router->post('/settings', [SettingsController::class,'update'], ['auth','csrf']);
$router->get('/settings/roles', [SettingsController::class,'roles'], ['auth']);
$router->post('/settings/roles', [SettingsController::class,'saveRoles'], ['auth','csrf']);
$router->post('/settings/backup', [SettingsController::class,'backup'], ['auth','csrf']);

$router->get('/notifications', [NotificationController::class,'index'], ['auth']);
$router->get('/notifications/feed', [NotificationController::class,'feed'], ['auth']);
$router->post('/notifications/{id}/read', [NotificationController::class,'read'], ['auth','csrf']);
$router->post('/notifications/read-all', [NotificationController::class,'readAll'], ['auth','csrf']);

$entities = array_keys((array) \App\Config\Config::get('entities', []));
foreach ($entities as $entity) {
    $router->post('/'.$entity.'/filters', static fn() => (new CrudController())->saveFilter($entity), ['auth','csrf']);
    $router->post('/'.$entity.'/filters/{id}/delete', static fn($id) => (new CrudController())->deleteFilter($entity, $id), ['auth','csrf']);
    $router->post('/'.$entity.'/{id}/favourite', static fn($id) => (new CrudController())->toggleFavourite($entity, $id), ['auth','csrf']);
    $router->get('/'.$entity, static fn() => (new CrudController())->index($entity), ['auth']);
    $router->get('/'.$entity.'/create', static fn() => (new CrudController())->create($entity), ['auth']);
    $router->post('/'.$entity, static fn() => (new CrudController())->store($entity), ['auth','csrf']);
    $router->get('/'.$entity.'/export', static fn() => (new CrudController())->export($entity), ['auth']);
    $router->post('/'.$entity.'/{id}/status', static fn($id) => (new CrudController())->quickStatus($entity, $id), ['auth','csrf']);
    $router->get('/'.$entity.'/{id}', static fn($id) => (new CrudController())->show($entity, $id), ['auth']);
    $router->get('/'.$entity.'/{id}/edit', static fn($id) => (new CrudController())->edit($entity, $id), ['auth']);
    $router->post('/'.$entity.'/{id}', static fn($id) => (new CrudController())->update($entity, $id), ['auth','csrf']);
    $router->post('/'.$entity.'/{id}/delete', static fn($id) => (new CrudController())->delete($entity, $id), ['auth','csrf']);
}
