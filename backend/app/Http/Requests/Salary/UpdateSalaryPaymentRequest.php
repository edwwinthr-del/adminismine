<?php

namespace App\Http\Requests\Salary;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The month and employee of an existing obligation are fixed; only the amounts
 * that feed the net due (and the paperwork around it) can be corrected.
 */
class UpdateSalaryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:salary_payments.manage
    }

    public function rules(): array
    {
        return [
            'currency' => Currencies::rules(),
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'adjustments' => ['sometimes', 'numeric'],
            'deductions' => ['sometimes', 'numeric', 'min:0'],
            'attachment_path' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
