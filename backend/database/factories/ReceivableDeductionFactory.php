<?php

namespace Database\Factories;

use App\Models\ReceivableDeduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivableDeduction>
 *
 * Attach with ->for($invoice) or by setting receivable_invoice_id.
 */
class ReceivableDeductionFactory extends Factory
{
    protected $model = ReceivableDeduction::class;

    public function definition(): array
    {
        return [
            'amount' => fake()->randomFloat(2, 10, 300),
            'deduction_date' => now()->toDateString(),
            'reason' => fake()->sentence(2),
        ];
    }
}
