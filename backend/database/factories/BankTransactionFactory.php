<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 *
 * A movement's amounts live on its lines, so they cannot be part of
 * `definition()` — the row has to exist before a line can point at it. Every
 * movement therefore gets one line after it is created: a random amount on the
 * default account unless {@see amount()} or {@see onAccount()} says otherwise.
 */
class BankTransactionFactory extends Factory
{
    protected $model = BankTransaction::class;

    public function definition(): array
    {
        return [
            'date' => now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'description_1' => fake()->sentence(2),
            'description_2' => null,
            'category' => fake()->randomElement(['income', 'expense', 'transfer', 'payroll', 'housing', 'travel', 'other']),
            'currency' => 'EUR',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (BankTransaction $transaction): void {
            if ($transaction->lines()->exists()) {
                return;
            }

            $transaction->setLines([[
                'account_id' => BankAccountFactory::default()->id,
                'amount' => fake()->randomFloat(2, -2000, 2000),
            ]]);
        });
    }

    /** One line of this amount on the default account. */
    public function amount(float $amount): static
    {
        return $this->onAccount(BankAccountFactory::default(), $amount);
    }

    /** One line of this amount on a named account. */
    public function onAccount(BankAccount|int $account, float $amount): static
    {
        $accountId = $account instanceof BankAccount ? $account->id : $account;

        return $this->afterCreating(function (BankTransaction $transaction) use ($accountId, $amount): void {
            $transaction->setLines([['account_id' => $accountId, 'amount' => $amount]]);
        });
    }

    /**
     * Lines written verbatim, for a movement whose two sides are not a matched
     * pair — a correction, or a day's till reconciliation.
     *
     * @param  list<array{account_id: int, amount: float|int}>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(
            fn (BankTransaction $transaction) => $transaction->setLines($lines),
        );
    }

    /**
     * A movement spanning two accounts — money leaving one and arriving in the
     * other, which is the only thing a transfer ever is.
     */
    public function transfer(BankAccount|int $from, BankAccount|int $to, float $amount): static
    {
        $fromId = $from instanceof BankAccount ? $from->id : $from;
        $toId = $to instanceof BankAccount ? $to->id : $to;

        return $this->state(fn (): array => ['category' => 'transfer'])
            ->afterCreating(function (BankTransaction $transaction) use ($fromId, $toId, $amount): void {
                $transaction->setLines([
                    ['account_id' => $fromId, 'amount' => -abs($amount)],
                    ['account_id' => $toId, 'amount' => abs($amount)],
                ]);
            });
    }
}
