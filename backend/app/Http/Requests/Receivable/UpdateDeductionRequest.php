<?php

namespace App\Http\Requests\Receivable;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:receivables.manage
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'currency' => Currencies::rules(),
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'deduction_date' => ['sometimes', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
