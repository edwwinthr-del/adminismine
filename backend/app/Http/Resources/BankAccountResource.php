<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'currency' => $this->currency,
            'iban' => $this->iban,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            // Only asked for on the list, and computed there in one aggregate
            // rather than per row.
            'balance' => $this->when(
                $this->balance !== null,
                fn (): float => round((float) $this->balance, 2),
            ),
            // Whether anything points at this account. An account with history
            // may be closed but never deleted (rule 3).
            'has_history' => $this->when(
                $this->has_history !== null,
                fn (): bool => (bool) $this->has_history,
            ),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
