<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            // The language-neutral event key ("payable.created"); the frontend
            // renders it, so one row reads correctly in all three locales.
            'event' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_label' => $this->subject_type === null ? null : class_basename($this->subject_type),
            'subject_id' => $this->subject_id,
            'causer' => $this->whenLoaded('causer', fn () => [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
            ]),
            'properties' => $this->properties,
            'created_at' => $this->created_at,
        ];
    }
}
