<?php

namespace App\Http\Requests\Housing;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class StoreHouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'landlord_name' => ['nullable', 'string', 'max:255'],
            'landlord_phone' => ['nullable', 'string', 'max:255'],
            'landlord_id_number' => ['nullable', 'string', 'max:255'],
            'landlord_bank_account' => ['nullable', 'string', 'max:255'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'currency' => Currencies::rules(),
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => $this->filled('contract_start_date')
                ? ['nullable', 'date', 'after_or_equal:contract_start_date']
                : ['nullable', 'date'],
            'rent_due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
