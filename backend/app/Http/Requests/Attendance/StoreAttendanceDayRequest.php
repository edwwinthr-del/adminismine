<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fast daily entry: one worksite, one date, many workers in a single request
 * (this is what "bulk mark workers as present" posts).
 */
class StoreAttendanceDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:attendance.submit + master worksite scoping
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'worksite_id' => ['required', 'integer', 'exists:gradilista,id'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.employee_id' => ['required', 'integer', 'distinct', 'exists:radnici,id'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'records.*.regular_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'records.*.overtime_hours' => ['sometimes', 'numeric', 'min:0', 'max:24'],
            'records.*.overtime_reason' => ['nullable', 'string', 'max:255'],
            'records.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'records.*.employee_id.distinct' => 'Each worker may appear only once per day.',
        ];
    }
}
