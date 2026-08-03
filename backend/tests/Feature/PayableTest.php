<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every delete re-authenticates the person at the keyboard, so the request
     * body carries the factory's password (see ConfirmsPassword).
     */
    private const CONFIRM = ['current_password' => 'password'];

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

    public function test_create_payable_starts_unpaid_with_full_remaining(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create();

        $this->postJson('/api/payables', [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-1',
            'invoice_date' => '2026-07-01',
            'original_amount' => 1000,
            'expense_category' => 'fuel',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.remaining_amount', 1000);
    }

    public function test_partial_then_full_payment_updates_remaining_and_status(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 1000]);
        $invoice->recalculate();

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 400, 'payment_date' => '2026-07-05', 'method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.paid_amount', 400)
            ->assertJsonPath('data.remaining_amount', 600);

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 600, 'payment_date' => '2026-07-10', 'method' => 'nlb',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_payment_exceeding_remaining_is_rejected(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 500]);
        $invoice->recalculate();

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 600, 'payment_date' => '2026-07-05', 'method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsAdmin();

        $paid = PayableInvoice::factory()->create(['original_amount' => 100]);
        $paid->payments()->create(['amount' => 100, 'currency' => 'EUR', 'payment_date' => '2026-07-01', 'method' => 'cash']);
        $paid->recalculate();

        PayableInvoice::factory()->create(['original_amount' => 200]); // stays unpaid

        $response = $this->getJson('/api/payables?status=unpaid')->assertOk();
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();

        $this->assertSame(['unpaid'], $statuses);
    }

    public function test_overdue_filter_and_flag(): void
    {
        $this->actingAsAdmin();

        $overdue = PayableInvoice::factory()->create([
            'original_amount' => 100,
            'invoice_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $overdue->recalculate();

        $response = $this->getJson('/api/payables?overdue=1')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $overdue->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['is_overdue']);
    }

    public function test_view_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker'); // no payables permissions
        Sanctum::actingAs($user);

        $this->getJson('/api/payables')->assertStatus(403);
    }

    // ---- correcting a settled invoice ----------------------------------

    /** @return array{0: PayableInvoice, 1: int} the invoice and its payment id */
    private function paidInvoice(float $amount = 1000): array
    {
        $invoice = PayableInvoice::factory()->create(['original_amount' => $amount]);
        $invoice->recalculate();

        $paymentId = $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => $amount, 'payment_date' => '2026-07-05', 'method' => 'cash',
        ])->assertCreated()->json('data.payments.0.id');

        return [$invoice->refresh(), $paymentId];
    }

    public function test_a_paid_invoice_can_still_be_corrected(): void
    {
        $this->actingAsAdmin();
        [$invoice] = $this->paidInvoice();

        $this->assertSame('paid', $invoice->status);

        $this->putJson("/api/payables/{$invoice->id}", [
            'invoice_number' => 'CORRECTED-1',
            'expense_category' => 'parts',
            'invoice_date' => '2026-06-30',
        ])
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'CORRECTED-1')
            // Untouched by the edit, and still derived from the payments.
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_raising_the_amount_of_a_paid_invoice_reopens_it(): void
    {
        $this->actingAsAdmin();
        [$invoice] = $this->paidInvoice();

        $this->putJson("/api/payables/{$invoice->id}", ['original_amount' => 1500])
            ->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.paid_amount', 1000)
            ->assertJsonPath('data.remaining_amount', 500);
    }

    public function test_the_amount_cannot_be_cut_below_what_was_already_paid(): void
    {
        $this->actingAsAdmin();
        [$invoice] = $this->paidInvoice();

        $this->putJson("/api/payables/{$invoice->id}", ['original_amount' => 400])
            ->assertStatus(422)
            ->assertJsonValidationErrors('original_amount');

        // Refused outright: no negative balance is left behind.
        $this->assertSame('1000.00', $invoice->fresh()->original_amount);
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
    }

    public function test_a_payment_can_be_corrected_in_place(): void
    {
        $this->actingAsAdmin();
        [$invoice, $paymentId] = $this->paidInvoice();

        $this->putJson("/api/payables/{$invoice->id}/payments/{$paymentId}", ['amount' => 600])
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 600)
            ->assertJsonPath('data.remaining_amount', 400)
            ->assertJsonPath('data.status', 'partial')
            // Corrected, not offset: still one payment row.
            ->assertJsonCount(1, 'data.payments');
    }

    public function test_a_payment_correction_may_not_overshoot_the_invoice(): void
    {
        $this->actingAsAdmin();
        [$invoice, $paymentId] = $this->paidInvoice();

        $this->putJson("/api/payables/{$invoice->id}/payments/{$paymentId}", ['amount' => 1200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_duplicate_payment_can_be_removed(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 1000]);
        $invoice->recalculate();

        $first = $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 500, 'payment_date' => '2026-07-05', 'method' => 'cash',
        ])->json('data.payments.0.id');

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 500, 'payment_date' => '2026-07-05', 'method' => 'cash',
        ])->assertCreated();

        $this->deleteJson("/api/payables/{$invoice->id}/payments/{$first}", self::CONFIRM)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 500)
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonCount(1, 'data.payments');
    }

    public function test_a_payment_cannot_be_reached_through_another_invoice(): void
    {
        $this->actingAsAdmin();
        [, $paymentId] = $this->paidInvoice();
        $other = PayableInvoice::factory()->create(['original_amount' => 500]);

        $this->deleteJson("/api/payables/{$other->id}/payments/{$paymentId}", self::CONFIRM)
            ->assertNotFound();
    }

    public function test_deleting_an_invoice_needs_the_right_password(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 400]);
        $invoice->recalculate();

        $this->deleteJson("/api/payables/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->deleteJson("/api/payables/{$invoice->id}", ['current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        // Refused twice and still there — a wrong password cancels, it does not
        // half-delete.
        $this->assertDatabaseHas('payable_invoices', ['id' => $invoice->id]);

        $this->deleteJson("/api/payables/{$invoice->id}", self::CONFIRM)->assertOk();
        $this->assertDatabaseMissing('payable_invoices', ['id' => $invoice->id]);
    }

    public function test_removing_a_matched_payment_unpays_the_invoice_and_releases_the_movement(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 500]);
        $invoice->recalculate();

        $transaction = BankTransaction::factory()->create([
            'category' => 'expense', 'nlb_amount' => 500, 'cash_amount' => 0, 'lovcen_amount' => 0,
        ]);

        $this->postJson("/api/bank-transactions/{$transaction->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 500,
        ])->assertCreated();

        $this->assertSame('paid', $invoice->fresh()->status);
        $paymentId = $invoice->fresh()->payments()->first()->id;

        $this->deleteJson("/api/payables/{$invoice->id}/payments/{$paymentId}", self::CONFIRM)
            ->assertOk()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.remaining_amount', 500);

        // The movement is still there — the money left the bank whatever happens
        // to the invoice — but nothing points at it any more.
        $this->assertDatabaseHas('bank_transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
        $this->assertSame(1, BankTransaction::query()->unmatched()->count());
    }

    public function test_a_payment_can_take_its_bank_movement_with_it(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 500]);
        $invoice->recalculate();

        $transaction = BankTransaction::factory()->create([
            'category' => 'expense', 'nlb_amount' => 500, 'cash_amount' => 0, 'lovcen_amount' => 0,
        ]);

        $this->postJson("/api/bank-transactions/{$transaction->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 500,
        ])->assertCreated();

        $paymentId = $invoice->fresh()->payments()->first()->id;

        $this->deleteJson(
            "/api/payables/{$invoice->id}/payments/{$paymentId}",
            self::CONFIRM + ['delete_bank_transaction' => true],
        )->assertOk()->assertJsonPath('data.status', 'unpaid');

        $this->assertDatabaseMissing('bank_transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
    }

    public function test_a_movement_settling_another_invoice_is_never_deleted_with_a_payment(): void
    {
        $this->actingAsAdmin();

        $first = PayableInvoice::factory()->create(['original_amount' => 300]);
        $second = PayableInvoice::factory()->create(['original_amount' => 200]);
        $first->recalculate();
        $second->recalculate();

        $transaction = BankTransaction::factory()->create([
            'category' => 'expense', 'nlb_amount' => 500, 'cash_amount' => 0, 'lovcen_amount' => 0,
        ]);

        // One movement paying two invoices — the ordinary case for a single
        // bank line covering a supplier's whole month.
        foreach ([[$first, 300], [$second, 200]] as [$invoice, $amount]) {
            $this->postJson("/api/bank-transactions/{$transaction->id}/match", [
                'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => $amount,
            ])->assertCreated();
        }

        $paymentId = $first->fresh()->payments()->first()->id;

        $this->deleteJson(
            "/api/payables/{$first->id}/payments/{$paymentId}",
            self::CONFIRM + ['delete_bank_transaction' => true],
        )->assertStatus(422)->assertJsonValidationErrors('delete_bank_transaction');

        // Refused whole: the second invoice is untouched and the payment stays.
        $this->assertDatabaseHas('bank_transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('payments', ['id' => $paymentId]);
        $this->assertSame('paid', $second->fresh()->status);
    }
}
