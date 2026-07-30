<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Sheet name and row number are preserved so a row can always be
            // traced back to where it came from in the workbook.
            'sheet_name' => $this->sheet_name,
            'row_number' => $this->row_number,
            'target' => $this->target,
            'raw' => $this->raw ?? [],
            'mapped' => $this->mapped ?? [],
            'issues' => $this->issues ?? [],
            'action' => $this->action,
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
