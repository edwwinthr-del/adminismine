<?php

namespace App\Http\Requests\Notifications;

use App\Support\NotificationTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A custom reminder raised by hand rather than found by the scanner. */
class StoreReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:notifications.configure
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date'],
            'severity' => ['sometimes', Rule::in(NotificationTypes::SEVERITIES)],
            // Who to remind. Empty means the author reminds themselves.
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'exists:korisnici,id'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:uloge,name'],
        ];
    }
}
