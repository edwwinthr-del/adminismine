<?php

namespace App\Http\Requests\Settings;

use App\Support\CompanyConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            /*
             * The payroll rules. Bounded rather than free: these are divisors and
             * multipliers behind every earned figure in the app, so a zero or a
             * typo'd 800 is not a setting but an outage. A standard day is at
             * least an hour and at most a full one; overtime is never worth less
             * than the hour it replaces.
             */
            'standard_day_hours' => ['sometimes', 'numeric', 'min:1', 'max:24'],
            'overtime_multiplier' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'working_day_rule' => ['sometimes', 'string', Rule::in(CompanyConfig::WORKING_DAY_RULES)],
            'social_assistance_annual' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
        ];
    }
}
