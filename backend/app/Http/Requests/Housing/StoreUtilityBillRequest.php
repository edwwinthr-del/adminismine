<?php

namespace App\Http\Requests\Housing;

use App\Models\UtilityBill;
use App\Support\MonthPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUtilityBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('billing_period')) {
            $this->merge(['billing_period' => MonthPeriod::normalize($this->input('billing_period'))]);
        }
    }

    public function rules(): array
    {
        return [
            'house_id' => ['required', 'integer', 'exists:houses,id'],
            'bill_type' => ['required', Rule::in(UtilityBill::TYPES)],
            'billing_period' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'due_date' => ['nullable', 'date'],
            'cost_bearer' => ['sometimes', Rule::in(UtilityBill::COST_BEARERS)],
            'exception_reason' => ['nullable', 'required_if:cost_bearer,workers', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'exception_reason.required_if' => 'Charging a bill to anyone but the company needs a reason.',
        ];
    }
}
