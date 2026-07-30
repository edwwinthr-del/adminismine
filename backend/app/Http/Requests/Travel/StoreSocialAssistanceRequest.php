<?php

namespace App\Http\Requests\Travel;

use App\Models\SocialAssistancePayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreSocialAssistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    /** The entitlement year defaults to the year the payment was made in. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('entitlement_year') && $this->filled('payment_date')) {
            $this->merge(['entitlement_year' => Carbon::parse($this->input('payment_date'))->year]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'person_name' => ['nullable', 'required_without:employee_id', 'string', 'max:255'],
            'payment_date' => ['required', 'date'],
            'entitlement_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', Rule::in(SocialAssistancePayment::METHODS)],
            'bank_transaction_id' => ['nullable', 'integer', 'exists:bank_transactions,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'person_name.required_without' => 'Give the worker record or the name the assistance was paid to.',
        ];
    }
}
