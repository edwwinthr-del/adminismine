<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'origin_country' => $this->origin_country,
            'passport_number' => $this->passport_number,
            'id_number' => $this->id_number,
            'job_role' => $this->job_role,
            'bank_account_number' => $this->bank_account_number,
            'bank_name' => $this->bank_name,
            'bank_account_status' => $this->bank_account_status,
            'base_salary' => $this->base_salary === null ? null : (float) $this->base_salary,
            'salary_currency' => $this->salary_currency,
            'salary_period' => $this->salary_period,
            'salary_calculation_rule' => $this->salary_calculation_rule,
            'daily_rate_override' => $this->daily_rate_override === null ? null : (float) $this->daily_rate_override,
            'overtime_multiplier' => $this->overtime_multiplier === null ? null : (float) $this->overtime_multiplier,
            'overtime_hourly_rate' => $this->overtime_hourly_rate === null ? null : (float) $this->overtime_hourly_rate,
            'contract_start_date' => optional($this->contract_start_date)->toDateString(),
            'contract_end_date' => optional($this->contract_end_date)->toDateString(),
            'work_permit_expiry' => optional($this->work_permit_expiry)->toDateString(),
            'residence_permit_expiry' => optional($this->residence_permit_expiry)->toDateString(),
            'medical_exam_expiry' => optional($this->medical_exam_expiry)->toDateString(),
            'safety_training_expiry' => optional($this->safety_training_expiry)->toDateString(),
            'status' => $this->status,
            'missing_documents' => $this->missing_documents,
            'document_alerts' => $this->documentAlerts(),
            'notes' => $this->notes,
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
        ];
    }
}
