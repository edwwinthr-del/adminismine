<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialAssistancePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
            ]),
            'person_name' => $this->person_name,
            'recipient_name' => $this->recipient_name,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'entitlement_year' => $this->entitlement_year,
            'currency' => $this->currency,
            'amount' => (float) $this->amount,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'exchange_rate_date' => optional($this->exchange_rate_date)->toDateString(),
            'amount_eur' => (float) $this->amount_eur,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'kind' => $this->account->kind,
            ] : null),
            'bank_transaction_id' => $this->bank_transaction_id,
            'reason' => $this->reason,
            'notes' => $this->notes,
        ];
    }
}
