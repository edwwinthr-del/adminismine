<?php

namespace App\Http\Requests\Loans;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:loans.manage
    }

    public function rules(): array
    {
        return [
            'counterparty' => ['required', 'string', 'max:255'],
            'direction' => ['sometimes', Rule::in(Loan::DIRECTIONS)],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'loan_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:loan_date'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'original_amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            // Optional links to who the counterparty is in the app's own records.
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
