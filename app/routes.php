<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

use App\Http\Controllers as C;
use App\Http\Controllers\Settings as S;
use App\Http\Controllers\Api as A;

/** @var App\Core\Router $router */

// ── Public (no session required) ──
$router->get('/manifest.webmanifest', [C\PublicController::class, 'webmanifest']);
$router->get('/p/{type}/{token}', [C\PublicController::class, 'document']);
$router->get('/p/{type}/{token}/pdf', [C\PublicController::class, 'documentPdf']);
$router->post('/p/{type}/{token}/respond', [C\PublicController::class, 'respond'], ['csrf', 'throttle:quote,20,600']);
$router->get('/forms/{token}', [C\PublicController::class, 'form']);
$router->post('/forms/{token}', [C\PublicController::class, 'submitForm'], ['throttle:form,10,600']);
$router->post('/hooks/workflow/{token}', [C\PublicController::class, 'workflowHook'], ['throttle:wfhook,120,60']);

// ── Authentication ──
$router->group(['guest'], function ($r) {
    $r->get('/login', [C\AuthController::class, 'showLogin']);
    $r->post('/login', [C\AuthController::class, 'login'], ['csrf', 'throttle:login,30,300']);
    $r->get('/login/2fa', [C\AuthController::class, 'show2fa']);
    $r->post('/login/2fa', [C\AuthController::class, 'verify2fa'], ['csrf', 'throttle:2fa,30,300']);
    $r->get('/forgot-password', [C\AuthController::class, 'showForgot']);
    $r->post('/forgot-password', [C\AuthController::class, 'sendReset'], ['csrf', 'throttle:forgot,5,900']);
    $r->get('/reset-password/{token}', [C\AuthController::class, 'showReset']);
    $r->post('/reset-password/{token}', [C\AuthController::class, 'reset'], ['csrf', 'throttle:reset,10,900']);
});
$router->post('/logout', [C\AuthController::class, 'logout'], ['csrf']);

// ── Application ──
$router->group(['auth', 'csrf'], function ($r) {
    $r->get('/', [C\DashboardController::class, 'index']);
    $r->post('/switch-company', [C\ProfileController::class, 'switchCompany']);

    // Global search, notifications, lookups
    $r->get('/x/search', [C\SearchController::class, 'search']);
    $r->get('/x/lookup/{entity}', [C\RecordController::class, 'lookup']);
    $r->get('/x/notifications', [C\NotificationController::class, 'index']);
    $r->post('/x/notifications/read', [C\NotificationController::class, 'read']);
    $r->get('/notifications', [C\NotificationController::class, 'page']);

    // Calendar
    $r->get('/calendar', [C\CalendarController::class, 'index'], ['perm:calendar.view']);
    $r->get('/x/calendar', [C\CalendarController::class, 'feed'], ['perm:calendar.view']);

    // Reports
    $r->get('/reports', [C\ReportController::class, 'index'], ['perm:reports.view']);
    $r->get('/reports/{key}', [C\ReportController::class, 'show'], ['perm:reports.view']);

    // Files library
    $r->get('/files', [C\FileController::class, 'index'], ['perm:files.view']);
    $r->post('/files', [C\FileController::class, 'upload'], ['throttle:upload,60,600']);
    $r->post('/files/folders', [C\FileController::class, 'createFolder'], ['perm:files.create']);
    $r->get('/files/{id}/download', [C\FileController::class, 'download']);
    $r->get('/files/{id}/view', [C\FileController::class, 'inline']);
    $r->delete('/files/{id}', [C\FileController::class, 'destroy']);

    // Timer
    $r->post('/x/timer/start', [C\TimerController::class, 'start'], ['perm:time_entries.create']);
    $r->post('/x/timer/stop', [C\TimerController::class, 'stop']);
    $r->get('/x/timer', [C\TimerController::class, 'status']);

    // Saved views
    $r->post('/x/views/{entity}', [C\ViewController::class, 'store']);
    $r->delete('/x/views/{id}', [C\ViewController::class, 'destroy']);

    // Sales documents (quotes / invoices)
    $r->get('/documents/{type}/new', [C\DocumentController::class, 'create']);
    $r->get('/documents/{type}/{id}/edit', [C\DocumentController::class, 'edit']);
    $r->post('/documents/{type}', [C\DocumentController::class, 'store']);
    $r->post('/documents/{type}/{id}', [C\DocumentController::class, 'update']);
    $r->get('/documents/{type}/{id}/pdf', [C\DocumentController::class, 'pdf']);
    $r->post('/documents/{type}/{id}/status', [C\DocumentController::class, 'status']);
    $r->post('/documents/{type}/{id}/email', [C\DocumentController::class, 'email']);
    $r->post('/documents/quote/{id}/convert', [C\DocumentController::class, 'convert']);
    $r->post('/documents/invoice/from-time/{id}', [C\DocumentController::class, 'fromTime']);

    // Special record actions
    $r->post('/r/leads/{id}/convert', [C\LeadController::class, 'convert']);
    $r->post('/r/tickets/{id}/reply', [C\TicketController::class, 'reply']);
    $r->post('/r/projects/{id}/members', [C\ProjectController::class, 'addMember']);
    $r->delete('/r/projects/{id}/members/{uid}', [C\ProjectController::class, 'removeMember']);
    $r->post('/r/{entity}/{id}/workflow/{wid}', [C\RecordController::class, 'runWorkflow']);

    // Generic record engine
    $r->get('/r/{entity}', [C\RecordController::class, 'index']);
    $r->get('/x/r/{entity}', [C\RecordController::class, 'data']);
    $r->get('/r/{entity}/export', [C\RecordController::class, 'export'], ['throttle:export,20,600']);
    $r->get('/r/{entity}/import', [C\RecordController::class, 'importForm']);
    $r->post('/r/{entity}/import', [C\RecordController::class, 'importUpload'], ['throttle:import,20,600']);
    $r->post('/r/{entity}/import/run', [C\RecordController::class, 'importRun'], ['throttle:import,20,600']);
    $r->get('/x/r/{entity}/form', [C\RecordController::class, 'form']);
    $r->post('/r/{entity}', [C\RecordController::class, 'store']);
    $r->post('/r/{entity}/bulk', [C\RecordController::class, 'bulk']);
    $r->get('/r/{entity}/{id}', [C\RecordController::class, 'show']);
    $r->post('/r/{entity}/{id}', [C\RecordController::class, 'update']);
    $r->patch('/x/r/{entity}/{id}', [C\RecordController::class, 'patch']);
    $r->delete('/r/{entity}/{id}', [C\RecordController::class, 'destroy']);
    $r->post('/r/{entity}/{id}/move', [C\RecordController::class, 'move']);
    $r->post('/r/{entity}/{id}/activities', [C\ActivityController::class, 'store']);
    $r->delete('/activities/{id}', [C\ActivityController::class, 'destroy']);
    $r->post('/r/{entity}/{id}/files', [C\FileController::class, 'uploadToRecord'], ['throttle:upload,60,600']);

    // Profile & security
    $r->get('/profile', [C\ProfileController::class, 'show']);
    $r->post('/profile', [C\ProfileController::class, 'update']);
    $r->post('/profile/password', [C\ProfileController::class, 'password'], ['throttle:reauth,10,900']);
    $r->get('/profile/security', [C\ProfileController::class, 'security']);
    $r->post('/profile/2fa/setup', [C\ProfileController::class, 'setup2fa']);
    $r->post('/profile/2fa/enable', [C\ProfileController::class, 'enable2fa'], ['throttle:reauth,10,900']);
    $r->post('/profile/2fa/disable', [C\ProfileController::class, 'disable2fa'], ['throttle:reauth,10,900']);
    $r->post('/profile/sessions/revoke', [C\ProfileController::class, 'revokeSessions']);
    $r->post('/x/preferences', [C\ProfileController::class, 'preferences']);
});

// ── Administration ──
$router->group(['auth', 'csrf', 'admin'], function ($r) {
    $r->get('/settings', [S\GeneralController::class, 'index']);
    $r->post('/settings/general', [S\GeneralController::class, 'save']);
    $r->get('/settings/company', [S\CompanyController::class, 'index']);
    $r->post('/settings/company', [S\CompanyController::class, 'save']);
    $r->post('/settings/companies', [S\CompanyController::class, 'create']);
    $r->get('/settings/users', [S\UserController::class, 'index']);
    $r->post('/settings/users', [S\UserController::class, 'invite']);
    $r->post('/settings/users/{id}', [S\UserController::class, 'update']);
    $r->post('/settings/users/{id}/reset-2fa', [S\UserController::class, 'reset2fa']);
    $r->post('/settings/users/{id}/logout', [S\UserController::class, 'logoutAll']);
    $r->post('/settings/users/{id}/resend', [S\UserController::class, 'resendInvite']);
    $r->get('/settings/roles', [S\RoleController::class, 'index']);
    $r->get('/settings/roles/{id}', [S\RoleController::class, 'edit']);
    $r->post('/settings/roles', [S\RoleController::class, 'store']);
    $r->post('/settings/roles/{id}', [S\RoleController::class, 'update']);
    $r->delete('/settings/roles/{id}', [S\RoleController::class, 'destroy']);
    $r->get('/settings/teams', [S\TeamController::class, 'index']);
    $r->post('/settings/teams', [S\TeamController::class, 'save']);
    $r->delete('/settings/teams/{id}', [S\TeamController::class, 'destroy']);
    $r->get('/settings/customization', [S\CustomizationController::class, 'index']);
    $r->post('/settings/picklists', [S\CustomizationController::class, 'savePicklists']);
    $r->post('/settings/pipelines', [S\CustomizationController::class, 'savePipelines']);
    $r->post('/settings/taxes', [S\CustomizationController::class, 'saveTaxes']);
    $r->post('/settings/currencies', [S\CustomizationController::class, 'saveCurrencies']);
    $r->post('/settings/sla', [S\CustomizationController::class, 'saveSla']);
    $r->post('/settings/scoring', [S\CustomizationController::class, 'saveScoring']);
    $r->get('/settings/fields', [S\CustomFieldController::class, 'index']);
    $r->post('/settings/fields', [S\CustomFieldController::class, 'store']);
    $r->delete('/settings/fields/{id}', [S\CustomFieldController::class, 'destroy']);
    $r->get('/settings/email', [S\EmailController::class, 'index']);
    $r->post('/settings/email', [S\EmailController::class, 'save']);
    $r->post('/settings/email/test', [S\EmailController::class, 'test']);
    $r->get('/settings/storage', [S\StorageController::class, 'index']);
    $r->post('/settings/storage', [S\StorageController::class, 'save']);
    $r->post('/settings/storage/test', [S\StorageController::class, 'test']);
    $r->get('/settings/security', [S\SecurityController::class, 'index']);
    $r->post('/settings/security', [S\SecurityController::class, 'save']);
    $r->get('/settings/audit', [S\SecurityController::class, 'audit']);
    $r->get('/settings/api', [S\ApiController::class, 'index']);
    $r->post('/settings/api/tokens', [S\ApiController::class, 'createToken']);
    $r->delete('/settings/api/tokens/{id}', [S\ApiController::class, 'revokeToken']);
    $r->post('/settings/webhooks', [S\ApiController::class, 'saveWebhook']);
    $r->delete('/settings/webhooks/{id}', [S\ApiController::class, 'deleteWebhook']);
    $r->post('/settings/webhooks/{id}/test', [S\ApiController::class, 'testWebhook']);
    $r->get('/settings/workflows', [S\WorkflowController::class, 'index']);
    $r->get('/settings/workflows/new', [S\WorkflowController::class, 'edit']);
    $r->get('/settings/workflows/{id}', [S\WorkflowController::class, 'edit']);
    $r->post('/settings/workflows', [S\WorkflowController::class, 'save']);
    $r->post('/settings/workflows/{id}/toggle', [S\WorkflowController::class, 'toggle']);
    $r->delete('/settings/workflows/{id}', [S\WorkflowController::class, 'destroy']);
    $r->get('/settings/forms', [S\FormController::class, 'index']);
    $r->post('/settings/forms', [S\FormController::class, 'save']);
    $r->delete('/settings/forms/{id}', [S\FormController::class, 'destroy']);
    $r->get('/settings/system', [S\SystemController::class, 'index']);
    $r->post('/settings/system/modules', [S\SystemController::class, 'modules']);
    $r->post('/settings/system/backup', [S\SystemController::class, 'backup']);
    $r->get('/settings/system/backup/{name}', [S\SystemController::class, 'downloadBackup']);
    $r->post('/settings/system/update-check', [S\SystemController::class, 'checkUpdate']);
    $r->post('/settings/system/update', [S\SystemController::class, 'applyUpdate']);
    $r->post('/settings/system/update-upload', [S\SystemController::class, 'uploadUpdate']);
    $r->post('/settings/system/cron', [S\SystemController::class, 'runCron']);
    $r->post('/settings/system/cron-key', [S\SystemController::class, 'rotateCronKey']);
});

// ── REST API v1 ──
$router->group(['api'], function ($r) {
    $r->get('/me', [A\RecordApiController::class, 'me']);
    $r->get('/meta', [A\RecordApiController::class, 'meta']);
    $r->get('/search', [A\RecordApiController::class, 'search']);
    $r->get('/{entity}', [A\RecordApiController::class, 'index']);
    $r->post('/{entity}', [A\RecordApiController::class, 'store']);
    $r->get('/{entity}/{id}', [A\RecordApiController::class, 'show']);
    $r->put('/{entity}/{id}', [A\RecordApiController::class, 'update']);
    $r->delete('/{entity}/{id}', [A\RecordApiController::class, 'destroy']);
}, '/api/v1');
$router->get('/api/v1/openapi.json', [A\RecordApiController::class, 'openapi']);
$router->get('/api/docs', [A\RecordApiController::class, 'docs']);
