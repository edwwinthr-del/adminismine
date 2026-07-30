<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:company.settings.manage
    }

    public function rules(): array
    {
        return [
            'company_name' => ['sometimes', 'string', 'max:255'],
            'base_currency' => ['sometimes', 'string', 'size:3'],
            'default_locale' => ['sometimes', 'string', 'in:en,sr,tr'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
