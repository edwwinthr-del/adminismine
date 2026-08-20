<?php

namespace App\Http\Requests\Payable;

use App\Http\Requests\Concerns\ValidatesBankRecord;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new settlement line. Shared by payables and receivables — a payment is one
 * polymorphic row whichever invoice it settles.
 *
 * How it reaches the bank ledger is the shared rule in {@see ValidatesBankRecord}.
 */
class RecordPaymentRequest extends FormRequest
{
    use ValidatesBankRecord;

    public function authorize(): bool
    {
        return true; // gated by can:payables.create
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'in:cash,nlb,lovcen,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->bankRecordRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBankRecord($validator)];
    }
}
