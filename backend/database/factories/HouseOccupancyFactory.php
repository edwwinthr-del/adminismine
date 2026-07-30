<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\House;
use App\Models\HouseOccupancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseOccupancy>
 */
class HouseOccupancyFactory extends Factory
{
    protected $model = HouseOccupancy::class;

    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'employee_id' => Employee::factory(),
            'room' => null,
            'moved_in_at' => now()->subMonths(2)->toDateString(),
            'moved_out_at' => null,
        ];
    }
}
