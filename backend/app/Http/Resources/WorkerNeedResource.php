<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkerNeedResource extends JsonResource
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
            'worksite_id' => $this->worksite_id,
            'worksite' => $this->whenLoaded('worksite', fn () => $this->worksite === null ? null : [
                'id' => $this->worksite->id,
                'name' => $this->worksite->name,
            ]),
            'date' => optional($this->date)->toDateString(),
            'need_type' => $this->need_type,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser === null ? null : [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ]),
            'resolved_at' => $this->resolved_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
