<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\SalaryPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryPayment>
 */
class SalaryPaymentFactory extends Factory
{
    protected $model = SalaryPayment::class;

    public function definition(): array
    {
        $base = fake()->randomFloat(2, 600, 1800);

        return [
            'employee_id' => Employee::factory(),
            'salary_month' => now()->startOfMonth()->toDateString(),
            'currency' => 'EUR',
            'base_salary' => $base,
            'adjustments' => 0,
            'deductions' => 0,
            'net_salary_due' => $base,
            'paid_amount' => 0,
            'remaining_amount' => $base,
            'status' => 'unpaid',
        ];
    }
}
