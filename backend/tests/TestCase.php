<?php

namespace Tests;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The account a test names, opened if it does not exist yet.
     *
     * Accounts became rows when `cash_amount` / `nlb_amount` / `lovcen_amount`
     * stopped being columns, so a test that needs money to move through
     * something has to name an account. A fresh database already carries a cash
     * till — the migration opens one on every install — which is why this finds
     * far more often than it creates.
     */
    protected function bankAccount(string $name = 'Cash', string $kind = 'cash'): BankAccount
    {
        return BankAccount::firstOrCreate(['name' => $name], ['kind' => $kind]);
    }

    protected function cashAccount(): BankAccount
    {
        return $this->bankAccount('Cash', 'cash');
    }

    protected function nlbAccount(): BankAccount
    {
        return $this->bankAccount('NLB', 'bank');
    }

    protected function lovcenAccount(): BankAccount
    {
        return $this->bankAccount('Lovćen', 'bank');
    }

    /**
     * What a movement put through one account.
     *
     * Zero where it has no line there at all — an account a movement never
     * touched has moved nothing through it, which is the same answer the three
     * columns gave when one of them held a zero.
     */
    protected function amountOn(BankTransaction $movement, BankAccount|int $account): float
    {
        $accountId = $account instanceof BankAccount ? $account->id : $account;

        return round((float) ($movement->lines()->where('account_id', $accountId)->value('amount') ?? 0), 2);
    }

    /** The same, in the accounting currency — what every balance is summed from. */
    protected function amountEurOn(BankTransaction $movement, BankAccount|int $account): float
    {
        $accountId = $account instanceof BankAccount ? $account->id : $account;

        return round((float) ($movement->lines()->where('account_id', $accountId)->value('amount_eur') ?? 0), 2);
    }

    /** The balance of one account, from the aggregate the app itself reads. */
    protected function balanceOfAccount(BankAccount|int $account): float
    {
        $accountId = $account instanceof BankAccount ? $account->id : $account;

        return (float) collect(BankTransaction::accountBalances()['accounts'])
            ->firstWhere('id', $accountId)['balance'];
    }
}
