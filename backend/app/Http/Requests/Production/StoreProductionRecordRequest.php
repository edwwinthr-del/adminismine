<?php

namespace App\Http\Requests\Production;

use App\Models\ProductionRecord;
use App\Support\MonthPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:mining_production.submit
    }

    /**
     * A daily entry derives its month from the date; a monthly entry accepts
     * 'YYYY-MM' (or a date inside the month) and keeps no day.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('period_type', 'daily');

        if ($type === 'daily' && $this->filled('date')) {
            $this->merge(['period_month' => MonthPeriod::normalize($this->input('date'))]);
        }

        if ($type === 'monthly') {
            $month = $this->input('period_month') ?? $this->input('month');

            $this->merge([
                'date' => null,
                'period_month' => $month === null ? null : MonthPeriod::normalize($month),
            ]);
        }
    }

    public function rules(): array
    {
        $monthly = $this->input('period_type') === 'monthly';

        // One row per site + material + day, and one per site + material + month:
        // duplicates would silently double the totals.
        $uniquePerPeriod = fn (string $periodType) => Rule::unique('production_records')
            ->where(fn ($query) => $query
                ->where('period_type', $periodType)
                ->where('worksite_id', $this->input('worksite_id'))
                ->where('material_type', $this->input('material_type', 'bauxite_ore')));

        return [
            'period_type' => ['required', Rule::in(ProductionRecord::PERIOD_TYPES)],
            'date' => array_values(array_filter([
                'nullable',
                'required_if:period_type,daily',
                'date',
                $monthly ? null : $uniquePerPeriod('daily'),
            ])),
            'period_month' => array_values(array_filter([
                'required',
                'date',
                $monthly ? $uniquePerPeriod('monthly') : null,
            ])),
            'worksite_id' => ['required', 'integer', 'exists:worksites,id'],
            'engineer_id' => ['nullable', 'integer', 'exists:employees,id'],
            'material_type' => ['sometimes', Rule::in(ProductionRecord::MATERIAL_TYPES)],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['sometimes', Rule::in(ProductionRecord::UNITS)],
            'quality_grade' => ['nullable', 'string', 'max:255'],
            'attachment_path' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.unique' => 'This worksite already has a record for that material on that day.',
            'period_month.unique' => 'This worksite already has a monthly record for that material.',
        ];
    }
}
