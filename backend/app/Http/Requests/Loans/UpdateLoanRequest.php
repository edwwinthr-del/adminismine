<?php

namespace App\Http\Requests\Loans;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:loans.manage
    }

    public function rules(): array
    {
        return [
            'counterparty' => ['sometimes', 'string', 'max:255'],
            'direction' => ['sometimes', Rule::in(Loan::DIRECTIONS)],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'loan_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'original_amount' => ['sometimes', 'numeric', 'gt:0'],
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
