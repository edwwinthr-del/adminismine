<?php

namespace App\Http\Requests\Bank;

use App\Models\BankAccount;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:bank_transactions.manage
    }

    public function rules(): array
    {
        return [
            // The name is how the account is identified everywhere — on a
            // movement line, in a settlement form, on the balances panel — so
            // two accounts may not share one.
            'name' => ['required', 'string', 'max:255', Rule::unique('bankovni_racuni', 'name')],
            'kind' => ['required', 'string', Rule::in(BankAccount::KINDS)],
            'currency' => Currencies::rules(),
            'iban' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
