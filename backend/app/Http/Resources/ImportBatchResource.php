<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'entity' => $this->entity,
            'status' => $this->status,
            'sheet_summary' => $this->sheet_summary ?? [],
            'totals' => $this->totals ?? [],
            'error' => $this->error,
            'row_count' => $this->whenCounted('rows'),
            'imported_at' => $this->imported_at,
            'created_at' => $this->created_at,
        ];
    }
}
