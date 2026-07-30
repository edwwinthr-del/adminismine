<?php

namespace App\Http\Requests\Housing;

use App\Support\MonthPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Charging housing costs to a worker is the exception, never the default, so the
 * reason is mandatory (PROJECT_LLM_APP_PROMPT.md "Worker Housing").
 */
class StoreHousingDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('month')) {
            $this->merge(['month' => MonthPeriod::normalize($this->input('month'))]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'house_id' => ['required', 'integer', 'exists:houses,id'],
            'month' => ['required', 'date'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'rent_share' => ['sometimes', 'numeric', 'min:0'],
            'utility_share' => ['sometimes', 'numeric', 'min:0'],
            'amount_deducted' => ['sometimes', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
            'utility_bill_id' => ['nullable', 'integer', 'exists:utility_bills,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
