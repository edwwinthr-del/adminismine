<?php

namespace Database\Factories;

use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Machine>
 */
class MachineFactory extends Factory
{
    protected $model = Machine::class;

    public function definition(): array
    {
        return [
            'machine_type' => fake()->randomElement(['excavator', 'truck', 'loader', 'drill', 'generator']),
            'brand' => fake()->randomElement(['Komatsu', 'Caterpillar', 'Volvo', 'Liebherr']),
            'model' => fake()->bothify('??-###'),
            'serial_number' => fake()->unique()->bothify('SN########'),
            'purchase_date' => now()->subMonths(fake()->numberBetween(1, 60))->toDateString(),
            'seller_name' => fake()->company(),
            'purchase_amount' => fake()->randomFloat(2, 5000, 250000),
            'currency' => 'EUR',
            'current_location' => fake()->city(),
            'status' => 'active',
        ];
    }

    public function sold(): static
    {
        return $this->state(fn (): array => ['status' => 'sold']);
    }

    public function inMaintenance(): static
    {
        return $this->state(fn (): array => ['status' => 'maintenance']);
    }
}
