<?php

namespace Database\Factories;

use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 500, 20000);

        return [
            'counterparty' => 'NORTH-EX',
            'direction' => 'received',
            'reference_number' => fake()->numberBetween(100, 199).'/24',
            'loan_date' => now()->subMonth()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'currency' => 'EUR',
            'original_amount' => $amount,
            'amount_eur' => $amount,
            'repaid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'outstanding',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_date' => now()->subDays(10)->toDateString()]);
    }
}
