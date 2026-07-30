<?php

namespace App\Http\Requests\Worksite;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorksiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:worksites.manage
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'mine_id' => ['nullable', 'integer', 'exists:mines,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
