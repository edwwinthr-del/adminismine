<?php

namespace App\Http\Requests\Attendance;

/** Rejecting a day always records why, so the master knows what to fix. */
class RejectAttendanceRequest extends ReviewAttendanceRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'reason' => ['required', 'string', 'max:255'],
        ]);
    }
}
