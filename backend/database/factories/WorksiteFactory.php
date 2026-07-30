<?php

namespace Database\Factories;

use App\Models\Worksite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Worksite>
 */
class WorksiteFactory extends Factory
{
    protected $model = Worksite::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Mine A', 'Mine B', 'Quarry North', 'Plant South', 'Depot'])
                .' '.fake()->unique()->numberBetween(1, 999),
            'location' => fake()->city(),
            'mine_id' => null,
            'project_id' => null,
            'client_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
