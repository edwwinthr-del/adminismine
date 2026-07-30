<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:users.manage
    }

    public function rules(): array
    {
        // No current-password check: this is an administrative reset for someone
        // who has lost access, not a self-service change.
        return [
            'password' => ['required', 'string', Password::defaults()],
        ];
    }
}
