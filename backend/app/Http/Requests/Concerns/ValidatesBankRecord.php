<?php

namespace App\Http\Requests\Concerns;

use App\Contracts\SettlementLine;
use App\Models\BankTransaction;
use App\Services\PaymentBankMovement;
use Illuminate\Contracts\Validation\Validator;

/**
 * The two ways a settlement reaches the bank ledger, and the rules that keep
 * them apart.
 *
 * `book_bank_transaction` writes the movement this settlement records;
 * `bank_transaction_id` points at one already typed off a statement. They are
 * mutually exclusive — asking for both would book the same money twice — and
 * booking needs a settlement that names an account, since a settlement
 * naming none moved no money through one.
 *
 * Shared by every module that takes settlements (invoices, rent, bills,
 * salaries, tickets, travel expenses, loans, social assistance), so a request
 * cannot be accepted in one module and refused in another.
 */
trait ValidatesBankRecord
{
    /**
     * The rules for both fields, merged into a request's own.
     *
     * `bank_transaction_id` is explicitly nullable: clearing it is how a wrong
     * match is undone.
     *
     * @return array<string, list<string>>
     */
    protected function bankRecordRules(): array
    {
        return [
            'bank_transaction_id' => ['sometimes', 'nullable', 'integer', 'exists:bankovne_transakcije,id'],
            // Without a rule of its own the flag is dropped by validated(), and
            // the API answers 200 while writing nothing at all.
            'book_bank_transaction' => ['sometimes', 'boolean'],
        ];
    }

    /** The check that runs after the field rules pass. */
    protected function validateBankRecord(Validator $validator): void
    {
        // A movement this app generated from another settlement is not a
        // statement line waiting to be matched — it already belongs to one.
        // Pointing a second settlement at it let that one rewrite or delete
        // somebody else's booked money.
        if ($this->filled('bank_transaction_id')) {
            $movement = BankTransaction::find($this->input('bank_transaction_id'));

            if (PaymentBankMovement::isGenerated($movement)) {
                $validator->errors()->add(
                    'bank_transaction_id',
                    'That movement was recorded from another settlement. Match a movement typed from a bank statement instead.',
                );
            }
        }

        if (! $this->boolean('book_bank_transaction')) {
            return;
        }

        if ($this->filled('bank_transaction_id')) {
            $validator->errors()->add(
                'book_bank_transaction',
                'Either record a new bank movement for this payment or match an existing one, not both.',
            );
        }

        // A settlement that names no account did not move money through one,
        // so there is nothing to book — the caller has to say which account the
        // money moved through before a movement can be written.
        if (! PaymentBankMovement::supports($this->accountForBooking())) {
            $validator->errors()->add(
                'book_bank_transaction',
                'Choose an account to record a bank movement for this payment.',
            );
        }

        if ($this->existingLine()?->linkedMovementId() !== null) {
            $validator->errors()->add(
                'book_bank_transaction',
                'This payment already has a bank movement.',
            );
        }
    }

    /** The settlement's own columns — the booking flag is an instruction, not data. */
    public function paymentData(): array
    {
        return collect($this->validated())->except('book_bank_transaction')->all();
    }

    /** Whether the caller asked for the movement to be written. */
    public function booksMovement(): bool
    {
        return $this->boolean('book_bank_transaction');
    }

    /**
     * The account the settlement will name once this request is applied, which
     * on a correction may not be in the payload at all.
     */
    private function accountForBooking(): ?int
    {
        return $this->filled('account_id')
            ? (int) $this->input('account_id')
            : $this->existingLine()?->lineAccountId();
    }

    /**
     * The line being corrected, when there is one. Routes name it `payment`;
     * a module whose line is the record itself overrides this.
     */
    protected function existingLine(): ?SettlementLine
    {
        $line = $this->route('payment');

        return $line instanceof SettlementLine ? $line : null;
    }
}
