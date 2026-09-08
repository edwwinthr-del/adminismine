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
            // One line per account the movement touched. Two lines is what a
            // transfer looks like: out of one account and into another.
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => [
                'required',
                'integer',
                // A closed account is a statement that money no longer moves
                // through it, so a new movement may not name one. An existing
                // movement that already does stays editable (see the update
                // request) — its history is not up for revision.
                Rule::exists('bankovni_racuni', 'id')->where('is_active', true),
            ],
            'lines.*.amount' => ['required', 'numeric'],
            'category' => ['nullable', 'string', Rule::in(BankTransaction::CATEGORIES)],
            'supplier_id' => ['nullable', 'integer', 'exists:dobavljaci,id'],
            'client_id' => ['nullable', 'integer', 'exists:klijenti,id'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['lines', 'lines.*.amount'])) {
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
