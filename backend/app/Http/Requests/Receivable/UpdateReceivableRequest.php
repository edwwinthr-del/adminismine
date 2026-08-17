<?php

namespace App\Http\Requests\Receivable;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReceivableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:receivables.manage
    }

    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'exists:klijenti,id'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invoice_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'invoice_amount' => ['sometimes', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
