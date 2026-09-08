<?php

namespace App\Http\Requests\Housing;

use App\Models\UtilityBill;
use App\Support\Currencies;
use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUtilityBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'bill_type' => ['sometimes', Rule::in(Vocabulary::values('utility_bill_type'))],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => Currencies::rules(),
            'due_date' => ['nullable', 'date'],
            'cost_bearer' => ['sometimes', Rule::in(UtilityBill::COST_BEARERS)],
            'exception_reason' => ['nullable', 'required_if:cost_bearer,workers', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'exception_reason.required_if' => 'Charging a bill to the workers needs a reason.',
        ];
    }
}
