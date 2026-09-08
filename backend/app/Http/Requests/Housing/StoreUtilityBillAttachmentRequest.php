<?php

namespace App\Http\Requests\Housing;

use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUtilityBillAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:housing.manage
    }

    public function rules(): array
    {
        return [
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
