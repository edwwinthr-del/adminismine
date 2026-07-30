<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'start_date' => optional($this->start_date)->toDateString(),
            'end_date' => optional($this->end_date)->toDateString(),
            'is_active' => $this->is_active,
            'worksite_count' => $this->whenCounted('worksites'),
            'worksites' => $this->whenLoaded('worksites', fn () => $this->worksites->map(fn ($worksite) => [
                'id' => $worksite->id,
                'name' => $worksite->name,
            ])->values()),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
