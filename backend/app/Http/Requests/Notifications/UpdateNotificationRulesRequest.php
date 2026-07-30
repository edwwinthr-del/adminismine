<?php

namespace App\Http\Requests\Notifications;

use App\Support\NotificationTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bulk rule update — the settings screen saves every rule it shows at once. */
class UpdateNotificationRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:notifications.configure
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.type' => ['required', 'string', Rule::in(NotificationTypes::all())],
            'rules.*.is_enabled' => ['required', 'boolean'],
            'rules.*.timing' => ['required', Rule::in(NotificationTypes::TIMINGS)],
            // Only meaningful for `days_before`, and required there.
            'rules.*.days_before' => [
                'nullable',
                'integer',
                'min:0',
                'max:365',
                'required_if:rules.*.timing,days_before',
            ],
            'rules.*.severity' => ['required', Rule::in(NotificationTypes::SEVERITIES)],
            'rules.*.channels' => ['required', 'array', 'min:1'],
            'rules.*.channels.*' => [Rule::in(NotificationTypes::CHANNELS)],
            'rules.*.recipient_roles' => ['present', 'array'],
            'rules.*.recipient_roles.*' => ['string', 'exists:roles,name'],
            'rules.*.recipient_user_ids' => ['present', 'array'],
            'rules.*.recipient_user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'rules.*.days_before.required_if' => 'Say how many days before the due date to notify.',
        ];
    }
}
