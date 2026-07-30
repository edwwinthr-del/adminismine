<?php

namespace App\Http\Requests\Housing;

use App\Models\RentPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', 'size:3'],
            'rent_amount_due' => ['sometimes', 'numeric', 'min:0'],
            'cost_bearer' => ['sometimes', Rule::in(RentPayment::COST_BEARERS)],
            'exception_reason' => ['nullable', 'required_if:cost_bearer,workers', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'exception_reason.required_if' => 'Charging rent to the workers needs a reason.',
        ];
    }
}
