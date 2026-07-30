<?php

namespace App\Http\Requests\Payable;

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
            'payment_date' => ['sometimes', 'date'],
            'method' => ['sometimes', 'string', 'in:cash,nlb,lovcen,other'],
            // Explicitly nullable: clearing it is how a wrong bank match is undone.
            'bank_transaction_id' => ['sometimes', 'nullable', 'integer', 'exists:bank_transactions,id'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
