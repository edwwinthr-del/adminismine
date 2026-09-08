<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Loan;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanTest extends TestCase
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

    public function test_a_loan_starts_fully_outstanding(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/loans', [
            'counterparty' => 'NORTH-EX',
            'reference_number' => '101/24',
            'loan_date' => '2026-07-01',
            'due_date' => '2026-09-01',
            'original_amount' => 7000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'received')
            ->assertJsonPath('data.status', 'outstanding')
            ->assertJsonPath('data.amount_eur', 7000)
            ->assertJsonPath('data.repaid_amount', 0)
            ->assertJsonPath('data.remaining_amount', 7000);
    }

    public function test_a_try_loan_stores_the_rate_it_was_converted_with(): void
    {
        $this->actingAsAdmin();
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => '2026-07-01']);

        $this->postJson('/api/loans', [
            'counterparty' => 'NORTH-EX',
            'loan_date' => '2026-07-05',
            'currency' => 'TRY',
            'original_amount' => 7000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.original_amount', 7000)
            ->assertJsonPath('data.exchange_rate', 35)
            ->assertJsonPath('data.amount_eur', 200)
            ->assertJsonPath('data.remaining_amount', 200);
    }

    public function test_repayments_reduce_the_balance_and_close_the_loan(): void
    {
        $this->actingAsAdmin();

        $loan = Loan::factory()->create([
            'original_amount' => 1000,
            'amount_eur' => 1000,
            'remaining_amount' => 1000,
        ]);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount' => 400,
            'payment_date' => '2026-07-10',
            'account_id' => $this->nlbAccount()->id,
            'reference' => 'VRACENO 1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.repaid_amount', 400)
            ->assertJsonPath('data.remaining_amount', 600);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount' => 600,
            'payment_date' => '2026-08-10',
            'account_id' => $this->cashAccount()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'repaid')
            ->assertJsonPath('data.remaining_amount', 0)
            ->assertJsonCount(2, 'data.repayments');
    }

    public function test_a_repayment_cannot_exceed_the_remaining_balance(): void
    {
        $this->actingAsAdmin();

        $loan = Loan::factory()->create([
            'original_amount' => 500,
            'amount_eur' => 500,
            'remaining_amount' => 500,
        ]);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount' => 600,
            'payment_date' => '2026-07-10',
            'account_id' => $this->cashAccount()->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_the_balance_is_recomputed_when_the_loan_amount_is_corrected(): void
    {
        $this->actingAsAdmin();

        $loan = Loan::factory()->create([
            'original_amount' => 1000,
            'amount_eur' => 1000,
            'remaining_amount' => 1000,
        ]);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount' => 400,
            'payment_date' => '2026-07-10',
            'account_id' => $this->cashAccount()->id,
        ])->assertCreated();

        $this->putJson("/api/loans/{$loan->id}", ['original_amount' => 800])
            ->assertOk()
            ->assertJsonPath('data.amount_eur', 800)
            ->assertJsonPath('data.repaid_amount', 400)
            ->assertJsonPath('data.remaining_amount', 400)
            ->assertJsonPath('data.status', 'partial');
    }

    public function test_the_list_reports_the_outstanding_total(): void
    {
        $this->actingAsAdmin();

        Loan::factory()->create(['original_amount' => 1000, 'amount_eur' => 1000, 'remaining_amount' => 1000]);
        Loan::factory()->create([
            'original_amount' => 500,
            'amount_eur' => 500,
            'repaid_amount' => 500,
            'remaining_amount' => 0,
            'status' => 'repaid',
        ]);

        $this->getJson('/api/loans')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total_eur', 1500)
            ->assertJsonPath('meta.repaid_eur', 500)
            ->assertJsonPath('meta.remaining_eur', 1000);

        $this->getJson('/api/loans?outstanding=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_overdue_loans_can_be_listed(): void
    {
        $this->actingAsAdmin();

        Loan::factory()->overdue()->create(['remaining_amount' => 1000]);
        Loan::factory()->create(['remaining_amount' => 1000]);

        $this->getJson('/api/loans?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_a_loan_can_be_tied_to_a_supplier(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create(['name' => 'North-Ex DOO']);

        $this->postJson('/api/loans', [
            'counterparty' => 'NORTH-EX',
            'loan_date' => '2026-07-01',
            'original_amount' => 1000,
            'supplier_id' => $supplier->id,
            'direction' => 'given',
        ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'given')
            ->assertJsonPath('data.supplier.name', 'North-Ex DOO');
    }

    public function test_deleting_a_loan_removes_its_repayments(): void
    {
        $this->actingAsAdmin();

        $loan = Loan::factory()->create([
            'original_amount' => 1000,
            'amount_eur' => 1000,
            'remaining_amount' => 1000,
        ]);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount' => 100,
            'payment_date' => '2026-07-10',
            'account_id' => $this->cashAccount()->id,
        ])->assertCreated();

        $this->deleteJson("/api/loans/{$loan->id}")->assertOk();

        $this->assertDatabaseMissing('pozajmice', ['id' => $loan->id]);
        $this->assertDatabaseMissing('placanja', [
            'payable_type' => Loan::class,
            'payable_id' => $loan->id,
        ]);
    }

    public function test_loan_endpoints_require_the_loans_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/loans')->assertForbidden();
        $this->postJson('/api/loans', [])->assertForbidden();
    }
}
