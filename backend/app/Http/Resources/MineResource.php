<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'location' => $this->location,
            'material_type' => $this->material_type,
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
