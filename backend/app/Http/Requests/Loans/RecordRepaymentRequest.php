<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/** One repayment against a loan, in EUR, optionally matched to a bank/cash movement. */
class RecordRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:loans.manage
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'in:cash,nlb,lovcen,other'],
            'bank_transaction_id' => ['nullable', 'integer', 'exists:bankovne_transakcije,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
