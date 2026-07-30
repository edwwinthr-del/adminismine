<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'job_role' => $this->employee->job_role,
            ]),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'is_active' => $this->is_active,
            'worksites' => $this->whenLoaded('worksites', fn () => $this->worksites->map(fn ($worksite) => [
                'id' => $worksite->id,
                'name' => $worksite->name,
            ])->values()),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
