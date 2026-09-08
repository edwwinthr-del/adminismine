<?php

namespace App\Http\Resources;

use App\Models\BankTransactionLine;
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
            // One entry per account this movement touched — two of them is what
            // a transfer looks like.
            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (BankTransactionLine $line): array => [
                    'id' => $line->id,
                    'account_id' => $line->account_id,
                    'account' => $line->relationLoaded('account') && $line->account ? [
                        'id' => $line->account->id,
                        'name' => $line->account->name,
                        'kind' => $line->account->kind,
                    ] : null,
                    'amount' => (float) $line->amount,
                    'amount_eur' => (float) $line->amount_eur,
                ])
                ->values()),
            'net_amount' => $this->net_amount,
            // Every balance and dashboard figure is summed from this.
            'net_amount_eur' => $this->net_amount_eur,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            // Present only on the ledger list, which asks for it explicitly
            // (BankTransaction::scopeWithRunningBalance).
            'running_balance' => $this->when(
                $this->running_balance !== null,
                fn (): float => round((float) $this->running_balance, 2),
            ),
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
