<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'counterparty' => $this->counterparty,
            'direction' => $this->direction,
            'reference_number' => $this->reference_number,
            'loan_date' => optional($this->loan_date)->toDateString(),
            'due_date' => optional($this->due_date)->toDateString(),
            'currency' => $this->currency,
            'original_amount' => (float) $this->original_amount,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'exchange_rate_date' => optional($this->exchange_rate_date)->toDateString(),
            'amount_eur' => (float) $this->amount_eur,
            'repaid_amount' => (float) $this->repaid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
            ]),
            'repayments' => PaymentResource::collection($this->whenLoaded('repayments')),
            'repayment_count' => $this->whenCounted('repayments'),
            'notes' => $this->notes,
        ];
    }
}
