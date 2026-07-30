<?php

namespace Database\Factories;

use App\Models\House;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<House>
 */
class HouseFactory extends Factory
{
    protected $model = House::class;

    public function definition(): array
    {
        return [
            'name' => 'House '.fake()->unique()->numberBetween(1, 999),
            'address' => fake()->streetAddress(),
            'landlord_name' => fake()->name(),
            'landlord_phone' => fake()->phoneNumber(),
            'monthly_rent' => fake()->randomFloat(2, 200, 900),
            'deposit' => fake()->randomFloat(2, 200, 900),
            'currency' => 'EUR',
            'contract_start_date' => now()->subMonths(fake()->numberBetween(1, 24))->toDateString(),
            'rent_due_day' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
