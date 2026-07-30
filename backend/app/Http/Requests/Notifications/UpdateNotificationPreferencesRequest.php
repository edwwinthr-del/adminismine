<?php

namespace App\Http\Requests\Notifications;

use App\Support\NotificationTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A user's own opt-outs. `is_enabled: null` hands the decision back to the rule. */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a user may always set their own preferences
    }

    public function rules(): array
    {
        return [
            'preferences' => ['present', 'array'],
            'preferences.*.type' => ['required', 'string', Rule::in(NotificationTypes::all())],
            'preferences.*.is_enabled' => ['present', 'nullable', 'boolean'],
        ];
    }
}
