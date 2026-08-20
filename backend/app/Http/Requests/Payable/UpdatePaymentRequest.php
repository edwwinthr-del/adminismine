<?php

namespace App\Http\Requests\Payable;

use App\Http\Requests\Concerns\ValidatesBankRecord;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a settlement line. Shared by payables and receivables: a payment
 * is one polymorphic row whichever invoice it settles.
 *
 * A payment recorded without a movement can be booked afterwards, and one that
 * already has a movement cannot be booked twice — both rules live in
 * {@see ValidatesBankRecord}, which reads the routed payment.
 */
class UpdatePaymentRequest extends FormRequest
{
    use ValidatesBankRecord;

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
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...$this->bankRecordRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBankRecord($validator)];
    }
}
