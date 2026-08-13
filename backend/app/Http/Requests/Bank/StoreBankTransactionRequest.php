<?php

namespace App\Http\Requests\Bank;

use App\Models\BankTransaction;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:bank_transactions.manage
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'description_1' => ['nullable', 'string', 'max:255'],
            'description_2' => ['nullable', 'string', 'max:255'],
            'cash_amount' => ['nullable', 'numeric'],
            'nlb_amount' => ['nullable', 'numeric'],
            'lovcen_amount' => ['nullable', 'numeric'],
            'category' => ['nullable', 'string', Rule::in(BankTransaction::CATEGORIES)],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sum = (float) $this->input('cash_amount', 0)
                + (float) $this->input('nlb_amount', 0)
                + (float) $this->input('lovcen_amount', 0);

            if ($sum === 0.0
                && (float) $this->input('cash_amount', 0) === 0.0
                && (float) $this->input('nlb_amount', 0) === 0.0
                && (float) $this->input('lovcen_amount', 0) === 0.0) {
                $validator->errors()->add('cash_amount', 'At least one account amount must be non-zero.');
            }
        });
    }
}
