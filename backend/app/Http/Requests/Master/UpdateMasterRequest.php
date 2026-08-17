<?php

namespace App\Http\Requests\Master;

use App\Models\Master;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:masters.manage
    }

    public function rules(): array
    {
        /** @var Master $master */
        $master = $this->route('master');

        return [
            'user_id' => [
                'nullable',
                'integer',
                'exists:korisnici,id',
                Rule::unique('majstori', 'user_id')->ignore($master->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'worksite_ids' => ['sometimes', 'array'],
            'worksite_ids.*' => ['integer', 'exists:gradilista,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
