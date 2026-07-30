<?php

namespace App\Http\Requests\Import;

use App\Models\ImportRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which previewed rows the user wants written, before approving the batch. */
class UpdateImportRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:imports.manage
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['required', 'integer', 'exists:import_rows,id'],
            'rows.*.action' => ['required', Rule::in(ImportRow::ACTIONS)],
        ];
    }
}
