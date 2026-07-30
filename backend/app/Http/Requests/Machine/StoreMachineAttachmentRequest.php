<?php

namespace App\Http\Requests\Machine;

use App\Models\FileAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMachineAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:machines.manage
    }

    public function rules(): array
    {
        return [
            // Invoices, warranties and customs papers are documents or photos;
            // executables and archives have no business here.
            'file' => [
                'required',
                'file',
                'max:10240', // 10 MB
                'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,csv,txt',
            ],
            'kind' => ['sometimes', Rule::in(FileAttachment::KINDS)],
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
