<?php

namespace App\Services\Assistant;

use App\Models\AiSuggestion;
use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\TravelExpense;
use App\Models\User;
use App\Models\UtilityBill;
use App\Models\WorkerNeed;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The rules a proposed record has to satisfy, and the code that finally writes
 * one. Validation lives here rather than in the assistant so a suggestion faces
 * exactly the same rules a typed-in record would.
 */
class SuggestionValidator
{
    /** @return array<string, mixed> */
    public function rulesFor(string $target): array
    {
        return match ($target) {
            'utility_bill' => [
                'house_id' => ['required', 'integer', 'exists:houses,id'],
                'bill_type' => ['required', Rule::in(UtilityBill::TYPES)],
                'billing_period' => ['required', 'date'],
                'amount' => ['required', 'numeric', 'min:0'],
                'currency' => ['sometimes', 'string', 'size:3'],
                'due_date' => ['nullable', 'date'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'payable_invoice' => [
                'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
                'invoice_number' => ['nullable', 'string', 'max:255'],
                'invoice_date' => ['required', 'date'],
                'due_date' => ['nullable', 'date'],
                'description' => ['nullable', 'string', 'max:1000'],
                'currency' => ['sometimes', 'string', 'size:3'],
                'original_amount' => ['required', 'numeric', 'gt:0'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'bank_transaction' => [
                'date' => ['required', 'date'],
                'description_1' => ['required', 'string', 'max:255'],
                'cash_amount' => ['sometimes', 'numeric'],
                'nlb_amount' => ['sometimes', 'numeric'],
                'lovcen_amount' => ['sometimes', 'numeric'],
                'category' => ['nullable', 'string', 'max:50'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'worker_need' => [
                'employee_id' => ['required', 'integer', 'exists:employees,id'],
                'date' => ['required', 'date'],
                'need_type' => ['required', Rule::in(WorkerNeed::TYPES)],
                'description' => ['required', 'string', 'max:1000'],
                'priority' => ['sometimes', Rule::in(WorkerNeed::PRIORITIES)],
            ],
            'travel_expense' => [
                'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
                'person_name' => ['nullable', 'required_without:employee_id', 'string', 'max:255'],
                'expense_date' => ['required', 'date'],
                'expense_type' => ['required', Rule::in(TravelExpense::TYPES)],
                'currency' => ['sometimes', 'string', 'size:3'],
                'amount' => ['required', 'numeric', 'min:0'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            default => throw new RuntimeException("No rules for target {$target}."),
        };
    }

    public function permissionFor(string $target): string
    {
        return match ($target) {
            'utility_bill' => 'housing.manage',
            'payable_invoice' => 'payables.create',
            'bank_transaction' => 'bank_transactions.manage',
            'worker_need' => 'worker_needs.manage',
            'travel_expense' => 'travel.manage',
            default => 'assistant.use',
        };
    }

    /**
     * Write a confirmed suggestion. Re-checks permission and validity at the
     * moment of saving, so a suggestion left open while a user's access changed
     * cannot slip through.
     */
    public function apply(AiSuggestion $suggestion, User $user): Model
    {
        if (! $suggestion->isApplicable()) {
            throw new RuntimeException('This suggestion cannot be applied.');
        }

        if (! $user->can($this->permissionFor($suggestion->target))) {
            throw new RuntimeException('You do not have permission to create that.');
        }

        $fields = $suggestion->validated;

        return DB::transaction(function () use ($suggestion, $fields, $user): Model {
            $record = match ($suggestion->target) {
                'utility_bill' => $this->utilityBill($fields),
                'payable_invoice' => $this->payableInvoice($fields),
                'bank_transaction' => $this->bankTransaction($fields),
                'worker_need' => WorkerNeed::create($fields + ['source' => 'assistant', 'status' => 'open']),
                'travel_expense' => $this->travelExpense($fields),
                default => throw new RuntimeException('Unknown target.'),
            };

            $suggestion->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'record_type' => $record::class,
                'record_id' => $record->getKey(),
            ])->save();

            // The audit trail records that a human confirmed an AI proposal.
            activity()->performedOn($record)->causedBy($user)
                ->withProperties([
                    'ai_suggestion_id' => $suggestion->id,
                    'target' => $suggestion->target,
                    'prompt' => $suggestion->prompt,
                ])
                ->log('assistant.suggestion_confirmed');

            return $record;
        });
    }

    private function utilityBill(array $fields): Model
    {
        $bill = UtilityBill::create([
            'house_id' => $fields['house_id'],
            'bill_type' => $fields['bill_type'],
            'billing_period' => MonthPeriod::normalize($fields['billing_period']),
            'amount' => $fields['amount'],
            'currency' => $fields['currency'] ?? 'EUR',
            'due_date' => $fields['due_date'] ?? null,
            'source' => 'assistant',
            'notes' => $fields['notes'] ?? null,
        ]);
        $bill->recalculate();

        return $bill;
    }

    private function payableInvoice(array $fields): Model
    {
        $invoice = PayableInvoice::create($fields + ['source' => 'assistant']);
        $invoice->recalculate();

        return $invoice;
    }

    private function bankTransaction(array $fields): Model
    {
        return BankTransaction::create([
            'date' => $fields['date'],
            'description_1' => $fields['description_1'],
            'cash_amount' => $fields['cash_amount'] ?? 0,
            'nlb_amount' => $fields['nlb_amount'] ?? 0,
            'lovcen_amount' => $fields['lovcen_amount'] ?? 0,
            'category' => $fields['category'] ?? null,
            'currency' => 'EUR',
            'source' => 'assistant',
            'notes' => $fields['notes'] ?? null,
        ]);
    }

    private function travelExpense(array $fields): Model
    {
        $date = $fields['expense_date'];

        $expense = TravelExpense::create([
            'employee_id' => $fields['employee_id'] ?? null,
            'person_name' => $fields['person_name'] ?? null,
            'expense_date' => $date,
            'period_month' => MonthPeriod::normalize($date),
            'expense_type' => $fields['expense_type'],
            'currency' => $fields['currency'] ?? 'EUR',
            'amount' => $fields['amount'],
            // EUR-only from the assistant: a foreign-currency amount needs a
            // rate decision, which is the user's to make, not the model's.
            'amount_eur' => $fields['amount'],
            'source' => 'assistant',
            'notes' => $fields['notes'] ?? null,
        ]);
        $expense->recalculate();

        return $expense;
    }

    /** Suppliers are never created by the assistant — it must pick an existing one. */
    public function supplierExists(int $id): bool
    {
        return Supplier::query()->whereKey($id)->exists();
    }
}
