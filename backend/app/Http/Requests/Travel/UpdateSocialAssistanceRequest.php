<?php

namespace App\Http\Requests\Travel;

use App\Models\SocialAssistancePayment;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialAssistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_date' => ['sometimes', 'date'],
            'entitlement_year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'currency' => Currencies::rules(),
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'method' => ['sometimes', 'nullable', Rule::in(SocialAssistancePayment::METHODS)],
            'bank_transaction_id' => ['sometimes', 'nullable', 'integer', 'exists:bank_transactions,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
