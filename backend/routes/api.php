<?php

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankTransactionController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\CustomsDocumentAttachmentController;
use App\Http\Controllers\Api\CustomsDocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\FlightTicketAttachmentController;
use App\Http\Controllers\Api\FlightTicketController;
use App\Http\Controllers\Api\HouseController;
use App\Http\Controllers\Api\HouseOccupancyController;
use App\Http\Controllers\Api\HousingDeductionController;
use App\Http\Controllers\Api\HousingSummaryController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MachineAttachmentController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\MineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\NotificationRuleController;
use App\Http\Controllers\Api\PayableController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductionRecordController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReceivableController;
use App\Http\Controllers\Api\RentPaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalaryPaymentController;
use App\Http\Controllers\Api\SocialAssistanceController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TravelExpenseAttachmentController;
use App\Http\Controllers\Api\TravelExpenseController;
use App\Http\Controllers\Api\TravelSummaryController;
use App\Http\Controllers\Api\UserAccessController;
use App\Http\Controllers\Api\UtilityBillAttachmentController;
use App\Http\Controllers\Api\UtilityBillController;
use App\Http\Controllers\Api\WorkerNeedController;
use App\Http\Controllers\Api\WorkingDayController;
use App\Http\Controllers\Api\WorksiteController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// EnsureUserIsActive re-checks the flag on every request: a token issued before
// the account was deactivated must stop working immediately, not at next login.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/locale', [AuthController::class, 'updateLocale']);
    // Changing your own password needs no permission — it is the one account
    // action that belongs to the account holder whatever their role.
    Route::put('/me/password', [AuthController::class, 'updatePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    // Landing screen. No permission of its own: it returns only the sections
    // the user can already reach elsewhere.
    Route::get('/dashboard', DashboardController::class);

    // Options for searchable dropdowns. One route, one catalogue
    // (App\Support\LookupRegistry); each resource carries the permission of the
    // module it belongs to, so a lookup can never leak rows a user cannot open.
    Route::get('/lookups/{resource}', LookupController::class);

    // Company settings (name shown app-wide; editing gated).
    Route::get('/company-settings', [CompanySettingsController::class, 'show']);
    Route::put('/company-settings', [CompanySettingsController::class, 'update'])->middleware('can:company.settings.manage');

    // Exchange rates (EUR base; TRY auto-pulled, manual override needs a reason).
    Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
    Route::post('/exchange-rates/sync', [ExchangeRateController::class, 'sync'])->middleware('can:exchange_rates.manage');
    Route::post('/exchange-rates/manual', [ExchangeRateController::class, 'storeManual'])->middleware('can:exchange_rates.manage');

    // Suppliers (referenced by payables).
    Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('can:payables.view');
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('can:payables.create');

    // Payables / supplier debt (replaces BORÇ LİSTESİ).
    Route::get('/payables', [PayableController::class, 'index'])->middleware('can:payables.view');
    Route::post('/payables', [PayableController::class, 'store'])->middleware('can:payables.create');
    Route::get('/payables/{payable}', [PayableController::class, 'show'])->middleware('can:payables.view');
    Route::put('/payables/{payable}', [PayableController::class, 'update'])->middleware('can:payables.create');
    Route::delete('/payables/{payable}', [PayableController::class, 'destroy'])->middleware('can:payables.approve');
    Route::post('/payables/{payable}/payments', [PayableController::class, 'recordPayment'])->middleware('can:payables.create');
    // Correcting a settlement line in place — what makes a paid invoice fixable
    // without booking a second, offsetting entry. Removing money that was
    // recorded as paid is the heavier act, so it needs the approver permission.
    Route::put('/payables/{payable}/payments/{payment}', [PayableController::class, 'updatePayment'])
        ->middleware('can:payables.create');
    Route::delete('/payables/{payable}/payments/{payment}', [PayableController::class, 'deletePayment'])
        ->middleware('can:payables.approve');

    // Clients (referenced by receivables).
    Route::get('/clients', [ClientController::class, 'index'])->middleware('can:receivables.manage');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('can:receivables.manage');

    // Receivables / client invoices (replaces ALACAKLAR, KESİLEN FATURALAR, UNIPROM ALACAKLAR).
    // `statement` must precede the {receivable} binding so it is not captured as an id.
    Route::get('/receivables/statement', [ReceivableController::class, 'statement'])->middleware('can:receivables.manage');
    Route::get('/receivables', [ReceivableController::class, 'index'])->middleware('can:receivables.manage');
    Route::post('/receivables', [ReceivableController::class, 'store'])->middleware('can:receivables.manage');
    Route::get('/receivables/{receivable}', [ReceivableController::class, 'show'])->middleware('can:receivables.manage');
    Route::put('/receivables/{receivable}', [ReceivableController::class, 'update'])->middleware('can:receivables.manage');
    Route::delete('/receivables/{receivable}', [ReceivableController::class, 'destroy'])->middleware('can:receivables.manage');
    Route::post('/receivables/{receivable}/payments', [ReceivableController::class, 'recordPayment'])->middleware('can:receivables.manage');
    Route::put('/receivables/{receivable}/payments/{payment}', [ReceivableController::class, 'updatePayment'])
        ->middleware('can:receivables.manage');
    Route::delete('/receivables/{receivable}/payments/{payment}', [ReceivableController::class, 'deletePayment'])
        ->middleware('can:receivables.manage');
    Route::post('/receivables/{receivable}/deductions', [ReceivableController::class, 'recordDeduction'])->middleware('can:receivables.manage');
    Route::put('/receivables/{receivable}/deductions/{deduction}', [ReceivableController::class, 'updateDeduction'])
        ->middleware('can:receivables.manage');
    Route::delete('/receivables/{receivable}/deductions/{deduction}', [ReceivableController::class, 'deleteDeduction'])
        ->middleware('can:receivables.manage');

    // Bank & cash movements (replaces BANKA HAREKETLERİ).
    Route::get('/bank-transactions/balances', [BankTransactionController::class, 'balances'])->middleware('can:bank_transactions.manage');
    Route::get('/bank-transactions', [BankTransactionController::class, 'index'])->middleware('can:bank_transactions.manage');
    Route::post('/bank-transactions', [BankTransactionController::class, 'store'])->middleware('can:bank_transactions.manage');
    Route::get('/bank-transactions/{bankTransaction}', [BankTransactionController::class, 'show'])->middleware('can:bank_transactions.manage');
    Route::put('/bank-transactions/{bankTransaction}', [BankTransactionController::class, 'update'])->middleware('can:bank_transactions.manage');
    Route::delete('/bank-transactions/{bankTransaction}', [BankTransactionController::class, 'destroy'])->middleware('can:bank_transactions.manage');
    Route::post('/bank-transactions/{bankTransaction}/match', [BankTransactionController::class, 'match'])->middleware('can:bank_transactions.manage');

    // Workers / employee profiles (replaces ISCILER ICIN BANKA HESAPLARI + worker lists).
    // `expiring-documents` must precede the {employee} binding.
    Route::middleware('can:employees.manage')->group(function () {
        Route::get('/employees/expiring-documents', [EmployeeController::class, 'expiringDocuments']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
        Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
        // withTrashed() so a removed employee can still be bound and restored.
        Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])->withTrashed();
    });

    // Employee salary payments (monthly obligations, tracked apart from other
    // worker expenses). Static segments precede the {salaryPayment} binding.
    Route::middleware('can:salary_payments.manage')->group(function () {
        Route::get('/salary-payments/summary', [SalaryPaymentController::class, 'summary']);
        Route::post('/salary-payments/generate', [SalaryPaymentController::class, 'generate']);
        Route::get('/salary-payments', [SalaryPaymentController::class, 'index']);
        Route::post('/salary-payments', [SalaryPaymentController::class, 'store']);
        Route::get('/salary-payments/{salaryPayment}', [SalaryPaymentController::class, 'show']);
        Route::put('/salary-payments/{salaryPayment}', [SalaryPaymentController::class, 'update']);
        Route::delete('/salary-payments/{salaryPayment}', [SalaryPaymentController::class, 'destroy']);
        Route::post('/salary-payments/{salaryPayment}/payments', [SalaryPaymentController::class, 'recordPayment']);
        Route::put('/salary-payments/{salaryPayment}/payments/{payment}', [SalaryPaymentController::class, 'updatePayment']);
        Route::delete('/salary-payments/{salaryPayment}/payments/{payment}', [SalaryPaymentController::class, 'deletePayment']);
    });

    // Work structure: mines (the deposit), projects (the billed work) and
    // worksites (where people clock in). All three share one permission — they
    // are one org chart, and a user who may edit a site may edit its structure.
    Route::middleware('can:worksites.manage')->group(function () {
        Route::get('/mines', [MineController::class, 'index']);
        Route::post('/mines', [MineController::class, 'store']);
        Route::get('/mines/{mine}', [MineController::class, 'show']);
        Route::put('/mines/{mine}', [MineController::class, 'update']);
        Route::delete('/mines/{mine}', [MineController::class, 'destroy']);

        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::put('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

        Route::get('/worksites', [WorksiteController::class, 'index']);
        Route::post('/worksites', [WorksiteController::class, 'store']);
        Route::get('/worksites/{worksite}', [WorksiteController::class, 'show']);
        Route::put('/worksites/{worksite}', [WorksiteController::class, 'update']);
        Route::delete('/worksites/{worksite}', [WorksiteController::class, 'destroy']);
        Route::put('/worksites/{worksite}/employees', [WorksiteController::class, 'syncEmployees']);
    });

    // Masters / supervisors and the worksites they cover.
    Route::middleware('can:masters.manage')->group(function () {
        Route::get('/masters', [MasterController::class, 'index']);
        Route::post('/masters', [MasterController::class, 'store']);
        Route::get('/masters/{master}', [MasterController::class, 'show']);
        Route::put('/masters/{master}', [MasterController::class, 'update']);
        Route::delete('/masters/{master}', [MasterController::class, 'destroy']);
    });

    // Daily attendance + daily earned pay. Masters are limited to their own
    // worksites (MasterAccessService); approval is what releases a day to payroll.
    Route::get('/attendance/roster', [AttendanceController::class, 'roster'])->middleware('can:attendance.submit');
    Route::get('/attendance/summary', [AttendanceController::class, 'summary'])->middleware('can:attendance.submit');
    Route::get('/attendance/payroll-preparation', [AttendanceController::class, 'payrollPreparation'])
        ->middleware('can:salary_payments.manage');
    Route::get('/attendance', [AttendanceController::class, 'index'])->middleware('can:attendance.submit');
    Route::post('/attendance', [AttendanceController::class, 'storeDay'])->middleware('can:attendance.submit');
    Route::post('/attendance/submit', [AttendanceController::class, 'submit'])->middleware('can:attendance.submit');
    Route::post('/attendance/approve', [AttendanceController::class, 'approve'])->middleware('can:attendance.approve');
    Route::post('/attendance/reject', [AttendanceController::class, 'reject'])->middleware('can:attendance.approve');
    Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->middleware('can:attendance.submit');
    Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroy'])->middleware('can:attendance.submit');
    Route::post('/attendance/{attendance}/adjust', [AttendanceController::class, 'adjust'])
        ->middleware('can:attendance.approve');

    // Working days per month (the daily-pay divisor); overriding needs a reason.
    Route::get('/working-days', [WorkingDayController::class, 'show'])->middleware('can:attendance.submit');
    Route::put('/working-days', [WorkingDayController::class, 'update'])->middleware('can:attendance.approve');
    Route::delete('/working-days', [WorkingDayController::class, 'destroy'])->middleware('can:attendance.approve');

    // Mining production (tons of bauxite ore per day / per month).
    Route::get('/production/totals', [ProductionRecordController::class, 'totals'])
        ->middleware('can:mining_production.submit');
    Route::get('/production', [ProductionRecordController::class, 'index'])->middleware('can:mining_production.submit');
    Route::post('/production', [ProductionRecordController::class, 'store'])->middleware('can:mining_production.submit');
    Route::get('/production/{production}', [ProductionRecordController::class, 'show'])
        ->middleware('can:mining_production.submit');
    Route::put('/production/{production}', [ProductionRecordController::class, 'update'])
        ->middleware('can:mining_production.submit');
    Route::delete('/production/{production}', [ProductionRecordController::class, 'destroy'])
        ->middleware('can:mining_production.submit');
    Route::post('/production/{production}/approve', [ProductionRecordController::class, 'approve'])
        ->middleware('can:mining_production.approve');
    Route::post('/production/{production}/reject', [ProductionRecordController::class, 'reject'])
        ->middleware('can:mining_production.approve');

    // Machines / company equipment and their paperwork.
    Route::middleware('can:machines.manage')->group(function () {
        Route::get('/machines/register', [MachineController::class, 'register']);
        Route::get('/machines', [MachineController::class, 'index']);
        Route::post('/machines', [MachineController::class, 'store']);
        Route::get('/machines/{machine}', [MachineController::class, 'show']);
        Route::put('/machines/{machine}', [MachineController::class, 'update']);
        Route::delete('/machines/{machine}', [MachineController::class, 'destroy']);

        Route::get('/machines/{machine}/attachments', [MachineAttachmentController::class, 'index']);
        Route::post('/machines/{machine}/attachments', [MachineAttachmentController::class, 'store']);
        Route::get('/machines/{machine}/attachments/{attachment}', [MachineAttachmentController::class, 'download']);
        Route::delete('/machines/{machine}/attachments/{attachment}', [MachineAttachmentController::class, 'destroy']);
    });

    // Customs & transport documents (CMR first), primarily tied to machine invoices.
    Route::middleware('can:customs_documents.manage')->group(function () {
        Route::get('/customs-documents/register', [CustomsDocumentController::class, 'register']);
        Route::get('/customs-documents', [CustomsDocumentController::class, 'index']);
        Route::post('/customs-documents', [CustomsDocumentController::class, 'store']);
        Route::get('/customs-documents/{customsDocument}', [CustomsDocumentController::class, 'show']);
        Route::put('/customs-documents/{customsDocument}', [CustomsDocumentController::class, 'update']);
        Route::delete('/customs-documents/{customsDocument}', [CustomsDocumentController::class, 'destroy']);

        Route::get('/customs-documents/{customsDocument}/attachments', [CustomsDocumentAttachmentController::class, 'index']);
        Route::post('/customs-documents/{customsDocument}/attachments', [CustomsDocumentAttachmentController::class, 'store']);
        Route::get('/customs-documents/{customsDocument}/attachments/{attachment}', [CustomsDocumentAttachmentController::class, 'download']);
        Route::delete('/customs-documents/{customsDocument}/attachments/{attachment}', [CustomsDocumentAttachmentController::class, 'destroy']);
    });

    // Worker housing: houses, dated occupancy, rent, utility bills and the
    // (exceptional) worker deductions. Rent and bills are company costs by default.
    Route::middleware('can:housing.manage')->group(function () {
        Route::get('/housing/summary', HousingSummaryController::class);

        Route::get('/houses', [HouseController::class, 'index']);
        Route::post('/houses', [HouseController::class, 'store']);
        Route::get('/houses/{house}', [HouseController::class, 'show']);
        Route::put('/houses/{house}', [HouseController::class, 'update']);
        Route::delete('/houses/{house}', [HouseController::class, 'destroy']);

        Route::get('/housing/occupancies', [HouseOccupancyController::class, 'index']);
        Route::post('/housing/occupancies', [HouseOccupancyController::class, 'store']);
        Route::put('/housing/occupancies/{occupancy}', [HouseOccupancyController::class, 'update']);
        Route::delete('/housing/occupancies/{occupancy}', [HouseOccupancyController::class, 'destroy']);

        Route::post('/housing/rent/generate', [RentPaymentController::class, 'generate']);
        Route::get('/housing/rent', [RentPaymentController::class, 'index']);
        Route::post('/housing/rent', [RentPaymentController::class, 'store']);
        Route::put('/housing/rent/{rentPayment}', [RentPaymentController::class, 'update']);
        Route::delete('/housing/rent/{rentPayment}', [RentPaymentController::class, 'destroy']);
        Route::post('/housing/rent/{rentPayment}/payments', [RentPaymentController::class, 'recordPayment']);
        Route::put('/housing/rent/{rentPayment}/payments/{payment}', [RentPaymentController::class, 'updatePayment']);
        Route::delete('/housing/rent/{rentPayment}/payments/{payment}', [RentPaymentController::class, 'deletePayment']);

        Route::get('/housing/bills', [UtilityBillController::class, 'index']);
        Route::post('/housing/bills', [UtilityBillController::class, 'store']);
        Route::get('/housing/bills/{bill}', [UtilityBillController::class, 'show']);
        Route::put('/housing/bills/{bill}', [UtilityBillController::class, 'update']);
        Route::delete('/housing/bills/{bill}', [UtilityBillController::class, 'destroy']);
        Route::post('/housing/bills/{bill}/payments', [UtilityBillController::class, 'recordPayment']);
        Route::put('/housing/bills/{bill}/payments/{payment}', [UtilityBillController::class, 'updatePayment']);
        Route::delete('/housing/bills/{bill}/payments/{payment}', [UtilityBillController::class, 'deletePayment']);
        // Charging a bill on to the occupants — the explicit exception.
        Route::post('/housing/bills/{bill}/split', [UtilityBillController::class, 'split']);

        Route::get('/housing/bills/{bill}/attachments', [UtilityBillAttachmentController::class, 'index']);
        Route::post('/housing/bills/{bill}/attachments', [UtilityBillAttachmentController::class, 'store']);
        Route::get('/housing/bills/{bill}/attachments/{attachment}', [UtilityBillAttachmentController::class, 'download']);
        Route::delete('/housing/bills/{bill}/attachments/{attachment}', [UtilityBillAttachmentController::class, 'destroy']);

        Route::get('/housing/deductions', [HousingDeductionController::class, 'index']);
        Route::post('/housing/deductions', [HousingDeductionController::class, 'store']);
        Route::put('/housing/deductions/{deduction}', [HousingDeductionController::class, 'update']);
        Route::delete('/housing/deductions/{deduction}', [HousingDeductionController::class, 'destroy']);
    });

    // Travel: flight tickets, road/car costs and social assistance. Tickets are
    // bought in TRY, so every row keeps its original amount plus the EUR value
    // and the rate that produced it.
    Route::middleware('can:travel.manage')->group(function () {
        Route::get('/travel/summary', TravelSummaryController::class);

        Route::get('/travel/tickets', [FlightTicketController::class, 'index']);
        Route::post('/travel/tickets', [FlightTicketController::class, 'store']);
        Route::get('/travel/tickets/{ticket}', [FlightTicketController::class, 'show']);
        Route::put('/travel/tickets/{ticket}', [FlightTicketController::class, 'update']);
        Route::delete('/travel/tickets/{ticket}', [FlightTicketController::class, 'destroy']);
        Route::post('/travel/tickets/{ticket}/payments', [FlightTicketController::class, 'recordPayment']);
        Route::put('/travel/tickets/{ticket}/payments/{payment}', [FlightTicketController::class, 'updatePayment']);
        Route::delete('/travel/tickets/{ticket}/payments/{payment}', [FlightTicketController::class, 'deletePayment']);

        Route::get('/travel/tickets/{ticket}/attachments', [FlightTicketAttachmentController::class, 'index']);
        Route::post('/travel/tickets/{ticket}/attachments', [FlightTicketAttachmentController::class, 'store']);
        Route::get('/travel/tickets/{ticket}/attachments/{attachment}', [FlightTicketAttachmentController::class, 'download']);
        Route::delete('/travel/tickets/{ticket}/attachments/{attachment}', [FlightTicketAttachmentController::class, 'destroy']);

        Route::get('/travel/expenses', [TravelExpenseController::class, 'index']);
        Route::post('/travel/expenses', [TravelExpenseController::class, 'store']);
        Route::get('/travel/expenses/{travelExpense}', [TravelExpenseController::class, 'show']);
        Route::put('/travel/expenses/{travelExpense}', [TravelExpenseController::class, 'update']);
        Route::delete('/travel/expenses/{travelExpense}', [TravelExpenseController::class, 'destroy']);
        Route::post('/travel/expenses/{travelExpense}/payments', [TravelExpenseController::class, 'recordPayment']);
        Route::put('/travel/expenses/{travelExpense}/payments/{payment}', [TravelExpenseController::class, 'updatePayment']);
        Route::delete('/travel/expenses/{travelExpense}/payments/{payment}', [TravelExpenseController::class, 'deletePayment']);

        Route::get('/travel/expenses/{travelExpense}/attachments', [TravelExpenseAttachmentController::class, 'index']);
        Route::post('/travel/expenses/{travelExpense}/attachments', [TravelExpenseAttachmentController::class, 'store']);
        Route::get('/travel/expenses/{travelExpense}/attachments/{attachment}', [TravelExpenseAttachmentController::class, 'download']);
        Route::delete('/travel/expenses/{travelExpense}/attachments/{attachment}', [TravelExpenseAttachmentController::class, 'destroy']);

        // Annual entitlement per worker: the payments are stored, what is still
        // payable is computed from them.
        Route::get('/travel/social-assistance/summary', [SocialAssistanceController::class, 'summary']);
        Route::get('/travel/social-assistance', [SocialAssistanceController::class, 'index']);
        Route::post('/travel/social-assistance', [SocialAssistanceController::class, 'store']);
        Route::get('/travel/social-assistance/{socialAssistance}', [SocialAssistanceController::class, 'show']);
        Route::put('/travel/social-assistance/{socialAssistance}', [SocialAssistanceController::class, 'update']);
        Route::delete('/travel/social-assistance/{socialAssistance}', [SocialAssistanceController::class, 'destroy']);
    });

    // Loans and advances (North-Ex and the like). Repayments are ordinary
    // polymorphic payments, so they match to bank/cash movements like any other.
    Route::middleware('can:loans.manage')->group(function () {
        Route::get('/loans', [LoanController::class, 'index']);
        Route::post('/loans', [LoanController::class, 'store']);
        Route::get('/loans/{loan}', [LoanController::class, 'show']);
        Route::put('/loans/{loan}', [LoanController::class, 'update']);
        Route::delete('/loans/{loan}', [LoanController::class, 'destroy']);
        Route::post('/loans/{loan}/repayments', [LoanController::class, 'recordRepayment']);
        Route::put('/loans/{loan}/repayments/{payment}', [LoanController::class, 'updateRepayment']);
        Route::delete('/loans/{loan}/repayments/{payment}', [LoanController::class, 'deleteRepayment']);
    });

    // Worker needs raised from the field and handled by the office.
    Route::middleware('can:worker_needs.manage')->group(function () {
        Route::get('/worker-needs', [WorkerNeedController::class, 'index']);
        Route::post('/worker-needs', [WorkerNeedController::class, 'store']);
        Route::get('/worker-needs/{workerNeed}', [WorkerNeedController::class, 'show']);
        Route::put('/worker-needs/{workerNeed}', [WorkerNeedController::class, 'update']);
        Route::delete('/worker-needs/{workerNeed}', [WorkerNeedController::class, 'destroy']);
    });

    // Notification centre. These are always the signed-in user's own rows, so
    // they need no permission beyond being authenticated.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::put('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);
    Route::put('/notifications/{notification}/resolve', [NotificationController::class, 'resolve']);

    // Personal opt-outs: a user always governs their own preferences.
    Route::get('/me/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('/me/notification-preferences', [NotificationPreferenceController::class, 'update']);

    // Rule configuration and custom reminders are an Admin job.
    Route::middleware('can:notifications.configure')->group(function () {
        Route::get('/settings/notification-rules', [NotificationRuleController::class, 'index']);
        Route::put('/settings/notification-rules', [NotificationRuleController::class, 'update']);
        Route::post('/notifications/scan', [NotificationRuleController::class, 'scan']);
        Route::post('/notifications/reminders', [NotificationController::class, 'storeReminder']);
    });

    // Excel import: upload parses into a preview, and nothing reaches the real
    // tables until the batch is approved.
    Route::middleware('can:imports.manage')->group(function () {
        // Choose what to import, then download the template for it. Static
        // segments precede the {import} binding.
        Route::get('/imports/entities', [ImportController::class, 'entities']);
        Route::get('/imports/template', [ImportController::class, 'template']);

        Route::get('/imports', [ImportController::class, 'index']);
        Route::post('/imports', [ImportController::class, 'store']);
        Route::get('/imports/{import}', [ImportController::class, 'show']);
        Route::put('/imports/{import}/rows', [ImportController::class, 'updateRows']);
        Route::post('/imports/{import}/commit', [ImportController::class, 'commit']);
        Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel']);
        Route::delete('/imports/{import}', [ImportController::class, 'destroy']);
    });

    // Reports. Each report carries its own permission, so the catalogue and the
    // data are both filtered to what the user may already see elsewhere.
    Route::middleware('can:reports.view')->group(function () {
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{key}', [ReportController::class, 'show']);
        Route::get('/reports/{key}/export', [ReportController::class, 'export'])
            ->middleware('can:reports.export');
    });

    // LLM assistant. Asking never writes; `confirm` is the only route that can
    // turn a proposal into a record, and only for the user who was shown it.
    Route::middleware('can:assistant.use')->group(function () {
        Route::get('/assistant/messages', [AssistantController::class, 'history']);
        Route::post('/assistant/ask', [AssistantController::class, 'ask']);
        Route::delete('/assistant/messages', [AssistantController::class, 'clear']);
        Route::post('/assistant/suggestions/{suggestion}/confirm', [AssistantController::class, 'confirm']);
        Route::post('/assistant/suggestions/{suggestion}/reject', [AssistantController::class, 'reject']);
    });

    // Audit trail. Read-only by design — see AuditLogController.
    Route::middleware('can:audit_logs.view')->group(function () {
        Route::get('/audit-logs/filters', [AuditLogController::class, 'filters']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    // Role & permission management (Super Admin bypasses via Gate::before).
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('can:roles.manage');
    Route::apiResource('roles', RoleController::class)->middleware('can:roles.manage');
    Route::post('/roles/{role}/clone', [RoleController::class, 'clone'])->middleware('can:roles.manage');

    // Logins are granted here, not self-registered — there is no register route.
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [UserAccessController::class, 'index']);
        Route::post('/users', [UserAccessController::class, 'store']);
        Route::put('/users/{user}', [UserAccessController::class, 'update']);
        Route::put('/users/{user}/password', [UserAccessController::class, 'updatePassword']);
        Route::put('/users/{user}/roles', [UserAccessController::class, 'syncRoles']);
        Route::put('/users/{user}/extra-permissions', [UserAccessController::class, 'syncPermissions']);
        // Deactivation stays the norm — it keeps the created_by/updated_by
        // stamps and audit rows pointing at the account intact (rule 3). Delete
        // is the narrow exception: a Super Admin removing a login they granted
        // themselves, password-confirmed. UserAccessController::destroy holds
        // every one of those conditions.
        Route::delete('/users/{user}', [UserAccessController::class, 'destroy']);
    });
});
