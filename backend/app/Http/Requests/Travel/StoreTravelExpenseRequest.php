<?php

namespace App\Http\Requests\Travel;

use App\Models\TravelExpense;
use App\Support\Currencies;
use App\Support\MonthPeriod;
use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTravelExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    /** The booking month defaults to the month the cost was incurred. */
    protected function prepareForValidation(): void
    {
        $month = $this->input('period_month') ?? $this->input('expense_date');

        if ($month !== null) {
            $this->merge(['period_month' => MonthPeriod::normalize((string) $month)]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:radnici,id'],
            'person_name' => ['nullable', 'required_without:employee_id', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            'period_month' => ['required', 'date'],
            'expense_type' => ['required', Rule::in(Vocabulary::values('travel_expense_type'))],
            'flight_ticket_id' => ['nullable', 'integer', 'exists:avionske_karte,id'],
            'currency' => Currencies::rules(),
            'amount' => ['required', 'numeric', 'min:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'cost_status' => ['sometimes', Rule::in(TravelExpense::COST_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'person_name.required_without' => 'Give the worker record or the name the cost was written for.',
        ];
    }
}
