<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            // No rendered message: the frontend builds it from type + data so it
            // reads in the viewer's own language.
            'data' => $this->data ?? [],
            'subject_type' => $this->subject_type === null ? null : class_basename($this->subject_type),
            'subject_id' => $this->subject_id,
            'link' => $this->link_path,
            'due_date' => optional($this->due_date)->toDateString(),
            'period' => optional($this->period)->toDateString(),
            'status' => $this->status,
            'read_at' => $this->read_at,
            'dismissed_at' => $this->dismissed_at,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
