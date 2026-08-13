<?php

namespace App\Http\Requests\Notifications;

use App\Models\User;
use App\Support\NotificationTypes;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Naming someone who cannot see the module is refused, not quietly dropped.
     *
     * Delivery filters recipients by the type's permission, so such a name would
     * simply never receive anything — and an administrator who set it would
     * believe that person was covered. This is the same failure as a preference
     * that saves and does nothing, so it is refused at the point it is typed.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('rules', []) as $index => $rule) {
                    $permission = NotificationTypes::permission($rule['type'] ?? '');

                    if ($permission === null) {
                        continue;
                    }

                    foreach ((array) ($rule['recipient_user_ids'] ?? []) as $position => $userId) {
                        $user = User::find($userId);

                        if ($user === null || $user->can($permission)) {
                            continue;
                        }

                        $validator->errors()->add(
                            "rules.{$index}.recipient_user_ids.{$position}",
                            "{$user->name} cannot be notified about this: it would show data that needs "
                            ."the {$permission} permission. Grant it first, or leave them off this rule.",
                        );
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'rules.*.days_before.required_if' => 'Say how many days before the due date to notify.',
        ];
    }
}
