<?php

namespace App\Http\Requests\Housing;

class UpdateHouseRequest extends StoreHouseRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'string', 'max:255'],
        ]);
    }
}
