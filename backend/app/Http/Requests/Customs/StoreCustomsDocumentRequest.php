<?php

namespace App\Http\Requests\Customs;

use App\Models\CustomsDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomsDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:customs_documents.manage
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(CustomsDocument::TYPES)],
            'document_number' => ['nullable', 'string', 'max:255'],
            // A CMR without its number is not much use as proof of carriage.
            'cmr_number' => ['nullable', 'required_if:document_type,cmr', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'cmr_date' => ['nullable', 'date'],
            'shipment_date' => ['nullable', 'date'],
            'customs_company_id' => ['nullable', 'integer', 'exists:dobavljaci,id'],
            'customs_company_name' => ['nullable', 'string', 'max:255'],
            'customs_invoice_number' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:255'],
            'receiver' => ['nullable', 'string', 'max:255'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:255'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'goods_description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'origin_place' => ['nullable', 'string', 'max:255'],
            'destination_place' => ['nullable', 'string', 'max:255'],
            'machine_id' => ['nullable', 'integer', 'exists:masine,id'],
            'payable_invoice_id' => ['nullable', 'integer', 'exists:ulazne_fakture,id'],
            'receivable_invoice_id' => ['nullable', 'integer', 'exists:izlazne_fakture,id'],
            'client_id' => ['nullable', 'integer', 'exists:klijenti,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:dobavljaci,id'],
            'production_record_id' => ['nullable', 'integer', 'exists:evidencija_proizvodnje,id'],
            'status' => ['sometimes', Rule::in(CustomsDocument::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'cmr_number.required_if' => 'A CMR consignment note needs its CMR number.',
        ];
    }
}
