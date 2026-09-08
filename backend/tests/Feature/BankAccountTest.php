<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The accounts money moves through.
 *
 * They were three columns — `cash_amount`, `nlb_amount`, `lovcen_amount` — which
 * named two Montenegrin banks in the schema and made a fourth account a
 * migration. These tests pin the behaviour that replaces them: accounts are
 * rows, they are closed rather than deleted once they carry history, and the
 * last open one cannot be closed.
 */
class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_fresh_install_already_has_a_cash_account(): void
    {
        $this->actingAsAdmin();

        // Every install gets a till — money has to be able to move somewhere
        // before the first account is opened by hand.
        $this->getJson('/api/bank-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cash')
            ->assertJsonPath('data.0.kind', 'cash');
    }

    public function test_an_account_can_be_opened_and_carries_its_balance(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/bank-accounts', [
            'name' => 'Erste', 'kind' => 'bank', 'currency' => 'EUR', 'iban' => 'ME25505000012345678951',
        ])->assertCreated()->json('data.id');

        BankTransaction::factory()->onAccount($id, 1500)->create(['category' => 'income']);
        BankTransaction::factory()->onAccount($id, 400)->create(['category' => 'expense']);

        $row = collect($this->getJson('/api/bank-accounts')->json('data'))->firstWhere('id', $id);

        $this->assertEquals(1100, $row['balance']);
        $this->assertTrue($row['has_history']);
    }

    public function test_two_accounts_cannot_share_a_name(): void
    {
        $this->actingAsAdmin();

        // The name identifies the account on every movement line and in every
        // settlement form.
        $this->postJson('/api/bank-accounts', ['name' => 'Cash', 'kind' => 'bank'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_account_with_history_is_closed_not_deleted(): void
    {
        $this->actingAsAdmin();
        $account = $this->nlbAccount();
        BankTransaction::factory()->onAccount($account, -900)->create(['category' => 'expense']);

        $this->deleteJson("/api/bank-accounts/{$account->id}")->assertStatus(422);
        $this->assertDatabaseHas('bankovni_racuni', ['id' => $account->id]);

        $this->putJson("/api/bank-accounts/{$account->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Closed, but the movement still names it — a ledger that cannot say
        // which account a figure came from is not a ledger (rule 3).
        $this->assertEquals(-900, $this->balanceOfAccount($account));
    }

    public function test_an_account_nothing_points_at_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $typo = BankAccount::factory()->create(['name' => 'NLB (typo)']);

        $this->deleteJson("/api/bank-accounts/{$typo->id}")->assertOk();
        $this->assertDatabaseMissing('bankovni_racuni', ['id' => $typo->id]);
    }

    public function test_the_last_open_account_cannot_be_closed(): void
    {
        $this->actingAsAdmin();
        $only = BankAccount::query()->active()->ordered()->first();

        // Closing it would leave nowhere for money to move through: every
        // movement and every booked settlement names an account.
        $this->putJson("/api/bank-accounts/{$only->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    }

    public function test_the_lookup_offers_open_accounts_only(): void
    {
        $this->actingAsAdmin();
        $open = $this->nlbAccount();
        $closed = $this->bankAccount('Old bank', 'bank');
        $closed->update(['is_active' => false]);

        $names = collect($this->getJson('/api/lookups/bank-accounts')->assertOk()->json('data'))
            ->pluck('label')
            ->all();

        $this->assertContains($open->name, $names);
        $this->assertNotContains($closed->name, $names);

        // A form editing an old record still has to be able to name the account
        // that record used.
        $withClosed = collect($this->getJson('/api/lookups/bank-accounts?include_closed=1')->json('data'))
            ->pluck('label')
            ->all();

        $this->assertContains($closed->name, $withClosed);
    }

    public function test_requires_the_bank_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/bank-accounts')->assertStatus(403);
        $this->postJson('/api/bank-accounts', ['name' => 'Sneaky', 'kind' => 'bank'])->assertStatus(403);
    }
}
