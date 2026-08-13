<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayableInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'invoice_number' => $this->invoice_number,
            'invoice_date' => optional($this->invoice_date)->toDateString(),
            'due_date' => optional($this->due_date)->toDateString(),
            'description' => $this->description,
            'expense_category' => $this->expense_category,
            'currency' => $this->currency,
            'original_amount' => (float) $this->original_amount,
            // EUR by default, with the original currency alongside it (rule 5).
            'amount_eur' => (float) $this->amount_eur,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'exchange_rate_date' => optional($this->exchange_rate_date)->toDateString(),
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'notes' => $this->notes,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
