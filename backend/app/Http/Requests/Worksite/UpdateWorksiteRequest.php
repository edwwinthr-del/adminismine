<?php

namespace App\Http\Requests\Worksite;

class UpdateWorksiteRequest extends StoreWorksiteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'string', 'max:255'],
        ]);
    }
}
