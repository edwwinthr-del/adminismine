<?php

namespace App\Http\Requests\Travel;

use App\Contracts\SettlementLine;
use App\Http\Requests\Concerns\ValidatesBankRecord;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a social assistance payout. The line being corrected is the record
 * itself, which is what {@see existingLine()} tells the shared bank-record rules
 * — so a payout that already has a movement cannot book a second one.
 */
class UpdateSocialAssistanceRequest extends FormRequest
{
    use ValidatesBankRecord;

    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:radnici,id'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_date' => ['sometimes', 'date'],
            'entitlement_year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'currency' => Currencies::rules(),
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('bankovni_racuni', 'id')->where('is_active', true)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...$this->bankRecordRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBankRecord($validator)];
    }

    protected function existingLine(): ?SettlementLine
    {
        $payout = $this->route('socialAssistance');

        return $payout instanceof SettlementLine ? $payout : null;
    }
}
