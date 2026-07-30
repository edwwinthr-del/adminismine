<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'job_role' => $this->employee->job_role,
                'bank_account_status' => $this->employee->bank_account_status,
            ]),
            'salary_month' => optional($this->salary_month)->toDateString(),
            'currency' => $this->currency,
            'base_salary' => (float) $this->base_salary,
            'adjustments' => (float) $this->adjustments,
            'deductions' => (float) $this->deductions,
            'net_salary_due' => (float) $this->net_salary_due,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'attachment_path' => $this->attachment_path,
            'notes' => $this->notes,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
