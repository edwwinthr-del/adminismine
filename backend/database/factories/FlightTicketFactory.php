<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\FlightTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlightTicket>
 */
class FlightTicketFactory extends Factory
{
    protected $model = FlightTicket::class;

    public function definition(): array
    {
        $rate = 35.0;
        $amount = fake()->randomFloat(2, 3000, 15000); // TRY
        $eur = round($amount / $rate, 2);

        return [
            'employee_id' => Employee::factory(),
            'ticket_date' => now()->toDateString(),
            'direction' => fake()->randomElement(FlightTicket::DIRECTIONS),
            'currency' => 'TRY',
            'amount' => $amount,
            'exchange_rate' => $rate,
            'exchange_rate_date' => now()->toDateString(),
            'amount_eur' => $eur,
            'paid_amount' => 0,
            'remaining_amount' => $eur,
            'status' => 'unpaid',
            'cost_status' => 'not_written',
        ];
    }

    public function written(): static
    {
        return $this->state(fn (): array => ['cost_status' => 'written']);
    }
}
