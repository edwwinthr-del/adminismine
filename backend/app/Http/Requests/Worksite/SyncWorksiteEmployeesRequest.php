<?php

namespace App\Http\Requests\Worksite;

use Illuminate\Foundation\Http\FormRequest;

class SyncWorksiteEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:worksites.manage
    }

    public function rules(): array
    {
        return [
            'employees' => ['present', 'array'],
            'employees.*.employee_id' => ['required', 'integer', 'exists:radnici,id'],
            'employees.*.assigned_from' => ['nullable', 'date'],
            'employees.*.assigned_to' => ['nullable', 'date'],
        ];
    }
}
