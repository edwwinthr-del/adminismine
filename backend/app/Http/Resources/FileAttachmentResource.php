<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attachable_id' => $this->attachable_id,
            'kind' => $this->kind,
            'label' => $this->label,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            // The file itself is served through the authenticated download route.
            'download_url' => $this->downloadPath(),
        ];
    }
}
