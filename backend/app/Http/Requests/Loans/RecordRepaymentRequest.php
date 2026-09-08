<?php

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\ValidatesBankRecord;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One repayment against a loan, in EUR, booked into the bank ledger or matched
 * to a movement already typed off a statement ({@see ValidatesBankRecord}).
 */
class RecordRepaymentRequest extends FormRequest
{
    use ValidatesBankRecord;

    public function authorize(): bool
    {
        return true; // gated by can:loans.manage
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            // The account the money moved through. Null is what 'other' meant:
            // settled, but not through an account this app tracks, so there is
            // nothing to book.
            'account_id' => ['present', 'nullable', 'integer', Rule::exists('bankovni_racuni', 'id')->where('is_active', true)],
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
