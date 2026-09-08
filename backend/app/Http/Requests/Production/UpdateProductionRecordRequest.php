<?php

namespace App\Http\Requests\Production;

use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The period a record belongs to is fixed once filed; the reported figures and
 * paperwork can be corrected while the record is not approved.
 */
class UpdateProductionRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:mining_production.submit + approval lock in the controller
    }

    public function rules(): array
    {
        return [
            'engineer_id' => ['nullable', 'integer', 'exists:radnici,id'],
            'material_type' => ['sometimes', Rule::in(Vocabulary::values('material_type'))],
            'quantity' => ['sometimes', 'numeric', 'min:0'],
            'unit' => ['sometimes', Rule::in(Vocabulary::values('production_unit'))],
            'quality_grade' => ['nullable', 'string', 'max:255'],
            'attachment_path' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
