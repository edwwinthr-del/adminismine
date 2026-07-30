<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    protected $model = BankTransaction::class;

    public function definition(): array
    {
        return [
            'date' => now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'description_1' => fake()->sentence(2),
            'description_2' => null,
            'cash_amount' => 0,
            'nlb_amount' => fake()->randomFloat(2, -2000, 2000),
            'lovcen_amount' => 0,
            'category' => fake()->randomElement(['income', 'expense', 'transfer', 'payroll', 'housing', 'travel', 'other']),
            'currency' => 'EUR',
        ];
    }
}
