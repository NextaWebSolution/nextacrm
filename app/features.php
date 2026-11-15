<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

/**
 * Built-in features registered through the same extension API that marketplace modules use.
 * See App\Core\Extensions for the available extension points.
 */

use App\Core\Extensions as X;
use App\Core\Router;
use App\Core\View;
use App\Http\Controllers as C;

// ───────────────────────── v1.1 · Client portal ─────────────────────────
X::routes(function (Router $r) {
    $r->get('/portal/login', [C\PortalController::class, 'loginForm']);
    $r->post('/portal/login', [C\PortalController::class, 'login'], ['csrf', 'throttle:portal-login,20,300']);
    $r->post('/portal/logout', [C\PortalController::class, 'logout'], ['csrf']);
    $r->get('/portal/forgot', [C\PortalController::class, 'forgotForm']);
    $r->post('/portal/forgot', [C\PortalController::class, 'forgot'], ['csrf', 'throttle:portal-forgot,5,900']);
    $r->get('/portal/activate/{token}', [C\PortalController::class, 'activateForm']);
    $r->post('/portal/activate/{token}', [C\PortalController::class, 'activate'], ['csrf', 'throttle:portal-reset,10,900']);
    $r->get('/portal', [C\PortalController::class, 'home']);
    $r->get('/portal/invoices', [C\PortalController::class, 'invoices']);
    $r->get('/portal/invoices/{id}', [C\PortalController::class, 'invoice']);
    $r->get('/portal/invoices/{id}/pdf', [C\PortalController::class, 'invoicePdf']);
    $r->post('/portal/invoices/{id}/pay', [C\PortalController::class, 'pay'], ['csrf', 'throttle:pay,20,600']);
    $r->get('/portal/quotes', [C\PortalController::class, 'quotes']);
    $r->get('/portal/projects', [C\PortalController::class, 'projects']);
    $r->get('/portal/projects/{id}', [C\PortalController::class, 'project']);
    $r->get('/portal/tickets', [C\PortalController::class, 'tickets']);
    $r->post('/portal/tickets', [C\PortalController::class, 'createTicket'], ['csrf', 'throttle:portal-ticket,10,600']);
    $r->get('/portal/tickets/{id}', [C\PortalController::class, 'ticket']);
    $r->post('/portal/tickets/{id}/reply', [C\PortalController::class, 'reply'], ['csrf', 'throttle:portal-ticket,30,600']);
    $r->get('/portal/files', [C\PortalController::class, 'files']);
    $r->get('/portal/files/{id}', [C\PortalController::class, 'download']);
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->post('/r/contacts/{id}/portal/invite', [C\PortalController::class, 'staffInvite']);
        $r->post('/r/contacts/{id}/portal/disable', [C\PortalController::class, 'staffDisable']);
        $r->post('/files/{id}/portal', [C\PortalController::class, 'shareFile']);
    });
});
X::panel('contacts', 'side', fn (array $rec) => View::partial('panels/portal_access', ['rec' => $rec]), 10);

// ───────────────────────── v1.1 · Online payments ─────────────────────────
X::routes(function (Router $r) {
    $r->post('/p/invoice/{token}/pay', [C\PaymentController::class, 'payPublic'], ['csrf', 'throttle:pay,20,600']);
    $r->get('/pay/return/{gateway}/{attempt}', [C\PaymentController::class, 'returned']);
    $r->post('/hooks/pay/{gateway}/{company}', [C\PaymentController::class, 'webhook'], ['throttle:payhook,300,60']);
    $r->group(['auth', 'csrf', 'admin'], function (Router $r) {
        $r->get('/settings/payments', [C\Settings\PaymentsController::class, 'index']);
        $r->post('/settings/payments', [C\Settings\PaymentsController::class, 'save']);
    });
});
X::settingsTab('Workspace', 'payments', '/settings/payments', 'Online payments', 'credit-card');

// ───────────────────────── v1.2 · E-signature (quotes & contracts) ─────────────────────────
X::routes(function (Router $r) {
    $r->post('/p/contract/{token}/sign', [C\PublicController::class, 'signContract'], ['csrf', 'throttle:sign,20,600']);
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->post('/r/contracts/{id}/signing-link', [C\SignatureController::class, 'contractLink']);
        $r->get('/r/{entity}/{id}/signature-certificate', [C\SignatureController::class, 'certificate']);
    });
});
foreach (['quotes', 'contracts'] as $__e) {
    X::panel($__e, 'side', fn (array $rec, $svc, string $entity) => View::partial('panels/signature', ['rec' => $rec, 'entity' => $entity]), 12);
}

// ───────────────────────── v1.1 · IMAP inbox → tickets & leads ─────────────────────────
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf', 'admin'], function (Router $r) {
        $r->get('/settings/inbox', [C\Settings\InboxController::class, 'index']);
        $r->post('/settings/inbox', [C\Settings\InboxController::class, 'save']);
        $r->post('/settings/inbox/{id}/test', [C\Settings\InboxController::class, 'test']);
        $r->post('/settings/inbox/{id}/fetch', [C\Settings\InboxController::class, 'fetchNow']);
        $r->delete('/settings/inbox/{id}', [C\Settings\InboxController::class, 'delete']);
    });
});
X::settingsTab('Automation', 'inbox', '/settings/inbox', 'Inbound e-mail', 'inbox');
X::cron('inbox', fn () => App\Services\InboxService::run());

// ───────────────────────── v1.1 · WhatsApp (Meta Cloud API, Twilio, Gupshup) ─────────────────────────
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->post('/r/{entity}/{id}/whatsapp', [C\WhatsAppController::class, 'send'], ['throttle:whatsapp,60,600']);
    });
    $r->group(['auth', 'csrf', 'admin'], function (Router $r) {
        $r->get('/settings/whatsapp', [C\WhatsAppController::class, 'settings']);
        $r->post('/settings/whatsapp', [C\WhatsAppController::class, 'save']);
        $r->post('/settings/whatsapp/test', [C\WhatsAppController::class, 'test']);
    });
});
X::settingsTab('Automation', 'whatsapp', '/settings/whatsapp', 'WhatsApp', 'message-circle');
foreach (['leads', 'contacts', 'customers', 'deals', 'invoices', 'quotes', 'tickets'] as $__e) {
    X::panel($__e, 'side', fn (array $rec, $svc, string $entity) => View::partial('panels/whatsapp', ['rec' => $rec, 'svc' => $svc, 'entity' => $entity]), 20);
}
X::workflowAction('send_whatsapp', 'Send a WhatsApp message', function (array $wf, string $entity, array $record, array $a) {
    $to = App\Services\WhatsAppService::phoneFor($record);
    if ($to === '') {
        return 'WhatsApp skipped: the record has no phone number';
    }
    App\Services\WhatsAppService::send($to, App\Services\WorkflowService::render((string) ($a['body'] ?? ''), $entity, $record), $entity, (int) $record['id'], ($a['template'] ?? '') ?: null);
    return 'WhatsApp sent';
});

// ───────────────────────── v1.1 · Public help centre ─────────────────────────
X::routes(function (Router $r) {
    $r->get('/help', [C\HelpController::class, 'index']);
    $r->get('/help/{ref}', [C\HelpController::class, 'article']);
});

// ───────────────────────── v1.1 · Gantt timeline, checklists, recurring tasks ─────────────────────────
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->post('/r/tasks/{id}/checklist', [C\ChecklistController::class, 'save']);
    });
});
X::panel('projects', 'main', fn (array $rec) => View::partial('panels/gantt', ['rec' => $rec]), 10);
X::panel('tasks', 'main', fn (array $rec) => View::partial('panels/checklist', ['rec' => $rec]), 10);

// ───────────────────────── v1.1 · Contract renewal reminders ─────────────────────────
X::cron('contract_renewals', fn () => App\Services\ContractService::runRenewals(), true, true);

// ───────────────────────── v1.2 · E-mail sequences (drip campaigns) ─────────────────────────
X::permission('sequences', 'E-mail sequences');
X::nav('Marketing', 'Sequences', '/sequences', 'mails', 'sequences');
X::routes(function (Router $r) {
    $r->get('/unsubscribe/{token}', [C\SequenceController::class, 'unsubscribeForm']);
    $r->post('/unsubscribe/{token}', [C\SequenceController::class, 'unsubscribe'], ['csrf', 'throttle:unsub,30,600']);
    $r->group(['auth', 'csrf', 'perm:sequences.view'], function (Router $r) {
        $r->get('/sequences', [C\SequenceController::class, 'index']);
        $r->get('/sequences/new', [C\SequenceController::class, 'edit']);
        $r->post('/sequences/new', [C\SequenceController::class, 'save']);
        $r->get('/sequences/{id}', [C\SequenceController::class, 'edit']);
        $r->post('/sequences/{id}', [C\SequenceController::class, 'save']);
        $r->delete('/sequences/{id}', [C\SequenceController::class, 'delete']);
        $r->post('/sequences/enrollments/{id}/stop', [C\SequenceController::class, 'stop']);
        $r->post('/r/{entity}/{id}/sequence', [C\SequenceController::class, 'enroll']);
    });
});
foreach (array_keys(App\Services\SequenceService::ENTITIES) as $__e) {
    X::panel($__e, 'side', fn (array $rec, $svc, string $entity) => View::partial('panels/sequences', ['rec' => $rec, 'entity' => $entity]), 25);
}
X::cron('sequences', fn () => App\Services\SequenceService::run(), false, true);
X::workflowAction('enroll_sequence', 'Enrol in an e-mail sequence', function (array $wf, string $entity, array $record, array $a) {
    return App\Services\SequenceService::enroll((int) ($a['sequence_id'] ?? 0), $entity, (int) $record['id']);
});

// ───────────────────────── v1.2 · Report builder & scheduled reports ─────────────────────────
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf', 'perm:reports.view'], function (Router $r) {
        $r->get('/reports/builder/new', [C\ReportBuilderController::class, 'builder']);
        $r->post('/reports/builder/preview', [C\ReportBuilderController::class, 'preview']);
        $r->post('/reports/builder/new', [C\ReportBuilderController::class, 'save']);
        $r->get('/reports/builder/{id}', [C\ReportBuilderController::class, 'builder']);
        $r->post('/reports/builder/{id}', [C\ReportBuilderController::class, 'save']);
        $r->get('/reports/custom/{id}', [C\ReportBuilderController::class, 'show']);
        $r->delete('/reports/custom/{id}', [C\ReportBuilderController::class, 'delete']);
    });
});
X::cron('scheduled_reports', fn () => App\Services\ReportBuilderService::sendScheduled(), true);

// ───────────────────────── v1.2 · Approval chains (quotations & expenses) ─────────────────────────
X::nav('Finance', 'Approvals', '/approvals', 'stamp', null, 'view', 5);
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->get('/approvals', [C\ApprovalController::class, 'inbox']);
        $r->post('/approvals/{id}/decide', [C\ApprovalController::class, 'decide']);
        $r->post('/r/{entity}/{id}/approval', [C\ApprovalController::class, 'submit']);
    });
    $r->group(['auth', 'csrf', 'admin'], function (Router $r) {
        $r->get('/settings/approvals', [C\ApprovalController::class, 'settings']);
        $r->post('/settings/approvals', [C\ApprovalController::class, 'saveFlow']);
        $r->delete('/settings/approvals/{id}', [C\ApprovalController::class, 'deleteFlow']);
    });
});
X::settingsTab('Automation', 'approvals', '/settings/approvals', 'Approval chains', 'stamp');
foreach (array_keys(App\Services\ApprovalService::ENTITIES) as $__e) {
    X::panel($__e, 'side', fn (array $rec, $svc, string $entity) => View::partial('panels/approval', ['rec' => $rec, 'entity' => $entity]), 5);
}

// ───────────────────────── v2.0 · Attendance & payroll ─────────────────────────
X::permission('attendance', 'Attendance');
X::permission('payroll', 'Payroll');
X::nav('Team', 'Attendance', '/attendance', 'calendar-check', null, 'view', 30);
X::nav('Team', 'Payroll', '/payroll', 'banknote', 'payroll', 'view', 31);
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->get('/attendance', [C\AttendanceController::class, 'index']);
        $r->post('/attendance/check-in', [C\AttendanceController::class, 'checkIn']);
        $r->post('/attendance/check-out', [C\AttendanceController::class, 'checkOut']);
        $r->post('/attendance/mark', [C\AttendanceController::class, 'mark']);
        $r->post('/attendance/holidays', [C\AttendanceController::class, 'holiday']);
        $r->delete('/attendance/holidays/{id}', [C\AttendanceController::class, 'deleteHoliday']);
        $r->post('/attendance/settings', [C\AttendanceController::class, 'settings']);
        $r->get('/payroll', [C\PayrollController::class, 'index']);
        $r->get('/payroll/me', [C\PayrollController::class, 'mine']);
        $r->post('/payroll/generate', [C\PayrollController::class, 'generate']);
        $r->post('/payroll/settings', [C\PayrollController::class, 'settings']);
        $r->post('/payroll/payslips/{id}', [C\PayrollController::class, 'adjust']);
        $r->get('/payroll/payslips/{id}/pdf', [C\PayrollController::class, 'payslip']);
        $r->get('/payroll/{id}', [C\PayrollController::class, 'show']);
        $r->post('/payroll/{id}/recalculate', [C\PayrollController::class, 'recalc']);
        $r->post('/payroll/{id}/approve', [C\PayrollController::class, 'approve']);
        $r->post('/payroll/{id}/paid', [C\PayrollController::class, 'paid']);
        $r->post('/payroll/{id}/email', [C\PayrollController::class, 'email']);
    });
});

// ───────────────────────── v2.0 · AI assistant (bring your own key) ─────────────────────────
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf'], function (Router $r) {
        $r->post('/r/{entity}/{id}/ai', [C\AiController::class, 'run']);
    });
    $r->group(['auth', 'csrf', 'admin'], function (Router $r) {
        $r->get('/settings/ai', [C\AiController::class, 'settings']);
        $r->post('/settings/ai', [C\AiController::class, 'save']);
        $r->post('/settings/ai/test', [C\AiController::class, 'test']);
    });
});
X::settingsTab('Automation', 'ai', '/settings/ai', 'AI assistant', 'bot');
foreach (App\Services\AiService::ENTITIES as $__e) {
    X::panel($__e, 'side', fn (array $rec, $svc, string $entity) => View::partial('panels/ai', ['rec' => $rec, 'entity' => $entity]), 2);
}

// ───────────────────────── v2.0 · Module marketplace ─────────────────────────
X::routes(function (Router $r) {
    $r->group(['auth', 'csrf', 'admin'], function (Router $r) {
        $r->get('/settings/modules', [C\Settings\ModulesController::class, 'index']);
        $r->post('/settings/modules/upload', [C\Settings\ModulesController::class, 'upload']);
        $r->post('/settings/modules/developer', [C\Settings\ModulesController::class, 'developer']);
        $r->post('/settings/modules/catalog/{slug}', [C\Settings\ModulesController::class, 'catalogInstall']);
        $r->post('/settings/modules/{slug}/toggle', [C\Settings\ModulesController::class, 'toggle']);
        $r->delete('/settings/modules/{slug}', [C\Settings\ModulesController::class, 'uninstall']);
    });
});
X::settingsTab('Workspace', 'modules', '/settings/modules', 'Modules', 'puzzle');
