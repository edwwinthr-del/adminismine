<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:exchange_rates.manage
    }

    public function rules(): array
    {
        return [
            'base_currency' => ['sometimes', 'string', 'size:3'],
            'quote_currency' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['sometimes', 'date'],
            'override_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
