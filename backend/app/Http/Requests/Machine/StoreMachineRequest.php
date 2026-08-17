<?php

namespace App\Http\Requests\Machine;

use App\Models\Machine;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:machines.manage
    }

    public function rules(): array
    {
        return [
            'machine_type' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:dobavljaci,id'],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'purchase_invoice_number' => ['nullable', 'string', 'max:255'],
            'purchase_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => Currencies::rules(),
            'payable_invoice_id' => ['nullable', 'integer', 'exists:ulazne_fakture,id'],
            'bank_transaction_id' => ['nullable', 'integer', 'exists:bankovne_transakcije,id'],
            'current_location' => ['nullable', 'string', 'max:255'],
            'worksite_id' => ['nullable', 'integer', 'exists:gradilista,id'],
            'status' => ['sometimes', Rule::in(Machine::STATUSES)],
            // Optional; a machine without these is never reminded about.
            'registration_expiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
