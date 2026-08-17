<?php

namespace App\Http\Requests\Payable;

use App\Models\BankTransaction;
use App\Models\Payment;
use App\Services\PaymentBankMovement;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a settlement line. Shared by payables and receivables: a payment
 * is one polymorphic row whichever invoice it settles.
 */
class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route's permission
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'payment_date' => ['sometimes', 'date'],
            'method' => ['sometimes', 'string', 'in:cash,nlb,lovcen,other'],
            // Explicitly nullable: clearing it is how a wrong bank match is undone.
            'bank_transaction_id' => ['sometimes', 'nullable', 'integer', 'exists:bankovne_transakcije,id'],
            // A payment recorded without a movement can be booked afterwards.
            // Without this rule the flag was dropped by validated() and the API
            // answered 200 while writing nothing at all.
            'book_bank_transaction' => ['sometimes', 'boolean'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('bank_transaction_id')) {
                    $movement = BankTransaction::find($this->input('bank_transaction_id'));

                    if (PaymentBankMovement::isGenerated($movement)) {
                        $validator->errors()->add(
                            'bank_transaction_id',
                            'That movement was recorded from another invoice payment. Match a movement typed from a bank statement instead.',
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

                if (! PaymentBankMovement::supports($this->effectiveMethod())) {
                    $validator->errors()->add(
                        'book_bank_transaction',
                        'Choose cash, NLB or Lovćen to record a bank movement for this payment.',
                    );
                }

                if ($this->bookedAlready()) {
                    $validator->errors()->add(
                        'book_bank_transaction',
                        'This payment already has a bank movement.',
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

    /** The method the payment will have after this edit, which may not be in the request. */
    private function effectiveMethod(): ?string
    {
        if ($this->filled('method')) {
            return $this->input('method');
        }

        $payment = $this->route('payment');

        return $payment instanceof Payment ? $payment->method : null;
    }

    private function bookedAlready(): bool
    {
        $payment = $this->route('payment');

        return $payment instanceof Payment && $payment->bank_transaction_id !== null;
    }
}
