<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:users.manage
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // The admin types the initial password and hands it over; there is no
            // mail transport to deliver an invite link.
            'password' => ['required', 'string', Password::defaults()],
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
