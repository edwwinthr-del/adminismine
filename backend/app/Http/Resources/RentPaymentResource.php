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
            // What it costs the books. Paid and remaining are EUR too, so a
            // lease in another currency still reads as one consistent set.
            'amount_eur' => (float) $this->amount_eur,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            // Paid and remaining are EUR, like the obligation's own amount_eur.
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            // The remainder as the operator would state it when they pay it.
            'remaining_amount_original' => $this->inOwnCurrency($this->remaining_amount),
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
