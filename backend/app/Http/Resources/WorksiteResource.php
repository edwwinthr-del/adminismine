<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorksiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'mine_id' => $this->mine_id,
            'mine' => $this->whenLoaded('mine', fn () => [
                'id' => $this->mine->id,
                'name' => $this->mine->name,
            ]),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'is_active' => $this->is_active,
            'employee_count' => $this->whenCounted('employees'),
            'master_count' => $this->whenCounted('masters'),
            'employees' => $this->whenLoaded('employees', fn () => $this->employees->map(fn ($employee) => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'job_role' => $employee->job_role,
                'assigned_from' => $employee->pivot->assigned_from,
                'assigned_to' => $employee->pivot->assigned_to,
            ])->values()),
            'masters' => $this->whenLoaded('masters', fn () => $this->masters->map(fn ($master) => [
                'id' => $master->id,
                'full_name' => $master->employee?->full_name,
            ])->values()),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
