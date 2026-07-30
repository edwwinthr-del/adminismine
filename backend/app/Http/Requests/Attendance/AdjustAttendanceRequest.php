<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual correction of a computed daily amount. The reason is mandatory: computed
 * values stay auditable (PROJECT_LLM_APP_PROMPT.md "Daily Earned Pay").
 */
class AdjustAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:attendance.approve
    }

    public function rules(): array
    {
        return [
            'adjustment_amount' => ['required', 'numeric'],
            'adjustment_reason' => ['required', 'string', 'max:255'],
        ];
    }
}
