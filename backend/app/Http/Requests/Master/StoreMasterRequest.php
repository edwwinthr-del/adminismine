<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:masters.manage
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:radnici,id', Rule::unique('majstori', 'employee_id')],
            'user_id' => ['nullable', 'integer', 'exists:korisnici,id', Rule::unique('majstori', 'user_id')],
            'is_active' => ['sometimes', 'boolean'],
            'worksite_ids' => ['sometimes', 'array'],
            'worksite_ids.*' => ['integer', 'exists:gradilista,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.unique' => 'This employee is already registered as a master.',
            'user_id.unique' => 'This login is already linked to another master.',
        ];
    }
}
