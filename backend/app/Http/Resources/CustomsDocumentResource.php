<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomsDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'cmr_number' => $this->cmr_number,
            'issue_date' => optional($this->issue_date)->toDateString(),
            'cmr_date' => optional($this->cmr_date)->toDateString(),
            'shipment_date' => optional($this->shipment_date)->toDateString(),
            'customs_company_id' => $this->customs_company_id,
            'customs_company_name' => $this->customs_company_name,
            'customs_company_label' => $this->customs_company_label,
            'customs_invoice_number' => $this->customs_invoice_number,
            'sender' => $this->sender,
            'receiver' => $this->receiver,
            'carrier_name' => $this->carrier_name,
            'vehicle_plate' => $this->vehicle_plate,
            'driver_name' => $this->driver_name,
            'goods_description' => $this->goods_description,
            'quantity' => $this->quantity === null ? null : (float) $this->quantity,
            'unit' => $this->unit,
            'origin_place' => $this->origin_place,
            'destination_place' => $this->destination_place,
            'machine_id' => $this->machine_id,
            'machine' => $this->whenLoaded('machine', fn () => $this->machine === null ? null : [
                'id' => $this->machine->id,
                'display_name' => $this->machine->display_name,
                'serial_number' => $this->machine->serial_number,
            ]),
            'payable_invoice_id' => $this->payable_invoice_id,
            'payable_invoice' => $this->whenLoaded('payableInvoice', fn () => $this->payableInvoice === null ? null : [
                'id' => $this->payableInvoice->id,
                'invoice_number' => $this->payableInvoice->invoice_number,
            ]),
            'receivable_invoice_id' => $this->receivable_invoice_id,
            'client_id' => $this->client_id,
            'supplier_id' => $this->supplier_id,
            'production_record_id' => $this->production_record_id,
            'status' => $this->status,
            'has_scan' => $this->has_scan,
            'attachment_count' => $this->whenCounted('attachments'),
            'attachments' => FileAttachmentResource::collection($this->whenLoaded('attachments')),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
