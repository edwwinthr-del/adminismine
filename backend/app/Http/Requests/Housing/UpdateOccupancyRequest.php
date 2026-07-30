<?php

namespace App\Http\Requests\Housing;

use App\Models\HouseOccupancy;
use Illuminate\Foundation\Http\FormRequest;

/** The worker and house of a recorded stay are fixed; the dates and room are not. */
class UpdateOccupancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        /** @var HouseOccupancy $occupancy */
        $occupancy = $this->route('occupancy');

        return [
            'room' => ['nullable', 'string', 'max:255'],
            'moved_in_at' => ['sometimes', 'date'],
            'moved_out_at' => [
                'nullable',
                'date',
                'after_or_equal:'.($this->input('moved_in_at') ?? $occupancy->moved_in_at->toDateString()),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
