<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'house_id' => $this->house_id,
            'house' => $this->whenLoaded('house', fn () => [
                'id' => $this->house->id,
                'name' => $this->house->name,
                'rent_due_day' => $this->house->rent_due_day,
            ]),
            'month' => optional($this->month)->toDateString(),
            'currency' => $this->currency,
            'rent_amount_due' => (float) $this->rent_amount_due,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'due_date' => $this->due_date->toDateString(),
            'is_overdue' => $this->is_overdue,
            'cost_bearer' => $this->cost_bearer,
            'exception_reason' => $this->exception_reason,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'notes' => $this->notes,
        ];
    }
}
