<?php

namespace App\Http\Requests\Bank;

use Illuminate\Foundation\Http\FormRequest;

class MatchTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:bank_transactions.manage
    }

    public function rules(): array
    {
        return [
            'target' => ['required', 'in:payable,receivable'],
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
