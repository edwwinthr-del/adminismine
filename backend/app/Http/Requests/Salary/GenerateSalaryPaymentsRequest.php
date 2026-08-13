<?php

namespace App\Http\Requests\Salary;

use App\Support\MonthPeriod;
use Illuminate\Foundation\Http\FormRequest;

class GenerateSalaryPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:salary_payments.manage
    }

    public function rules(): array
    {
        return [
            // 'YYYY-MM' or a full date inside the month.
            'month' => ['required', 'string', MonthPeriod::rule()],
            // When true nothing is saved; the caller sees what would be created.
            'preview' => ['sometimes', 'boolean'],
        ];
    }
}
