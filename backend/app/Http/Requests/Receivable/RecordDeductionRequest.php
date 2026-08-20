<?php

namespace App\Http\Requests\Receivable;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class RecordDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:receivables.manage
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'deduction_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
