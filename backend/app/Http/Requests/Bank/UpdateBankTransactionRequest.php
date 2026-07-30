<?php

namespace App\Http\Requests\Bank;

use App\Models\BankTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:bank_transactions.manage
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
            'description_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cash_amount' => ['sometimes', 'numeric'],
            'nlb_amount' => ['sometimes', 'numeric'],
            'lovcen_amount' => ['sometimes', 'numeric'],
            'category' => ['sometimes', 'nullable', 'string', Rule::in(BankTransaction::CATEGORIES)],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
