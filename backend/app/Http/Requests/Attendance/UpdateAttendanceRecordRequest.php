<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:attendance.submit + approved-day lock in the controller
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(AttendanceRecord::STATUSES)],
            'regular_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'overtime_hours' => ['sometimes', 'numeric', 'min:0', 'max:24'],
            'overtime_reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
