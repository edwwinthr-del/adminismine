<?php

namespace Database\Factories;

use App\Models\PayableInvoice;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayableInvoice>
 */
class PayableInvoiceFactory extends Factory
{
    protected $model = PayableInvoice::class;

    public function definition(): array
    {
        $original = fake()->randomFloat(2, 100, 10000);

        return [
            'supplier_id' => Supplier::factory(),
            'invoice_number' => 'INV-'.fake()->unique()->numberBetween(1000, 9999),
            'invoice_date' => now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'due_date' => now()->addDays(fake()->numberBetween(-10, 30))->toDateString(),
            'description' => fake()->sentence(3),
            'expense_category' => fake()->randomElement(['fuel', 'materials', 'services', 'equipment', 'other']),
            'currency' => 'EUR',
            'original_amount' => $original,
            'paid_amount' => 0,
            'remaining_amount' => $original,
            'status' => 'unpaid',
        ];
    }
}
