<?php

namespace App\Http\Requests\Housing;

use App\Models\RentPayment;
use App\Support\Currencies;
use App\Support\MonthPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('month')) {
            $this->merge(['month' => MonthPeriod::normalize($this->input('month'))]);
        }
    }

    public function rules(): array
    {
        return [
            'house_id' => ['required', 'integer', 'exists:houses,id'],
            'month' => [
                'required',
                'date',
                Rule::unique('rent_payments')->where(fn ($query) => $query
                    ->where('house_id', $this->input('house_id'))),
            ],
            'currency' => Currencies::rules(),
            'rent_amount_due' => ['required', 'numeric', 'min:0'],
            // Rent is a company expense unless someone says otherwise, with a reason.
            'cost_bearer' => ['sometimes', Rule::in(RentPayment::COST_BEARERS)],
            'exception_reason' => ['nullable', 'required_if:cost_bearer,workers', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.unique' => 'This house already has a rent record for that month.',
            'exception_reason.required_if' => 'Charging rent to anyone but the company needs a reason.',
        ];
    }
}
