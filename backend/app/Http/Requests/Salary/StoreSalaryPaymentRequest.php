<?php

namespace App\Http\Requests\Salary;

use App\Models\SalaryPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalaryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:salary_payments.manage
    }

    /** Accept 'YYYY-MM' and normalize to the first day of that month. */
    protected function prepareForValidation(): void
    {
        if ($this->filled('salary_month')) {
            $this->merge(['salary_month' => SalaryPayment::normalizeMonth($this->input('salary_month'))]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'salary_month' => [
                'required',
                'date',
                Rule::unique('salary_payments')->where(
                    fn ($query) => $query->where('employee_id', $this->input('employee_id')),
                ),
            ],
            'currency' => ['sometimes', 'string', 'size:3'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'adjustments' => ['sometimes', 'numeric'],
            'deductions' => ['sometimes', 'numeric', 'min:0'],
            'attachment_path' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'salary_month.unique' => 'This employee already has a salary record for that month.',
        ];
    }
}
