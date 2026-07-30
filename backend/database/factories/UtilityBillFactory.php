<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\UtilityBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UtilityBill>
 */
class UtilityBillFactory extends Factory
{
    protected $model = UtilityBill::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 20, 300);

        return [
            'house_id' => House::factory(),
            'bill_type' => fake()->randomElement(UtilityBill::TYPES),
            'billing_period' => now()->startOfMonth()->toDateString(),
            'amount' => $amount,
            'currency' => 'EUR',
            'due_date' => now()->addDays(10)->toDateString(),
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'unpaid',
            'cost_bearer' => 'company',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_date' => now()->subDays(5)->toDateString()]);
    }
}
