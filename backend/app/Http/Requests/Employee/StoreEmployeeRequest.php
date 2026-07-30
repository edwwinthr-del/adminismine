<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:employees.manage
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'origin_country' => ['nullable', 'string', 'max:255'],
            'passport_number' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:255'],
            'job_role' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_status' => ['sometimes', Rule::in(Employee::BANK_ACCOUNT_STATUSES)],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'salary_currency' => ['sometimes', 'string', 'size:3'],
            'salary_period' => ['sometimes', Rule::in(Employee::SALARY_PERIODS)],
            'salary_calculation_rule' => ['sometimes', Rule::in(Employee::SALARY_RULES)],
            'daily_rate_override' => ['nullable', 'numeric', 'min:0'],
            'overtime_multiplier' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'overtime_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'contract_start_date' => ['nullable', 'date'],
            // Only compare the two when both are in the payload; a partial update may
            // send the end date alone.
            'contract_end_date' => $this->filled('contract_start_date')
                ? ['nullable', 'date', 'after_or_equal:contract_start_date']
                : ['nullable', 'date'],
            'work_permit_expiry' => ['nullable', 'date'],
            'residence_permit_expiry' => ['nullable', 'date'],
            'medical_exam_expiry' => ['nullable', 'date'],
            'safety_training_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(Employee::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
