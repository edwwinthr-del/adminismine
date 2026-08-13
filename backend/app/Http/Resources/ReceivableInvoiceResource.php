<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivableInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'invoice_number' => $this->invoice_number,
            'invoice_date' => optional($this->invoice_date)->toDateString(),
            'due_date' => optional($this->due_date)->toDateString(),
            'description' => $this->description,
            'currency' => $this->currency,
            'invoice_amount' => (float) $this->invoice_amount,
            // EUR by default, with the original currency alongside it (rule 5).
            'amount_eur' => (float) $this->amount_eur,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'exchange_rate_date' => optional($this->exchange_rate_date)->toDateString(),
            'received_amount' => (float) $this->received_amount,
            'deducted_amount' => (float) $this->deducted_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'notes' => $this->notes,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'deductions' => ReceivableDeductionResource::collection($this->whenLoaded('deductions')),
            'created_at' => $this->created_at,
        ];
    }
}
