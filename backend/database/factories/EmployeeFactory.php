<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'origin_country' => fake()->randomElement(['TR', 'RS', 'ME', 'BA']),
            'passport_number' => fake()->unique()->bothify('U########'),
            'id_number' => fake()->unique()->numerify('###########'),
            'job_role' => fake()->randomElement(['miner', 'driver', 'welder', 'electrician', 'helper']),
            'bank_account_number' => fake()->iban('ME'),
            'bank_name' => fake()->randomElement(['NLB', 'Lovcen']),
            'bank_account_status' => 'open',
            'base_salary' => fake()->randomFloat(2, 600, 1800),
            'salary_currency' => 'EUR',
            'salary_period' => 'monthly',
            'salary_calculation_rule' => 'working_days',
            'contract_start_date' => now()->subMonths(fake()->numberBetween(1, 24))->toDateString(),
            'contract_end_date' => now()->addMonths(fake()->numberBetween(2, 18))->toDateString(),
            'work_permit_expiry' => now()->addMonths(fake()->numberBetween(2, 12))->toDateString(),
            'residence_permit_expiry' => now()->addMonths(fake()->numberBetween(2, 12))->toDateString(),
            'medical_exam_expiry' => now()->addMonths(fake()->numberBetween(2, 12))->toDateString(),
            'safety_training_expiry' => now()->addMonths(fake()->numberBetween(2, 12))->toDateString(),
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
