<?php

namespace Database\Factories;

use App\Models\ProductionRecord;
use App\Models\Worksite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRecord>
 */
class ProductionRecordFactory extends Factory
{
    protected $model = ProductionRecord::class;

    public function definition(): array
    {
        $date = now()->toDateString();

        return [
            'period_type' => 'daily',
            'date' => $date,
            'period_month' => now()->startOfMonth()->toDateString(),
            'worksite_id' => Worksite::factory(),
            'engineer_id' => null,
            'material_type' => 'bauxite_ore',
            'quantity' => fake()->randomFloat(3, 50, 900),
            'unit' => 'tons',
            'approval_status' => 'draft',
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (): array => [
            'period_type' => 'monthly',
            'date' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);
    }
}
