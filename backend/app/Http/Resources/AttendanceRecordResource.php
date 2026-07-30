<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => optional($this->date)->toDateString(),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'job_role' => $this->employee->job_role,
            ]),
            'worksite_id' => $this->worksite_id,
            'worksite' => $this->whenLoaded('worksite', fn () => [
                'id' => $this->worksite->id,
                'name' => $this->worksite->name,
            ]),
            'master_id' => $this->master_id,
            'status' => $this->status,
            'regular_hours' => $this->regular_hours === null ? null : (float) $this->regular_hours,
            'overtime_hours' => (float) $this->overtime_hours,
            'overtime_reason' => $this->overtime_reason,
            'note' => $this->note,
            'approval_status' => $this->approval_status,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'rejection_reason' => $this->rejection_reason,
            'currency' => $this->currency,
            'daily_rate' => $this->daily_rate === null ? null : (float) $this->daily_rate,
            'working_days_basis' => $this->working_days_basis,
            'regular_amount' => (float) $this->regular_amount,
            'overtime_amount' => (float) $this->overtime_amount,
            'adjustment_amount' => (float) $this->adjustment_amount,
            'adjustment_reason' => $this->adjustment_reason,
            'total_amount' => (float) $this->total_amount,
            'approved_for_payroll' => $this->approved_for_payroll,
            'notes' => $this->notes,
        ];
    }
}
