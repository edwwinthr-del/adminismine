<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HousingDeductionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
            ]),
            'house_id' => $this->house_id,
            'house' => $this->whenLoaded('house', fn () => [
                'id' => $this->house->id,
                'name' => $this->house->name,
            ]),
            'month' => optional($this->month)->toDateString(),
            'currency' => $this->currency,
            'rent_share' => (float) $this->rent_share,
            'utility_share' => (float) $this->utility_share,
            'amount_deducted' => (float) $this->amount_deducted,
            'remaining_amount' => (float) $this->remaining_amount,
            'reason' => $this->reason,
            'utility_bill_id' => $this->utility_bill_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
