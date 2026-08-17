<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:users.manage
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('korisnici', 'email')->ignore($this->route('user')),
            ],
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
