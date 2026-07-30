<?php

namespace App\Http\Requests\Machine;

class UpdateMachineRequest extends StoreMachineRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'machine_type' => ['sometimes', 'string', 'max:255'],
        ]);
    }
}
