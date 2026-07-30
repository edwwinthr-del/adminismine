<?php

namespace App\Http\Requests\Mine;

use Illuminate\Foundation\Http\FormRequest;

class StoreMineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:worksites.manage
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:255'],
            'material_type' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
