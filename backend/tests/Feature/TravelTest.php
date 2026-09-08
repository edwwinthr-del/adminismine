<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\FlightTicket;
use App\Models\SocialAssistancePayment;
use App\Models\TravelExpense;
use App\Models\User;
use App\Services\SocialAssistanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TravelTest extends TestCase
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

    public function test_a_try_ticket_is_converted_with_the_stored_rate(): void
    {
        $this->actingAsAdmin();
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => '2026-07-01']);

        $worker = Employee::factory()->create();

        $this->postJson('/api/travel/tickets', [
            'employee_id' => $worker->id,
            'ticket_date' => '2026-07-10',
            'direction' => 'departure',
            'currency' => 'TRY',
            'amount' => 7000,
            'route' => 'IST-TGD',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 7000)
            ->assertJsonPath('data.currency', 'TRY')
            ->assertJsonPath('data.exchange_rate', 35)
            // The rate that was actually used is stored on the record.
            ->assertJsonPath('data.exchange_rate_date', '2026-07-01')
            ->assertJsonPath('data.amount_eur', 200)
            ->assertJsonPath('data.remaining_amount', 200)
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.cost_status', 'not_written');
    }

    public function test_a_manual_rate_beats_the_stored_daily_rate(): void
    {
        $this->actingAsAdmin();
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => '2026-07-01']);

        $this->postJson('/api/travel/tickets', [
            'passenger_name' => 'YILMAZ ODUMLU',
            'ticket_date' => '2026-07-10',
            'direction' => 'round_trip',
            'currency' => 'TRY',
            'amount' => 7000,
            'exchange_rate' => 40,
        ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', 40)
            ->assertJsonPath('data.amount_eur', 175);
    }

    public function test_the_rate_in_force_on_the_ticket_date_is_used(): void
    {
        $this->actingAsAdmin();
        ExchangeRate::factory()->create(['rate' => 30.0, 'rate_date' => '2026-06-01']);
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => '2026-07-01']);
        ExchangeRate::factory()->create(['rate' => 40.0, 'rate_date' => '2026-08-01']);

        $this->postJson('/api/travel/tickets', [
            'passenger_name' => 'TEKIN GUNDUZ',
            'ticket_date' => '2026-07-10',
            'direction' => 'departure',
            'currency' => 'TRY',
            'amount' => 7000,
        ])
            ->assertCreated()
            // Not the newest rate — the newest one on or before the ticket date.
            ->assertJsonPath('data.exchange_rate', 35)
            ->assertJsonPath('data.amount_eur', 200);
    }

    public function test_a_ticket_in_a_currency_with_no_rate_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/travel/tickets', [
            'passenger_name' => 'NEVZAT SEVIL',
            'ticket_date' => '2026-07-10',
            'direction' => 'departure',
            'currency' => 'TRY',
            'amount' => 7000,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange_rate');
    }

    public function test_eur_tickets_need_no_rate(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/travel/tickets', [
            'passenger_name' => 'UMUT PUSTI',
            'ticket_date' => '2026-07-10',
            'direction' => 'arrival',
            'currency' => 'EUR',
            'amount' => 180,
        ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', null)
            ->assertJsonPath('data.amount_eur', 180);
    }

    public function test_a_ticket_needs_a_worker_or_a_passenger_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/travel/tickets', [
            'ticket_date' => '2026-07-10',
            'direction' => 'departure',
            'currency' => 'EUR',
            'amount' => 180,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('passenger_name');
    }

    public function test_paying_a_ticket_moves_it_through_partial_to_paid(): void
    {
        $this->actingAsAdmin();

        $ticket = FlightTicket::factory()->create([
            'currency' => 'EUR',
            'amount' => 200,
            'exchange_rate' => null,
            'amount_eur' => 200,
            'remaining_amount' => 200,
        ]);

        $this->postJson("/api/travel/tickets/{$ticket->id}/payments", [
            'amount' => 120,
            'payment_date' => '2026-07-12',
            'account_id' => $this->cashAccount()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.paid_amount', 120)
            ->assertJsonPath('data.remaining_amount', 80);

        $this->postJson("/api/travel/tickets/{$ticket->id}/payments", [
            'amount' => 80,
            'payment_date' => '2026-07-20',
            'account_id' => $this->nlbAccount()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_a_payment_cannot_exceed_the_remaining_ticket_cost(): void
    {
        $this->actingAsAdmin();

        $ticket = FlightTicket::factory()->create([
            'currency' => 'EUR',
            'amount' => 200,
            'amount_eur' => 200,
            'remaining_amount' => 200,
        ]);

        $this->postJson("/api/travel/tickets/{$ticket->id}/payments", [
            'amount' => 250,
            'payment_date' => '2026-07-12',
            'account_id' => $this->cashAccount()->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_editing_the_amount_keeps_the_rate_that_was_pinned(): void
    {
        $this->actingAsAdmin();
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => '2026-07-01']);

        $ticket = FlightTicket::factory()->create([
            'ticket_date' => '2026-07-10',
            'currency' => 'TRY',
            'amount' => 7000,
            'exchange_rate' => 40,
            'amount_eur' => 175,
            'remaining_amount' => 175,
        ]);

        $this->putJson("/api/travel/tickets/{$ticket->id}", ['amount' => 8000])
            ->assertOk()
            ->assertJsonPath('data.exchange_rate', 40)
            ->assertJsonPath('data.amount_eur', 200)
            ->assertJsonPath('data.remaining_amount', 200);
    }

    public function test_clearing_the_pinned_rate_falls_back_to_the_stored_one(): void
    {
        $this->actingAsAdmin();
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => '2026-07-01']);

        $ticket = FlightTicket::factory()->create([
            'ticket_date' => '2026-07-10',
            'currency' => 'TRY',
            'amount' => 7000,
            'exchange_rate' => 40,
            'amount_eur' => 175,
            'remaining_amount' => 175,
        ]);

        $this->putJson("/api/travel/tickets/{$ticket->id}", ['exchange_rate' => null])
            ->assertOk()
            ->assertJsonPath('data.exchange_rate', 35)
            ->assertJsonPath('data.amount_eur', 200);
    }

    public function test_unwritten_ticket_costs_can_be_listed(): void
    {
        $this->actingAsAdmin();

        FlightTicket::factory()->count(2)->create(['cost_status' => 'not_written']);
        FlightTicket::factory()->written()->create();

        $this->getJson('/api/travel/tickets?unwritten=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_travel_expense_defaults_its_booking_month_to_the_expense_date(): void
    {
        $this->actingAsAdmin();
        $worker = Employee::factory()->create();

        $this->postJson('/api/travel/expenses', [
            'employee_id' => $worker->id,
            'expense_date' => '2026-07-18',
            'expense_type' => 'car',
            'amount' => 462.60,
        ])
            ->assertCreated()
            ->assertJsonPath('data.period_month', '2026-07-01')
            ->assertJsonPath('data.amount_eur', 462.6)
            ->assertJsonPath('data.expense_type', 'car');
    }

    public function test_travel_expenses_can_be_filtered_by_month_and_type(): void
    {
        $this->actingAsAdmin();

        TravelExpense::factory()->create(['period_month' => '2026-07-01', 'expense_type' => 'car']);
        TravelExpense::factory()->create(['period_month' => '2026-07-01', 'expense_type' => 'flight']);
        TravelExpense::factory()->create(['period_month' => '2026-06-01', 'expense_type' => 'car']);

        $this->getJson('/api/travel/expenses?month=2026-07')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/travel/expenses?month=2026-07&expense_type=car')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_travel_expense_can_point_back_at_the_ticket_it_came_from(): void
    {
        $this->actingAsAdmin();

        $ticket = FlightTicket::factory()->create();

        $this->postJson('/api/travel/expenses', [
            'person_name' => 'GUNGOR KAHVECI',
            'expense_date' => '2026-07-18',
            'expense_type' => 'flight',
            'flight_ticket_id' => $ticket->id,
            'amount' => 402.22,
        ])
            ->assertCreated()
            ->assertJsonPath('data.flight_ticket_id', $ticket->id);
    }

    public function test_social_assistance_summary_shows_what_is_still_payable(): void
    {
        $this->actingAsAdmin();

        $worker = Employee::factory()->create(['first_name' => 'Tekin', 'last_name' => 'Gunduz']);
        $untouched = Employee::factory()->create(['first_name' => 'Zakir', 'last_name' => 'Cecen']);

        SocialAssistancePayment::factory()->create([
            'employee_id' => $worker->id,
            'entitlement_year' => 2026,
            'amount' => 580,
            'amount_eur' => 580,
        ]);

        $response = $this->getJson('/api/travel/social-assistance/summary?year=2026')->assertOk();

        // JSON drops the zero fraction, so the whole-euro entitlement arrives as an int.
        $response->assertJsonPath('data.entitlement', (int) SocialAssistanceService::DEFAULT_ANNUAL_ENTITLEMENT);

        $rows = collect($response->json('data.rows'));

        $paidRow = $rows->firstWhere('employee_id', $worker->id);
        $this->assertSame(580, $paidRow['paid']);
        $this->assertSame(420, $paidRow['payable']);

        // A worker with no payment yet still shows up, owed the full entitlement.
        $unpaidRow = $rows->firstWhere('employee_id', $untouched->id);
        $this->assertSame(0, $unpaidRow['paid']);
        $this->assertSame(1000, $unpaidRow['payable']);

        $response->assertJsonPath('data.totals.paid', 580);
    }

    public function test_overpaid_social_assistance_never_reports_a_negative_payable(): void
    {
        $this->actingAsAdmin();

        $worker = Employee::factory()->create();
        SocialAssistancePayment::factory()->create([
            'employee_id' => $worker->id,
            'entitlement_year' => 2026,
            'amount' => 1200,
            'amount_eur' => 1200,
        ]);

        $rows = collect($this->getJson('/api/travel/social-assistance/summary?year=2026')->json('data.rows'));

        $this->assertSame(0, $rows->firstWhere('employee_id', $worker->id)['payable']);
    }

    public function test_the_travel_summary_totals_a_month(): void
    {
        $this->actingAsAdmin();

        FlightTicket::factory()->create([
            'ticket_date' => '2026-07-10',
            'currency' => 'EUR',
            'amount' => 200,
            'amount_eur' => 200,
            'remaining_amount' => 200,
            'cost_status' => 'not_written',
        ]);
        FlightTicket::factory()->written()->create([
            'ticket_date' => '2026-07-12',
            'currency' => 'EUR',
            'amount' => 150,
            'amount_eur' => 150,
            'remaining_amount' => 0,
            'paid_amount' => 150,
            'status' => 'paid',
        ]);
        TravelExpense::factory()->create([
            'period_month' => '2026-07-01',
            'amount' => 100,
            'amount_eur' => 100,
            'remaining_amount' => 100,
        ]);
        SocialAssistancePayment::factory()->create([
            'payment_date' => '2026-07-05',
            'amount' => 50,
            'amount_eur' => 50,
        ]);
        // A different month must stay out of the totals.
        FlightTicket::factory()->create(['ticket_date' => '2026-06-10', 'amount_eur' => 999]);

        $this->getJson('/api/travel/summary?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.totals.tickets_eur', 350)
            ->assertJsonPath('data.totals.tickets_unpaid_eur', 200)
            ->assertJsonPath('data.totals.expenses_eur', 100)
            ->assertJsonPath('data.totals.social_assistance_eur', 50)
            ->assertJsonPath('data.totals.travel_cost_eur', 500)
            ->assertJsonPath('data.alerts.unwritten_tickets_eur', 200)
            ->assertJsonCount(1, 'data.alerts.unwritten_tickets');
    }

    public function test_ticket_paperwork_is_stored_privately_and_served_through_the_download_route(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $ticket = FlightTicket::factory()->create();

        $attachment = $this->postJson("/api/travel/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('ticket.pdf', 100, 'application/pdf'),
            'kind' => 'invoice',
        ])
            ->assertCreated()
            ->assertJsonPath('data.download_url', fn (?string $url): bool => str_starts_with((string) $url, "/travel/tickets/{$ticket->id}/attachments/"))
            ->json('data');

        $this->get("/api/travel/tickets/{$ticket->id}/attachments/{$attachment['id']}")->assertOk();

        // A file never belongs to another ticket's URL.
        $other = FlightTicket::factory()->create();
        $this->get("/api/travel/tickets/{$other->id}/attachments/{$attachment['id']}")->assertNotFound();
    }

    public function test_deleting_a_ticket_removes_its_files_and_payments(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $ticket = FlightTicket::factory()->create([
            'currency' => 'EUR',
            'amount' => 200,
            'amount_eur' => 200,
            'remaining_amount' => 200,
        ]);

        $this->postJson("/api/travel/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('ticket.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->postJson("/api/travel/tickets/{$ticket->id}/payments", [
            'amount' => 50,
            'payment_date' => '2026-07-12',
            'account_id' => $this->cashAccount()->id,
        ])->assertCreated();

        $this->deleteJson("/api/travel/tickets/{$ticket->id}")->assertOk();

        $this->assertDatabaseMissing('avionske_karte', ['id' => $ticket->id]);
        $this->assertDatabaseMissing('prilozi', [
            'attachable_type' => FlightTicket::class,
            'attachable_id' => $ticket->id,
        ]);
        $this->assertDatabaseMissing('placanja', [
            'payable_type' => FlightTicket::class,
            'payable_id' => $ticket->id,
        ]);
    }

    public function test_travel_endpoints_require_the_travel_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/travel/tickets')->assertForbidden();
        $this->getJson('/api/travel/expenses')->assertForbidden();
        $this->getJson('/api/travel/social-assistance')->assertForbidden();
        $this->getJson('/api/travel/summary')->assertForbidden();
    }

    /**
     * Payables and receivables could always correct a settlement line; every
     * other module that takes payments shipped POST and nothing else. A rent
     * payment or travel expense typed twice could only be undone by deleting
     * the whole obligation — losing its history — or by booking a compensating
     * opposite entry, which the domain rules forbid because two rows that
     * cancel out both read as real money in every report and bank match.
     */
    public function test_a_travel_payment_can_be_corrected_and_removed(): void
    {
        $this->actingAsAdmin();

        $expense = TravelExpense::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'currency' => 'EUR',
            'amount' => 500,
            'amount_eur' => 500,
        ]);
        $expense->recalculate();

        $this->postJson("/api/travel/expenses/{$expense->id}/payments", [
            'amount' => 500,
            'payment_date' => '2026-07-10',
            'account_id' => $this->cashAccount()->id,
        ])->assertCreated();

        $this->assertSame('paid', $expense->fresh()->status);
        $payment = $expense->fresh()->payments()->sole();

        // Typed as 500 when it was really 300 — corrected in place.
        $this->putJson("/api/travel/expenses/{$expense->id}/payments/{$payment->id}", [
            'amount' => 300,
            'payment_date' => '2026-07-10',
            'account_id' => $this->cashAccount()->id,
        ])->assertOk();

        $expense->refresh();
        $this->assertSame(300.0, (float) $expense->paid_amount);
        $this->assertSame(200.0, (float) $expense->remaining_amount);
        $this->assertSame('partial', $expense->status);

        // A correction may not exceed what the expense is worth.
        $this->putJson("/api/travel/expenses/{$expense->id}/payments/{$payment->id}", [
            'amount' => 900,
            'payment_date' => '2026-07-10',
            'account_id' => $this->cashAccount()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Removing the last line reopens the expense.
        $this->deleteJson("/api/travel/expenses/{$expense->id}/payments/{$payment->id}")->assertOk();

        $expense->refresh();
        $this->assertSame(0.0, (float) $expense->paid_amount);
        $this->assertSame(500.0, (float) $expense->remaining_amount);
        $this->assertSame('unpaid', $expense->status);
        $this->assertSame(0, $expense->payments()->count());
    }

    /** A payment reached through the wrong parent is not found, not forbidden. */
    public function test_a_payment_cannot_be_corrected_through_another_expense(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $mine = TravelExpense::factory()->create([
            'employee_id' => $employee->id, 'currency' => 'EUR', 'amount' => 500, 'amount_eur' => 500,
        ]);
        $other = TravelExpense::factory()->create([
            'employee_id' => $employee->id, 'currency' => 'EUR', 'amount' => 500, 'amount_eur' => 500,
        ]);
        $mine->recalculate();
        $other->recalculate();

        $this->postJson("/api/travel/expenses/{$mine->id}/payments", [
            'amount' => 100, 'payment_date' => '2026-07-10', 'account_id' => $this->cashAccount()->id,
        ])->assertCreated();

        $payment = $mine->fresh()->payments()->sole();

        $this->deleteJson("/api/travel/expenses/{$other->id}/payments/{$payment->id}")->assertNotFound();
        $this->assertSame(1, $mine->fresh()->payments()->count());
    }
}
