<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_type' => $this->period_type,
            'date' => optional($this->date)->toDateString(),
            'period_month' => optional($this->period_month)->toDateString(),
            'worksite_id' => $this->worksite_id,
            'worksite' => $this->whenLoaded('worksite', fn () => [
                'id' => $this->worksite->id,
                'name' => $this->worksite->name,
            ]),
            'engineer_id' => $this->engineer_id,
            'engineer' => $this->whenLoaded('engineer', fn () => $this->engineer === null ? null : [
                'id' => $this->engineer->id,
                'full_name' => $this->engineer->full_name,
            ]),
            'material_type' => $this->material_type,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'quality_grade' => $this->quality_grade,
            'attachment_path' => $this->attachment_path,
            'approval_status' => $this->approval_status,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
