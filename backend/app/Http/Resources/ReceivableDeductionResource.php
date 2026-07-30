<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivableDeductionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'deduction_date' => optional($this->deduction_date)->toDateString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
