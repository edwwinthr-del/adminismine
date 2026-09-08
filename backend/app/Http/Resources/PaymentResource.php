<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'kind' => $this->account->kind,
            ] : null),
            // Both invoice pages read this to show whether a payment reached the
            // bank ledger, and to reopen an edit form on the movement it points
            // at. Leaving it out made `payment.bank_transaction_id !== null`
            // always true in the browser: the "matched" mark never rendered, and
            // the delete dialog offered to remove a movement that may not exist.
            'bank_transaction_id' => $this->bank_transaction_id,
            // Whether this app wrote that movement (booked) or the operator
            // typed it off a statement and the payment points at it (matched).
            'bank_movement_source' => $this->whenLoaded(
                'bankTransaction',
                fn () => $this->bankTransaction?->source,
            ),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
