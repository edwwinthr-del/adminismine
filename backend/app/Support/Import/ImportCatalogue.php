<?php

namespace App\Support\Import;

use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\FlightTicket;
use App\Models\Loan;
use App\Models\TravelExpense;

/**
 * What can be imported, and in what shape.
 *
 * This is the one description of an import format in the app. The downloadable
 * template is generated from it, the uploaded file is validated against it, and
 * the frontend's entity picker is rendered from it — so a template a user
 * downloads is by construction the format the importer accepts.
 *
 * Enum columns list the app's canonical values (rule 4): a template never asks
 * for a translated label, and an imported cell is never stored as one.
 */
final class ImportCatalogue
{
    /** @return array<string, ImportEntity> */
    public static function all(): array
    {
        return [
            'payable_invoice' => new ImportEntity(
                key: 'payable_invoice',
                label: 'Supplier invoices (payables)',
                target: 'payable_invoice',
                permission: 'payables.create',
                description: 'Debts owed to suppliers. A supplier that does not exist yet is created from its name.',
                columns: [
                    new ImportColumn('supplier_name', 'Supplier', required: true, example: 'Acme Doo',
                        help: 'Matched to an existing supplier by name; created if there is no match.'),
                    new ImportColumn('invoice_number', 'Invoice number', example: 'F-2026-014'),
                    new ImportColumn('invoice_date', 'Invoice date', type: 'date', required: true, example: '2026-03-06'),
                    new ImportColumn('due_date', 'Due date', type: 'date', example: '2026-04-06'),
                    new ImportColumn('description', 'Description', example: 'Spare parts'),
                    new ImportColumn('expense_category', 'Category', example: 'parts'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('original_amount', 'Amount', type: 'decimal', required: true, example: '1250.00'),
                    new ImportColumn('opening_paid_amount', 'Already paid', type: 'decimal', example: '250.00',
                        help: 'Becomes a real payment, so the balance stays derived from the payments.'),
                    new ImportColumn('notes', 'Notes'),
                ],
            ),

            'receivable_invoice' => new ImportEntity(
                key: 'receivable_invoice',
                label: 'Client invoices (receivables)',
                target: 'receivable_invoice',
                permission: 'receivables.manage',
                description: 'Invoices issued to clients. A client that does not exist yet is created from its name.',
                columns: [
                    new ImportColumn('client_name', 'Client', required: true, example: 'Uniprom'),
                    new ImportColumn('invoice_number', 'Invoice number', example: '2026/114'),
                    new ImportColumn('invoice_date', 'Invoice date', type: 'date', required: true, example: '2026-03-06'),
                    new ImportColumn('due_date', 'Due date', type: 'date', example: '2026-04-06'),
                    new ImportColumn('description', 'Description', example: 'Bauxite delivery'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('invoice_amount', 'Amount', type: 'decimal', required: true, example: '18400.00'),
                    new ImportColumn('notes', 'Notes'),
                ],
            ),

            'bank_transaction' => new ImportEntity(
                key: 'bank_transaction',
                label: 'Bank & cash movements',
                target: 'bank_transaction',
                permission: 'bank_transactions.manage',
                description: 'One row per movement. Amounts are signed: + money in, − money out. '
                    .'A transfer between two accounts is one row with both sides filled in.',
                columns: [
                    new ImportColumn('date', 'Date', type: 'date', required: true, example: '2026-03-06'),
                    new ImportColumn('description_1', 'Description', example: 'Supplier payment'),
                    new ImportColumn('description_2', 'Second description'),
                    new ImportColumn('cash_amount', 'Cash', type: 'decimal', example: '-500.00'),
                    new ImportColumn('nlb_amount', 'NLB', type: 'decimal', example: '0'),
                    new ImportColumn('lovcen_amount', 'Lovćen', type: 'decimal', example: '0'),
                    new ImportColumn('category', 'Category', type: 'enum', values: BankTransaction::CATEGORIES,
                        example: 'expense'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('notes', 'Notes'),
                ],
            ),

            'supplier' => new ImportEntity(
                key: 'supplier',
                label: 'Suppliers',
                target: 'supplier',
                permission: 'payables.create',
                description: 'A supplier whose name already exists is reported as a duplicate and left alone.',
                columns: [
                    new ImportColumn('name', 'Name', required: true, example: 'Acme Doo'),
                    new ImportColumn('tax_number', 'Tax number', example: '02345678'),
                    new ImportColumn('contact_name', 'Contact'),
                    new ImportColumn('phone', 'Phone'),
                    new ImportColumn('email', 'Email'),
                    new ImportColumn('address', 'Address'),
                    new ImportColumn('iban', 'IBAN'),
                    new ImportColumn('notes', 'Notes'),
                ],
            ),

            'client' => new ImportEntity(
                key: 'client',
                label: 'Clients',
                target: 'client',
                permission: 'receivables.manage',
                description: 'A client whose name already exists is reported as a duplicate and left alone.',
                columns: [
                    new ImportColumn('name', 'Name', required: true, example: 'Uniprom'),
                    new ImportColumn('tax_number', 'Tax number'),
                    new ImportColumn('contact_name', 'Contact'),
                    new ImportColumn('phone', 'Phone'),
                    new ImportColumn('email', 'Email'),
                    new ImportColumn('address', 'Address'),
                    new ImportColumn('iban', 'IBAN'),
                    new ImportColumn('notes', 'Notes'),
                ],
            ),

            'employee' => new ImportEntity(
                key: 'employee',
                label: 'Workers',
                target: 'employee',
                permission: 'employees.manage',
                description: 'Worker profiles. A worker whose name already exists is reported rather than duplicated.',
                columns: [
                    new ImportColumn('first_name', 'First name', required: true, example: 'Ahmet'),
                    new ImportColumn('last_name', 'Last name', required: true, example: 'Yılmaz'),
                    new ImportColumn('origin_country', 'Country', example: 'Türkiye'),
                    new ImportColumn('passport_number', 'Passport number'),
                    new ImportColumn('id_number', 'ID number'),
                    new ImportColumn('job_role', 'Role', example: 'Excavator operator'),
                    new ImportColumn('bank_account_number', 'Bank account'),
                    new ImportColumn('bank_name', 'Bank'),
                    new ImportColumn('bank_account_status', 'Bank account status', type: 'enum',
                        values: Employee::BANK_ACCOUNT_STATUSES, example: 'open'),
                    new ImportColumn('base_salary', 'Base salary', type: 'decimal', example: '900.00'),
                    new ImportColumn('salary_currency', 'Salary currency', type: 'enum',
                        values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('salary_period', 'Salary period', type: 'enum',
                        values: Employee::SALARY_PERIODS, example: 'monthly'),
                    new ImportColumn('contract_start_date', 'Contract start', type: 'date'),
                    new ImportColumn('contract_end_date', 'Contract end', type: 'date'),
                    new ImportColumn('work_permit_expiry', 'Work permit expiry', type: 'date'),
                    new ImportColumn('residence_permit_expiry', 'Residence permit expiry', type: 'date'),
                    new ImportColumn('medical_exam_expiry', 'Medical exam expiry', type: 'date'),
                    new ImportColumn('safety_training_expiry', 'Safety training expiry', type: 'date'),
                    new ImportColumn('status', 'Status', type: 'enum', values: Employee::STATUSES, example: 'active'),
                    new ImportColumn('notes', 'Notes'),
                ],
            ),

            'employee_bank_account' => new ImportEntity(
                key: 'employee_bank_account',
                label: 'Worker bank account status',
                target: 'employee_bank_account',
                permission: 'employees.manage',
                description: 'Updates one field on workers who already exist. A name with no match is reported, '
                    .'never turned into a new worker.',
                columns: [
                    new ImportColumn('employee_name', 'Worker', required: true, example: 'Ahmet Yılmaz'),
                    new ImportColumn('bank_account_status', 'Bank account status', type: 'enum', required: true,
                        values: Employee::BANK_ACCOUNT_STATUSES, example: 'open'),
                ],
            ),

            'loan' => new ImportEntity(
                key: 'loan',
                label: 'Loans & advances',
                target: 'loan',
                permission: 'loans.manage',
                description: 'Money lent or borrowed. Marking a loan repaid records a repayment, '
                    .'so the balance still follows from the history.',
                columns: [
                    new ImportColumn('counterparty', 'Counterparty', required: true, example: 'North-Ex'),
                    new ImportColumn('direction', 'Direction', type: 'enum', values: Loan::DIRECTIONS,
                        example: 'received', help: 'received = we owe it, given = it is owed to us.'),
                    new ImportColumn('reference_number', 'Reference'),
                    new ImportColumn('loan_date', 'Date', type: 'date', required: true, example: '2026-02-01'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('original_amount', 'Amount', type: 'decimal', required: true, example: '5000.00'),
                    new ImportColumn('repaid_in_full', 'Repaid in full', type: 'boolean', example: 'no'),
                ],
            ),

            'flight_ticket' => new ImportEntity(
                key: 'flight_ticket',
                label: 'Flight tickets',
                target: 'flight_ticket',
                permission: 'travel.manage',
                description: 'Tickets are usually bought in TRY: give the original amount and currency and '
                    .'the EUR value that was booked.',
                columns: [
                    new ImportColumn('passenger_name', 'Passenger', required: true, example: 'Ahmet Yılmaz'),
                    new ImportColumn('ticket_date', 'Date', type: 'date', required: true, example: '2026-03-06'),
                    new ImportColumn('direction', 'Direction', type: 'enum', values: FlightTicket::DIRECTIONS,
                        example: 'departure'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'TRY'),
                    new ImportColumn('amount', 'Amount', type: 'decimal', example: '9800.00'),
                    new ImportColumn('exchange_rate', 'Exchange rate', type: 'decimal', example: '38.5',
                        help: 'The rate actually charged. Left empty, the stored rate for the date is used.'),
                    new ImportColumn('amount_eur', 'Amount in EUR', type: 'decimal', required: true, example: '254.55'),
                    new ImportColumn('cost_status', 'Charged to worker', type: 'enum',
                        values: FlightTicket::COST_STATUSES, example: 'not_written'),
                ],
            ),

            'travel_expense' => new ImportEntity(
                key: 'travel_expense',
                label: 'Travel expenses',
                target: 'travel_expense',
                permission: 'travel.manage',
                columns: [
                    new ImportColumn('person_name', 'Person', required: true, example: 'Ahmet Yılmaz'),
                    new ImportColumn('expense_date', 'Date', type: 'date', required: true, example: '2026-03-06'),
                    new ImportColumn('period_month', 'Month', type: 'date', example: '2026-03-01'),
                    new ImportColumn('expense_type', 'Type', type: 'enum', values: TravelExpense::TYPES, example: 'car'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('amount', 'Amount', type: 'decimal', required: true, example: '120.00'),
                    new ImportColumn('cost_status', 'Charged to worker', type: 'enum',
                        values: TravelExpense::COST_STATUSES, example: 'not_written'),
                    new ImportColumn('settled', 'Already paid', type: 'boolean', example: 'no'),
                ],
            ),

            'social_assistance_payment' => new ImportEntity(
                key: 'social_assistance_payment',
                label: 'Social assistance payments',
                target: 'social_assistance_payment',
                permission: 'travel.manage',
                description: 'Payouts only. What is still payable for the year is computed from them, never imported.',
                columns: [
                    new ImportColumn('person_name', 'Person', required: true, example: 'Ahmet Yılmaz'),
                    new ImportColumn('payment_date', 'Date', type: 'date', required: true, example: '2026-03-06'),
                    new ImportColumn('entitlement_year', 'Entitlement year', type: 'integer', example: '2026'),
                    new ImportColumn('currency', 'Currency', type: 'enum', values: ['EUR', 'TRY', 'USD'], example: 'EUR'),
                    new ImportColumn('amount', 'Amount', type: 'decimal', required: true, example: '250.00'),
                ],
            ),
        ];
    }

    public static function find(string $key): ?ImportEntity
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
