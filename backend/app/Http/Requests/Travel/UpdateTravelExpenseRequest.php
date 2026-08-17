<?php

namespace App\Http\Requests\Travel;

use App\Models\TravelExpense;
use App\Support\Currencies;
use App\Support\MonthPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTravelExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('period_month')) {
            $this->merge(['period_month' => MonthPeriod::normalize((string) $this->input('period_month'))]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:radnici,id'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expense_date' => ['sometimes', 'date'],
            'period_month' => ['sometimes', 'date'],
            'expense_type' => ['sometimes', Rule::in(TravelExpense::TYPES)],
            'flight_ticket_id' => ['sometimes', 'nullable', 'integer', 'exists:avionske_karte,id'],
            'currency' => Currencies::rules(),
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'cost_status' => ['sometimes', Rule::in(TravelExpense::COST_STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
