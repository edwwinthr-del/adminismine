<?php

namespace App\Http\Requests\Import;

use App\Support\Import\ImportCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:imports.manage
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv'],
            // Omitted means "the whole workbook", which is how the original
            // GLOBAL MINE file is imported. Named means the file is a filled-in
            // template for that entity.
            'entity' => ['nullable', 'string', Rule::in(ImportCatalogue::keys())],
        ];
    }
}
