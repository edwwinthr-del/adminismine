<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 *
 * Attach to a payable with ->for($invoice, 'payable').
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'method' => fake()->randomElement(['cash', 'nlb', 'lovcen']),
            'reference' => fake()->bothify('REF-####'),
        ];
    }
}
