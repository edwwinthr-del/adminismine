<?php

namespace App\Http\Requests\Travel;

use App\Http\Requests\Concerns\ValidatesBankRecord;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * A social assistance payout. It is its own settlement, so the bank-record rules
 * apply to it directly ({@see ValidatesBankRecord}).
 */
class StoreSocialAssistanceRequest extends FormRequest
{
    use ValidatesBankRecord;

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
            'employee_id' => ['nullable', 'integer', 'exists:radnici,id'],
            'person_name' => ['nullable', 'required_without:employee_id', 'string', 'max:255'],
            'payment_date' => ['required', 'date'],
            'entitlement_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'currency' => Currencies::rules(),
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'account_id' => ['nullable', 'integer', Rule::exists('bankovni_racuni', 'id')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->bankRecordRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBankRecord($validator)];
    }

    public function messages(): array
    {
        return [
            'person_name.required_without' => 'Give the worker record or the name the assistance was paid to.',
        ];
    }
}
