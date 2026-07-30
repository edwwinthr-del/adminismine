<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MachineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'machine_type' => $this->machine_type,
            'brand' => $this->brand,
            'model' => $this->model,
            'display_name' => $this->display_name,
            'serial_number' => $this->serial_number,
            'purchase_date' => optional($this->purchase_date)->toDateString(),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'seller_name' => $this->seller_name,
            'purchased_from' => $this->purchased_from,
            'purchase_invoice_number' => $this->purchase_invoice_number,
            'purchase_amount' => $this->purchase_amount === null ? null : (float) $this->purchase_amount,
            'currency' => $this->currency,
            'payable_invoice_id' => $this->payable_invoice_id,
            'payable_invoice' => $this->whenLoaded('payableInvoice', fn () => $this->payableInvoice === null ? null : [
                'id' => $this->payableInvoice->id,
                'invoice_number' => $this->payableInvoice->invoice_number,
                'original_amount' => (float) $this->payableInvoice->original_amount,
            ]),
            'bank_transaction_id' => $this->bank_transaction_id,
            'current_location' => $this->current_location,
            'worksite_id' => $this->worksite_id,
            'worksite' => $this->whenLoaded('worksite', fn () => $this->worksite === null ? null : [
                'id' => $this->worksite->id,
                'name' => $this->worksite->name,
            ]),
            'status' => $this->status,
            'registration_expiry' => optional($this->registration_expiry)->toDateString(),
            'insurance_expiry' => optional($this->insurance_expiry)->toDateString(),
            'attachment_count' => $this->whenCounted('attachments'),
            'attachments' => FileAttachmentResource::collection($this->whenLoaded('attachments')),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
