<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            // Unique because the column is: two accounts may not share a name.
            'name' => fake()->unique()->company().' account',
            'kind' => 'bank',
            'currency' => 'EUR',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (): array => ['name' => 'Cash '.fake()->unique()->numberBetween(1, 99999), 'kind' => 'cash']);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * The account a test means when it does not care which one.
     *
     * A fresh database already has one — the migration opens a cash till on
     * every install — so this reuses it rather than filling the catalogue with a
     * new account per movement.
     */
    public static function default(): BankAccount
    {
        return BankAccount::query()->active()->ordered()->first()
            ?? BankAccount::factory()->cash()->create();
    }
}
