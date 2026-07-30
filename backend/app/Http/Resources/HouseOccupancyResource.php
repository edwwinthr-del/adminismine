<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseOccupancyResource extends JsonResource
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
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
            ]),
            'room' => $this->room,
            'moved_in_at' => optional($this->moved_in_at)->toDateString(),
            'moved_out_at' => optional($this->moved_out_at)->toDateString(),
            'is_current' => $this->is_current,
            'notes' => $this->notes,
        ];
    }
}
