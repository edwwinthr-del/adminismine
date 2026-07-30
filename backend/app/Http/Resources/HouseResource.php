<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'landlord_name' => $this->landlord_name,
            'landlord_phone' => $this->landlord_phone,
            'landlord_id_number' => $this->landlord_id_number,
            'landlord_bank_account' => $this->landlord_bank_account,
            'monthly_rent' => $this->monthly_rent === null ? null : (float) $this->monthly_rent,
            'deposit' => $this->deposit === null ? null : (float) $this->deposit,
            'currency' => $this->currency,
            'contract_start_date' => optional($this->contract_start_date)->toDateString(),
            'contract_end_date' => optional($this->contract_end_date)->toDateString(),
            'rent_due_day' => $this->rent_due_day,
            'is_active' => $this->is_active,
            'occupant_count' => $this->whenCounted('currentOccupancies'),
            'occupants' => $this->whenLoaded('currentOccupancies', fn () => $this->currentOccupancies
                ->map(fn ($occupancy) => [
                    'occupancy_id' => $occupancy->id,
                    'employee_id' => $occupancy->employee_id,
                    'full_name' => $occupancy->employee?->full_name,
                    'room' => $occupancy->room,
                    'moved_in_at' => optional($occupancy->moved_in_at)->toDateString(),
                ])->values()),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
