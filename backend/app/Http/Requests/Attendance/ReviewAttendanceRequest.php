<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submit / approve / reject a day. Either a whole worksite-day or an explicit
 * list of record ids may be targeted.
 */
class ReviewAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated per route (attendance.submit / attendance.approve)
    }

    public function rules(): array
    {
        return [
            'date' => ['required_without:ids', 'date'],
            'worksite_id' => ['required_with:date', 'integer', 'exists:gradilista,id'],
            'ids' => ['required_without:date', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:evidencija_prisustva,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
