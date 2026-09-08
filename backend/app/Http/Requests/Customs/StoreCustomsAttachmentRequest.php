<?php

namespace App\Http\Requests\Customs;

use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomsAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:customs_documents.manage
    }

    public function rules(): array
    {
        return [
            // Scanned papers and phone photos of the CMR travelling with the truck.
            'file' => [
                'required',
                'file',
                'max:10240', // 10 MB
                'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,csv,txt',
            ],
            'kind' => ['sometimes', Rule::in(Vocabulary::values('attachment_kind'))],
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
