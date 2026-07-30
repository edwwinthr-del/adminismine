<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\RentPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RentPayment>
 */
class RentPaymentFactory extends Factory
{
    protected $model = RentPayment::class;

    public function definition(): array
    {
        $due = fake()->randomFloat(2, 200, 900);

        return [
            'house_id' => House::factory(),
            'month' => now()->startOfMonth()->toDateString(),
            'currency' => 'EUR',
            'rent_amount_due' => $due,
            'paid_amount' => 0,
            'remaining_amount' => $due,
            'status' => 'unpaid',
            'cost_bearer' => 'company',
        ];
    }
}
