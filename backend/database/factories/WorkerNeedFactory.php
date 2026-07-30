<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\WorkerNeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerNeed>
 */
class WorkerNeedFactory extends Factory
{
    protected $model = WorkerNeed::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'worksite_id' => null,
            'date' => now()->toDateString(),
            'need_type' => fake()->randomElement(WorkerNeed::TYPES),
            'description' => fake()->sentence(),
            'priority' => 'normal',
            'status' => 'open',
        ];
    }
}
