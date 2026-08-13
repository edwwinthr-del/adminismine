<?php

namespace App\Http\Requests\Travel;

use App\Models\FlightTicket;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlightTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'passenger_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ticket_date' => ['sometimes', 'date'],
            'direction' => ['sometimes', Rule::in(FlightTicket::DIRECTIONS)],
            'route' => ['sometimes', 'nullable', 'string', 'max:255'],
            'airline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => Currencies::rules(),
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'cost_status' => ['sometimes', Rule::in(FlightTicket::COST_STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
