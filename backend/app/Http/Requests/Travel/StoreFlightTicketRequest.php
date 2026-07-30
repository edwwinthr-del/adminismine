<?php

namespace App\Http\Requests\Travel;

use App\Models\FlightTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlightTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:travel.manage
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            // Either a worker record or at least the name as it was written.
            'passenger_name' => ['nullable', 'required_without:employee_id', 'string', 'max:255'],
            'ticket_date' => ['required', 'date'],
            'direction' => ['required', Rule::in(FlightTicket::DIRECTIONS)],
            'route' => ['nullable', 'string', 'max:255'],
            'airline' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0'],
            // Optional override of the stored daily rate.
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'cost_status' => ['sometimes', Rule::in(FlightTicket::COST_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'passenger_name.required_without' => 'Give the worker record or the passenger name.',
        ];
    }
}
