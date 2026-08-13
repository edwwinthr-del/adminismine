<?php

namespace App\Http\Requests\Payable;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePayableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:payables.create
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invoice_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'expense_category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'original_amount' => ['sometimes', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
