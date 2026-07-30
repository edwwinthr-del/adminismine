<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ReceivableInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivableInvoice>
 */
class ReceivableInvoiceFactory extends Factory
{
    protected $model = ReceivableInvoice::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 200, 20000);

        return [
            'client_id' => Client::factory(),
            'invoice_number' => 'R-'.fake()->unique()->numberBetween(1000, 9999),
            'invoice_date' => now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'due_date' => now()->addDays(fake()->numberBetween(-10, 30))->toDateString(),
            'description' => fake()->sentence(3),
            'currency' => 'EUR',
            'invoice_amount' => $amount,
            'received_amount' => 0,
            'deducted_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'unpaid',
        ];
    }
}
