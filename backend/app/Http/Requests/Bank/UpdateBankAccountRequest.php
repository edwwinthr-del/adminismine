<?php

namespace App\Http\Requests\Bank;

use App\Models\BankAccount;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:bank_transactions.manage
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('bankovni_racuni', 'name')->ignore($this->route('bankAccount')?->id),
            ],
            'kind' => ['sometimes', 'string', Rule::in(BankAccount::KINDS)],
            'currency' => Currencies::rules(),
            'iban' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->missing('is_active') || $this->boolean('is_active')) {
                return;
            }

            $account = $this->route('bankAccount');

            // Closing the last open account would leave nowhere for money to
            // move through: every new movement and every booked settlement
            // names an account, so the app would refuse both. Refused rather
            // than warned about, like the last active Super Admin.
            $othersOpen = BankAccount::query()
                ->active()
                ->when($account, fn ($query) => $query->whereKeyNot($account->id))
                ->exists();

            if (! $othersOpen) {
                $validator->errors()->add(
                    'is_active',
                    'This is the only open account — money has to be able to move somewhere.',
                );
            }
        });
    }
}
