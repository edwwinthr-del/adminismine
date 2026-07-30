<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UtilityBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'house_id' => $this->house_id,
            'house' => $this->whenLoaded('house', fn () => [
                'id' => $this->house->id,
                'name' => $this->house->name,
            ]),
            'bill_type' => $this->bill_type,
            'billing_period' => optional($this->billing_period)->toDateString(),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'due_date' => optional($this->due_date)->toDateString(),
            'paid_date' => optional($this->paid_date)->toDateString(),
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'cost_bearer' => $this->cost_bearer,
            'exception_reason' => $this->exception_reason,
            'attachment_count' => $this->whenCounted('attachments'),
            'attachments' => FileAttachmentResource::collection($this->whenLoaded('attachments')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'notes' => $this->notes,
        ];
    }
}
