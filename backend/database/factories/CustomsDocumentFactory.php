<?php

namespace Database\Factories;

use App\Models\CustomsDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomsDocument>
 */
class CustomsDocumentFactory extends Factory
{
    protected $model = CustomsDocument::class;

    public function definition(): array
    {
        $date = now()->subDays(fake()->numberBetween(0, 90));

        return [
            'document_type' => 'cmr',
            'document_number' => fake()->unique()->bothify('DOC-####'),
            'cmr_number' => fake()->unique()->bothify('CMR-######'),
            'issue_date' => $date->toDateString(),
            'cmr_date' => $date->toDateString(),
            'shipment_date' => $date->copy()->addDay()->toDateString(),
            'customs_company_name' => fake()->company(),
            'customs_invoice_number' => fake()->bothify('CI-####'),
            'sender' => fake()->company(),
            'receiver' => 'AdminisMine DOO',
            'carrier_name' => fake()->company(),
            'vehicle_plate' => strtoupper(fake()->bothify('??-###-??')),
            'driver_name' => fake()->name(),
            'goods_description' => fake()->randomElement(['Excavator', 'Spare parts', 'Bauxite ore', 'Tyres']),
            'quantity' => fake()->randomFloat(3, 1, 500),
            'unit' => fake()->randomElement(['tons', 'pcs']),
            'origin_place' => fake()->country(),
            'destination_place' => 'Montenegro',
            'status' => 'received',
        ];
    }

    public function missing(): static
    {
        return $this->state(fn (): array => ['status' => 'missing']);
    }

    public function checked(): static
    {
        return $this->state(fn (): array => ['status' => 'checked']);
    }
}
