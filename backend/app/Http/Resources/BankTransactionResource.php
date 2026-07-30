<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => optional($this->date)->toDateString(),
            'description_1' => $this->description_1,
            'description_2' => $this->description_2,
            'cash_amount' => (float) $this->cash_amount,
            'nlb_amount' => (float) $this->nlb_amount,
            'lovcen_amount' => (float) $this->lovcen_amount,
            'net_amount' => $this->net_amount,
            'category' => $this->category,
            'is_uncategorized' => $this->is_uncategorized,
            'possible_duplicate' => (bool) ($this->possible_duplicate ?? false),
            'currency' => $this->currency,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ] : null),
            'matched_payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
