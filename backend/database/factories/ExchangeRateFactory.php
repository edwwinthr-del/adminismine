<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'base_currency' => 'EUR',
            'quote_currency' => 'TRY',
            'rate' => 35.0,
            'rate_date' => now()->toDateString(),
            'provider' => 'test',
            'is_manual' => false,
            'fetched_at' => now(),
        ];
    }
}
