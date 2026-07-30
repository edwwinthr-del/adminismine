<?php

namespace App\Http\Requests\Employee;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
        ]);
    }
}
