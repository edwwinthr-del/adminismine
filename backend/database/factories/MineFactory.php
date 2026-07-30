<?php

namespace Database\Factories;

use App\Models\Mine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mine>
 */
class MineFactory extends Factory
{
    protected $model = Mine::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Zagrad', 'Biocki Stan', 'Vrsuta', 'Stitovo', 'Djalovica'])
                .' '.fake()->unique()->numberBetween(1, 999),
            'code' => strtoupper(fake()->unique()->bothify('MN-###')),
            'location' => fake()->city(),
            'material_type' => 'bauxite_ore',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
