<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\SocialAssistancePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAssistancePayment>
 */
class SocialAssistancePaymentFactory extends Factory
{
    protected $model = SocialAssistancePayment::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 1000);

        return [
            'employee_id' => Employee::factory(),
            'payment_date' => now()->toDateString(),
            'entitlement_year' => (int) now()->year,
            'currency' => 'EUR',
            'amount' => $amount,
            'amount_eur' => $amount,
            'method' => 'cash',
        ];
    }
}
