<?php

namespace App\Http\Requests\Attendance;

use App\Models\SalaryPayment;
use Illuminate\Foundation\Http\FormRequest;

class OverrideWorkingDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:attendance.approve
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('month')) {
            $this->merge(['month' => SalaryPayment::normalizeMonth($this->input('month'))]);
        }
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date'],
            'working_days' => ['required', 'integer', 'min:1', 'max:31'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
