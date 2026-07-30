<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => 'Project '.fake()->unique()->numberBetween(1, 9999),
            'code' => strtoupper(fake()->unique()->bothify('PR-###')),
            'client_id' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
