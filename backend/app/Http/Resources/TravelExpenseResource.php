<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelExpenseResource extends JsonResource
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
            'traveller_name' => $this->traveller_name,
            'expense_date' => optional($this->expense_date)->toDateString(),
            'period_month' => optional($this->period_month)->toDateString(),
            'expense_type' => $this->expense_type,
            'flight_ticket_id' => $this->flight_ticket_id,
            'currency' => $this->currency,
            'amount' => (float) $this->amount,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'exchange_rate_date' => optional($this->exchange_rate_date)->toDateString(),
            'amount_eur' => (float) $this->amount_eur,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'cost_status' => $this->cost_status,
            'attachment_count' => $this->whenCounted('attachments'),
            'attachments' => FileAttachmentResource::collection($this->whenLoaded('attachments')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'notes' => $this->notes,
        ];
    }
}
