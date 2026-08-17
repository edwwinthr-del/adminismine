<?php

namespace App\Http\Requests\Loans;

use App\Models\Loan;
use App\Support\Currencies;
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
            'currency' => Currencies::rules(),
            'original_amount' => ['sometimes', 'numeric', 'gt:0'],
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:dobavljaci,id'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:klijenti,id'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:radnici,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
