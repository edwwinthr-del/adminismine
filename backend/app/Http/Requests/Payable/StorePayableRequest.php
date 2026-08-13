<?php

namespace App\Http\Requests\Payable;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class StorePayableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:payables.create
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'expense_category' => ['nullable', 'string', 'max:255'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'original_amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
