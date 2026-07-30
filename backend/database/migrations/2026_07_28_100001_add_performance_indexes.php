<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the paths the lists and the dashboard actually take.
 *
 * Two gaps this closes:
 *
 * 1. **Foreign keys.** MySQL indexes an FK column automatically; Postgres does
 *    not, and Postgres is what production runs on. Every `foreignId()` column a
 *    filter, a `whereHas` or a group-by touches is indexed here by hand.
 * 2. **Filter + sort pairs.** Each list filters on a status/date and then sorts
 *    by date and id. A composite in that order lets one index serve both, so
 *    paging deep into a list stays cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payable_invoices', function (Blueprint $table): void {
            $table->index('supplier_id', 'payable_invoices_supplier_id_index');
            // Both the list's default sort and keyset paging over it.
            $table->index(['invoice_date', 'id'], 'payable_invoices_invoice_date_id_index');
            // `outstanding()`/`overdue()`: status first, then the due date test.
            $table->index(['status', 'due_date'], 'payable_invoices_status_due_date_index');
        });

        Schema::table('receivable_invoices', function (Blueprint $table): void {
            $table->index('client_id', 'receivable_invoices_client_id_index');
            $table->index(['invoice_date', 'id'], 'receivable_invoices_invoice_date_id_index');
            $table->index(['status', 'due_date'], 'receivable_invoices_status_due_date_index');
            // The dashboard's "outstanding per client" group-by.
            $table->index(['status', 'client_id'], 'receivable_invoices_status_client_id_index');
        });

        Schema::table('receivable_deductions', function (Blueprint $table): void {
            $table->index('receivable_invoice_id', 'receivable_deductions_invoice_id_index');
        });

        Schema::table('payments', function (Blueprint $table): void {
            // `unmatched()` asks "does any payment point at this movement?" for
            // every row of the bank list; without this it is a scan per row.
            $table->index('bank_transaction_id', 'payments_bank_transaction_id_index');
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->index('supplier_id', 'bank_transactions_supplier_id_index');
            $table->index('client_id', 'bank_transactions_client_id_index');
            $table->index(['date', 'id'], 'bank_transactions_date_id_index');
            // Exactly the duplicate signature, so the group-by behind the
            // duplicate flag is an index scan rather than a sort of the table.
            $table->index(
                ['date', 'cash_amount', 'nlb_amount', 'lovcen_amount'],
                'bank_transactions_signature_index',
            );
        });

        Schema::table('worker_needs', function (Blueprint $table): void {
            $table->index('employee_id', 'worker_needs_employee_id_index');
            $table->index('worksite_id', 'worker_needs_worksite_id_index');
            $table->index('assigned_user_id', 'worker_needs_assigned_user_id_index');
            // The archive: settled needs, newest first.
            $table->index(['status', 'resolved_at'], 'worker_needs_status_resolved_at_index');
        });

        Schema::table('customs_documents', function (Blueprint $table): void {
            $table->index('customs_company_id', 'customs_documents_customs_company_id_index');
            $table->index('payable_invoice_id', 'customs_documents_payable_invoice_id_index');
            $table->index('receivable_invoice_id', 'customs_documents_receivable_invoice_id_index');
        });

        Schema::table('machines', function (Blueprint $table): void {
            $table->index('supplier_id', 'machines_supplier_id_index');
            $table->index('payable_invoice_id', 'machines_payable_invoice_id_index');
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->index('employee_id', 'loans_employee_id_index');
            $table->index('supplier_id', 'loans_supplier_id_index');
            $table->index('client_id', 'loans_client_id_index');
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->index('master_id', 'attendance_records_master_id_index');
        });

        Schema::table('utility_bills', function (Blueprint $table): void {
            // The housing tiles: this month's bills, then the overdue ones.
            $table->index(['billing_period', 'status'], 'utility_bills_period_status_index');
        });
    }

    public function down(): void
    {
        $indexes = [
            'payable_invoices' => [
                'payable_invoices_supplier_id_index',
                'payable_invoices_invoice_date_id_index',
                'payable_invoices_status_due_date_index',
            ],
            'receivable_invoices' => [
                'receivable_invoices_client_id_index',
                'receivable_invoices_invoice_date_id_index',
                'receivable_invoices_status_due_date_index',
                'receivable_invoices_status_client_id_index',
            ],
            'receivable_deductions' => ['receivable_deductions_invoice_id_index'],
            'payments' => ['payments_bank_transaction_id_index'],
            'bank_transactions' => [
                'bank_transactions_supplier_id_index',
                'bank_transactions_client_id_index',
                'bank_transactions_date_id_index',
                'bank_transactions_signature_index',
            ],
            'worker_needs' => [
                'worker_needs_employee_id_index',
                'worker_needs_worksite_id_index',
                'worker_needs_assigned_user_id_index',
                'worker_needs_status_resolved_at_index',
            ],
            'customs_documents' => [
                'customs_documents_customs_company_id_index',
                'customs_documents_payable_invoice_id_index',
                'customs_documents_receivable_invoice_id_index',
            ],
            'machines' => ['machines_supplier_id_index', 'machines_payable_invoice_id_index'],
            'loans' => ['loans_employee_id_index', 'loans_supplier_id_index', 'loans_client_id_index'],
            'attendance_records' => ['attendance_records_master_id_index'],
            'utility_bills' => ['utility_bills_period_status_index'],
        ];

        foreach ($indexes as $table => $names) {
            Schema::table($table, function (Blueprint $blueprint) use ($names): void {
                foreach ($names as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
