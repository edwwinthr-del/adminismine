<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'is_enabled' => $this->is_enabled,
            'timing' => $this->timing,
            'days_before' => $this->days_before,
            'severity' => $this->severity,
            'channels' => $this->channels ?? [],
            'recipient_roles' => $this->recipient_roles ?? [],
            'recipient_user_ids' => $this->recipient_user_ids ?? [],
            'config' => $this->config,
        ];
    }
}
