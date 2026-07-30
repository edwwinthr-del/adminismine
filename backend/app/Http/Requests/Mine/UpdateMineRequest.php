<?php

namespace App\Http\Requests\Mine;

class UpdateMineRequest extends StoreMineRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'string', 'max:255'],
        ]);
    }
}
