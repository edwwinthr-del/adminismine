<?php

namespace App\Services\Import;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Employee;
use App\Models\FlightTicket;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Loan;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\SocialAssistancePayment;
use App\Models\Supplier;
use App\Models\TravelExpense;
use App\Support\Import\LabelNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes approved rows into the real tables. Runs only after a human has looked
 * at the preview; rows marked `skip` are never touched, and an existing record
 * is never overwritten — a row that would collide is skipped and reported.
 *
 * Every created record carries `source = 'import'` so imported data stays
 * distinguishable from data typed into the app.
 */
class ImportCommitter
{
    private const SOURCE = 'import';

    /** @return array{imported: int, skipped: int, failed: int} */
    public function commit(ImportBatch $batch): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        $batch->rows()->where('action', 'skip')->where('status', 'pending')
            ->update(['status' => 'skipped']);
        $result['skipped'] = $batch->rows()->where('status', 'skipped')->count();

        // Parties first, so invoices and tickets can be linked to them.
        foreach (['supplier', 'client', 'employee', 'payable_invoice', 'receivable_invoice'] as $target) {
            $this->commitTarget($batch, $target, $result);
        }
        foreach (['client_payment', 'bank_transaction', 'loan', 'flight_ticket',
            'travel_expense', 'social_assistance_payment', 'employee_bank_account'] as $target) {
            $this->commitTarget($batch, $target, $result);
        }

        $batch->forceFill(['status' => 'imported', 'imported_at' => now()])->save();

        return $result;
    }

    private function commitTarget(ImportBatch $batch, string $target, array &$result): void
    {
        $rows = $batch->rows()->importable()->where('target', $target)->orderBy('row_number')->get();

        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($row, &$result): void {
                    $record = $this->createFor($row);

                    if ($record === null) {
                        $row->forceFill(['status' => 'skipped'])->save();
                        $result['skipped']++;

                        return;
                    }

                    $row->forceFill([
                        'status' => 'imported',
                        'record_type' => $record::class,
                        'record_id' => $record->getKey(),
                    ])->save();
                    $result['imported']++;
                });
            } catch (Throwable $exception) {
                $row->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();
                $result['failed']++;
            }
        }
    }

    /** @return Model|null null means "nothing to write" rather than a failure */
    private function createFor(ImportRow $row): ?Model
    {
        $mapped = $row->mapped ?? [];

        return match ($row->target) {
            'supplier' => $this->party(Supplier::class, $mapped),
            'client' => $this->party(Client::class, $mapped),
            'employee' => $this->employee($mapped),
            'payable_invoice' => $this->payableInvoice($mapped),
            'receivable_invoice' => $this->receivableInvoice($mapped),
            'client_payment' => $this->clientPayment($mapped),
            'bank_transaction' => $this->bankTransaction($mapped),
            'loan' => $this->loan($mapped),
            'flight_ticket' => $this->flightTicket($mapped),
            'travel_expense' => $this->travelExpense($mapped),
            'social_assistance_payment' => $this->socialAssistance($mapped),
            'employee_bank_account' => $this->employeeBankAccount($mapped),
            default => null,
        };
    }

    /**
     * A supplier or client in its own right. An existing name is left untouched
     * rather than overwritten — the preview already flagged it as a duplicate,
     * and importing must never rewrite a record someone is using.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $mapped
     */
    private function party(string $class, array $mapped): ?Model
    {
        $name = $mapped['name'] ?? null;
        $key = LabelNormalizer::nameKey($name);

        if ($key === null) {
            return null;
        }

        $existing = $class::query()->get(['id', 'name'])
            ->first(fn (Model $party): bool => LabelNormalizer::nameKey($party->name) === $key);

        if ($existing !== null) {
            return null;
        }

        return $class::create(array_merge(
            array_intersect_key($mapped, array_flip([
                'name', 'tax_number', 'contact_name', 'phone', 'email', 'address', 'iban', 'notes',
            ])),
            ['source' => self::SOURCE],
        ));
    }

    /** @param array<string, mixed> $mapped */
    private function employee(array $mapped): ?Model
    {
        if (($mapped['first_name'] ?? null) === null || ($mapped['last_name'] ?? null) === null) {
            return null;
        }

        $existing = $this->findEmployee(trim($mapped['first_name'].' '.$mapped['last_name']));

        if ($existing !== null) {
            return null;
        }

        return Employee::create(array_merge(
            array_intersect_key($mapped, array_flip([
                'first_name', 'last_name', 'origin_country', 'passport_number', 'id_number', 'job_role',
                'bank_account_number', 'bank_name', 'bank_account_status', 'base_salary', 'salary_currency',
                'salary_period', 'contract_start_date', 'contract_end_date', 'work_permit_expiry',
                'residence_permit_expiry', 'medical_exam_expiry', 'safety_training_expiry', 'status', 'notes',
            ])),
            ['source' => self::SOURCE],
        ));
    }

    private function payableInvoice(array $mapped): ?Model
    {
        if (($mapped['original_amount'] ?? null) === null) {
            return null;
        }

        $supplier = $this->findOrCreateParty(Supplier::class, $mapped['supplier_name'] ?? null);

        if ($supplier === null) {
            return null;
        }

        $invoice = PayableInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => $mapped['invoice_number'] ?? null,
            'invoice_date' => $mapped['invoice_date'] ?? null,
            'due_date' => $mapped['due_date'] ?? null,
            'description' => $mapped['description'] ?? null,
            'expense_category' => $mapped['expense_category'] ?? null,
            'currency' => $mapped['currency'] ?? 'EUR',
            'original_amount' => $mapped['original_amount'],
            'source' => self::SOURCE,
            'notes' => $mapped['notes'] ?? null,
        ]);

        // What the workbook says was already settled becomes a real payment, so
        // the balance is still derived rather than stored.
        $paid = (float) ($mapped['opening_paid_amount'] ?? 0);
        if ($paid > 0) {
            $invoice->payments()->create([
                'amount' => min($paid, (float) $mapped['original_amount']),
                'currency' => $mapped['currency'] ?? 'EUR',
                'payment_date' => $mapped['invoice_date'] ?? now()->toDateString(),
                'account_id' => null,
                'reference' => 'opening balance (import)',
                'source' => self::SOURCE,
            ]);
        }

        $invoice->recalculate();

        return $invoice;
    }

    private function receivableInvoice(array $mapped): ?Model
    {
        if (($mapped['invoice_amount'] ?? null) === null) {
            return null;
        }

        $client = $this->findOrCreateParty(Client::class, $mapped['client_name'] ?? null);

        if ($client === null) {
            return null;
        }

        $invoice = ReceivableInvoice::create([
            'client_id' => $client->id,
            'invoice_number' => $mapped['invoice_number'] ?? null,
            'invoice_date' => $mapped['invoice_date'] ?? null,
            'due_date' => $mapped['due_date'] ?? null,
            'description' => $mapped['description'] ?? null,
            'currency' => $mapped['currency'] ?? 'EUR',
            'invoice_amount' => $mapped['invoice_amount'],
            'source' => self::SOURCE,
            'notes' => $mapped['notes'] ?? null,
        ]);
        $invoice->recalculate();

        return $invoice;
    }

    /** A statement payment, applied to its invoice when the number matches. */
    private function clientPayment(array $mapped): ?Model
    {
        $amount = $mapped['amount'] ?? null;
        $client = $this->findOrCreateParty(Client::class, $mapped['client_name'] ?? null);

        if ($amount === null || $client === null) {
            return null;
        }

        $invoice = ReceivableInvoice::query()
            ->where('client_id', $client->id)
            ->when(
                ($mapped['invoice_number'] ?? null) !== null,
                fn ($query) => $query->where('invoice_number', $mapped['invoice_number']),
            )
            ->outstanding()
            ->orderBy('invoice_date')
            ->first();

        if ($invoice === null) {
            return null;
        }

        $payment = $invoice->payments()->create([
            'amount' => min((float) $amount, (float) $invoice->remaining_amount),
            'currency' => $mapped['currency'] ?? 'EUR',
            'payment_date' => $mapped['payment_date'] ?? now()->toDateString(),
            'account_id' => null,
            'reference' => $mapped['reference'] ?? null,
            'source' => self::SOURCE,
            'notes' => $mapped['notes'] ?? null,
        ]);
        $invoice->recalculate();

        return $payment;
    }

    private function bankTransaction(array $mapped): ?Model
    {
        if (($mapped['date'] ?? null) === null) {
            return null;
        }

        $lines = $this->movementLines($mapped);

        if ($lines === []) {
            return null;
        }

        $movement = BankTransaction::create([
            'date' => $mapped['date'],
            'description_1' => $mapped['description_1'] ?? null,
            'description_2' => $mapped['description_2'] ?? null,
            'category' => $mapped['category'] ?? null,
            'currency' => $mapped['currency'] ?? 'EUR',
            'import_source' => self::SOURCE,
            'source' => self::SOURCE,
            'notes' => $mapped['notes'] ?? null,
        ]);

        $movement->setLines($lines);

        return $movement;
    }

    /**
     * The movement's lines, from either shape the importer sees: the workbook
     * parsers emit `lines` by account name, the entity template emits one or two
     * account/amount pairs.
     *
     * @return list<array{account_id: int, amount: float}>
     */
    private function movementLines(array $mapped): array
    {
        $pairs = $mapped['lines'] ?? array_values(array_filter([
            ['account' => $mapped['account'] ?? null, 'amount' => $mapped['amount'] ?? 0],
            ['account' => $mapped['account_2'] ?? null, 'amount' => $mapped['amount_2'] ?? 0],
        ], fn (array $line): bool => $line['account'] !== null && (float) $line['amount'] !== 0.0));

        $lines = [];

        foreach ($pairs as $pair) {
            $account = $this->resolveAccount((string) ($pair['account'] ?? ''));

            if ($account === null) {
                continue;
            }

            $lines[] = ['account_id' => $account, 'amount' => (float) $pair['amount']];
        }

        return $lines;
    }

    /**
     * The till, for the one import block that says money was handed over in
     * cash. Null where this company keeps no cash account — the settlement is
     * still recorded, it simply names no account.
     */
    private function cashAccountId(): ?int
    {
        return BankAccount::query()->active()->where('kind', 'cash')->ordered()->value('id');
    }

    /**
     * The account with this name, opened if the import names one that does not
     * exist yet — the same treatment a supplier gets, and what lets a workbook
     * written years ago land on the accounts it was written against.
     */
    private function resolveAccount(string $name): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return BankAccount::query()->where('name', $name)->value('id')
            ?? BankAccount::create([
                'name' => $name,
                // The till is the only account whose kind is guessable from the
                // name the workbook uses for it.
                'kind' => strcasecmp($name, 'Cash') === 0 ? 'cash' : 'bank',
                'source' => self::SOURCE,
            ])->id;
    }

    private function loan(array $mapped): ?Model
    {
        if (($mapped['original_amount'] ?? null) === null || ($mapped['loan_date'] ?? null) === null) {
            return null;
        }

        $loan = Loan::create([
            'counterparty' => $mapped['counterparty'],
            'direction' => $mapped['direction'] ?? 'received',
            'reference_number' => $mapped['reference_number'] ?? null,
            'loan_date' => $mapped['loan_date'],
            'currency' => $mapped['currency'] ?? 'EUR',
            'original_amount' => $mapped['original_amount'],
            'amount_eur' => $mapped['original_amount'],
            'source' => self::SOURCE,
        ]);

        // VRACENO on the sheet means it came back — recorded as a repayment so
        // the balance follows from the history.
        if (($mapped['repaid_in_full'] ?? false) === true) {
            $loan->repayments()->create([
                'amount' => $mapped['original_amount'],
                'currency' => 'EUR',
                'payment_date' => $mapped['loan_date'],
                'account_id' => null,
                'reference' => 'VRACENO (import)',
                'source' => self::SOURCE,
            ]);
        }

        $loan->recalculate();

        return $loan;
    }

    private function flightTicket(array $mapped): ?Model
    {
        if (($mapped['amount_eur'] ?? null) === null) {
            return null;
        }

        $employee = $this->findEmployee($mapped['passenger_name'] ?? null);

        $ticket = FlightTicket::create([
            'employee_id' => $employee?->id,
            'passenger_name' => $mapped['passenger_name'] ?? null,
            'ticket_date' => $mapped['ticket_date'] ?? now()->toDateString(),
            'direction' => $mapped['direction'] ?? 'departure',
            'currency' => $mapped['currency'] ?? 'TRY',
            'amount' => $mapped['amount'] ?? $mapped['amount_eur'],
            'exchange_rate' => $mapped['exchange_rate'] ?? null,
            'amount_eur' => $mapped['amount_eur'],
            'cost_status' => $mapped['cost_status'] ?? 'not_written',
            'source' => self::SOURCE,
        ]);
        $ticket->recalculate();

        return $ticket;
    }

    private function travelExpense(array $mapped): ?Model
    {
        if (($mapped['amount'] ?? null) === null) {
            return null;
        }

        $employee = $this->findEmployee($mapped['person_name'] ?? null);

        $expense = TravelExpense::create([
            'employee_id' => $employee?->id,
            'person_name' => $mapped['person_name'] ?? null,
            'expense_date' => $mapped['expense_date'] ?? now()->toDateString(),
            'period_month' => $mapped['period_month'] ?? now()->startOfMonth()->toDateString(),
            'expense_type' => $mapped['expense_type'] ?? 'other',
            'currency' => $mapped['currency'] ?? 'EUR',
            'amount' => $mapped['amount'],
            'amount_eur' => $mapped['amount'],
            'cost_status' => $mapped['cost_status'] ?? 'not_written',
            'source' => self::SOURCE,
        ]);

        // The "paid travel" block is money already handed over.
        if (($mapped['settled'] ?? false) === true) {
            $expense->payments()->create([
                'amount' => $mapped['amount'],
                'currency' => 'EUR',
                'payment_date' => $mapped['expense_date'] ?? now()->toDateString(),
                'account_id' => $this->cashAccountId(),
                'reference' => 'paid on import',
                'source' => self::SOURCE,
            ]);
        }

        $expense->recalculate();

        return $expense;
    }

    private function socialAssistance(array $mapped): ?Model
    {
        if (($mapped['amount'] ?? null) === null) {
            return null;
        }

        $employee = $this->findEmployee($mapped['person_name'] ?? null);

        return SocialAssistancePayment::create([
            'employee_id' => $employee?->id,
            'person_name' => $mapped['person_name'] ?? null,
            'payment_date' => $mapped['payment_date'] ?? now()->toDateString(),
            'entitlement_year' => $mapped['entitlement_year'] ?? (int) now()->year,
            'currency' => $mapped['currency'] ?? 'EUR',
            'amount' => $mapped['amount'],
            'amount_eur' => $mapped['amount'],
            'source' => self::SOURCE,
        ]);
    }

    /**
     * The only target that touches an existing record. It updates one field on a
     * worker who must already exist — an unmatched name writes nothing.
     */
    private function employeeBankAccount(array $mapped): ?Model
    {
        $employee = $this->findEmployee($mapped['employee_name'] ?? null);

        if ($employee === null) {
            return null;
        }

        $employee->forceFill([
            'bank_account_status' => $mapped['bank_account_status'] ?? 'unknown',
        ])->save();

        return $employee;
    }

    /** @param class-string<Model> $class */
    private function findOrCreateParty(string $class, ?string $name): ?Model
    {
        $key = LabelNormalizer::nameKey($name);

        if ($key === null) {
            return null;
        }

        $existing = $class::query()->get(['id', 'name'])
            ->first(fn (Model $party): bool => LabelNormalizer::nameKey($party->name) === $key);

        // The original spelling is kept exactly as the workbook wrote it.
        return $existing ?? $class::create(['name' => trim($name), 'source' => self::SOURCE]);
    }

    private function findEmployee(?string $name): ?Employee
    {
        $key = LabelNormalizer::nameKey($name);

        if ($key === null) {
            return null;
        }

        return Employee::query()->get(['id', 'first_name', 'last_name'])
            ->first(fn (Employee $employee): bool => LabelNormalizer::nameKey($employee->full_name) === $key);
    }
}
