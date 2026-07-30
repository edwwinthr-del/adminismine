<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\TravelExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TravelExpense>
 */
class TravelExpenseFactory extends Factory
{
    protected $model = TravelExpense::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 50, 600);

        return [
            'employee_id' => Employee::factory(),
            'expense_date' => now()->toDateString(),
            'period_month' => now()->startOfMonth()->toDateString(),
            'expense_type' => fake()->randomElement(['car', 'flight', 'bus']),
            'currency' => 'EUR',
            'amount' => $amount,
            'amount_eur' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'unpaid',
            'cost_status' => 'not_written',
        ];
    }
}
