<?php

namespace App\Http\Requests\Payable;

use App\Services\PaymentBankMovement;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new settlement line. Shared by payables and receivables — a payment is one
 * polymorphic row whichever invoice it settles.
 *
 * `book_bank_transaction` and `bank_transaction_id` are the two ways a payment
 * reaches the bank ledger, and they are mutually exclusive: either this payment
 * is the record that money moved (write the movement) or it points at a
 * movement already typed off a statement (match it). Asking for both would
 * book the same money twice.
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:payables.create
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'in:cash,nlb,lovcen,other'],
            'bank_transaction_id' => ['nullable', 'integer', 'exists:bank_transactions,id'],
            'book_bank_transaction' => ['sometimes', 'boolean'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('book_bank_transaction')) {
                    return;
                }

                if ($this->filled('bank_transaction_id')) {
                    $validator->errors()->add(
                        'book_bank_transaction',
                        'Either record a new bank movement for this payment or match an existing one, not both.',
                    );
                }

                // `other` names no account, so there is no column to book the
                // amount into — the caller has to say which one the money moved
                // through before a movement can be written.
                if (! PaymentBankMovement::supports($this->input('method'))) {
                    $validator->errors()->add(
                        'book_bank_transaction',
                        'Choose cash, NLB or Lovćen to record a bank movement for this payment.',
                    );
                }
            },
        ];
    }

    /** The payment's own columns — the booking flag is an instruction, not data. */
    public function paymentData(): array
    {
        return collect($this->validated())->except('book_bank_transaction')->all();
    }
}
