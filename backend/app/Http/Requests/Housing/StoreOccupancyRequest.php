<?php

namespace App\Http\Requests\Housing;

use Illuminate\Foundation\Http\FormRequest;

class StoreOccupancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
            'house_id' => ['required', 'integer', 'exists:houses,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'room' => ['nullable', 'string', 'max:255'],
            'moved_in_at' => ['required', 'date'],
            'moved_out_at' => ['nullable', 'date', 'after_or_equal:moved_in_at'],
            // A worker moving house mid-month keeps their history: the previous
            // stay is closed on the move-in date instead of being edited away.
            'close_previous' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
