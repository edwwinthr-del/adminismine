<?php

namespace App\Http\Requests\Bank;

use App\Models\BankTransaction;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
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
            // Sent whole or not at all: a movement's lines are one statement
            // about where the money went, and half of that statement is not a
            // correction of it.
            'lines' => ['sometimes', 'array', 'min:1'],
            // Unlike a new movement, an existing one may keep naming a closed
            // account — correcting the date on a movement through an account
            // that has since closed must not require reopening it.
            'lines.*.account_id' => ['required', 'integer', 'exists:bankovni_racuni,id'],
            'lines.*.amount' => ['required', 'numeric'],
            'category' => ['sometimes', 'nullable', 'string', Rule::in(BankTransaction::CATEGORIES)],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:dobavljaci,id'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:klijenti,id'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('lines') || $validator->errors()->hasAny(['lines', 'lines.*.amount'])) {
                return;
            }

            $moved = collect($this->input('lines', []))
                ->contains(fn ($line): bool => round((float) ($line['amount'] ?? 0), 2) !== 0.0);

            if (! $moved) {
                $validator->errors()->add('lines', 'At least one account amount must be non-zero.');
            }
        });
    }
}
