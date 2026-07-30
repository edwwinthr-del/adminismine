<?php

namespace App\Http\Requests\Housing;

use Illuminate\Foundation\Http\FormRequest;

class GenerateRentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            // When true nothing is saved; the caller sees what would be created.
            'preview' => ['sometimes', 'boolean'],
        ];
    }
}
