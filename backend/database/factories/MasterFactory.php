<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Master;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Master>
 */
class MasterFactory extends Factory
{
    protected $model = Master::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'user_id' => null,
            'is_active' => true,
        ];
    }
}
