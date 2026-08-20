<?php

namespace App\Http\Requests\Housing;

use App\Http\Requests\Concerns\ValidatesBankRecord;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settling rent or a utility bill, booked into the bank ledger or matched to a
 * movement already typed off a statement ({@see ValidatesBankRecord}).
 */
class RecordHousingPaymentRequest extends FormRequest
{
    use ValidatesBankRecord;

    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'in:cash,nlb,lovcen,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->bankRecordRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBankRecord($validator)];
    }
}
