<?php

namespace App\Http\Requests\Housing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Splitting a bill between the current occupants is an explicit exception: it is
 * never automatic, and the authorized user has to say why.
 */
class SplitBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            // Defaults to the whole bill split equally between current occupants.
            'amount' => ['nullable', 'numeric', 'min:0'],
            'employee_ids' => ['sometimes', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ];
    }
}
