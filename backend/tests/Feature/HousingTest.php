<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\House;
use App\Models\HouseOccupancy;
use App\Models\HousingDeduction;
use App\Models\RentPayment;
use App\Models\User;
use App\Models\UtilityBill;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HousingTest extends TestCase
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

    public function test_house_is_created_with_landlord_and_rent_details(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/houses', [
            'name' => 'Kuca Niksic 1',
            'address' => 'Ulica 5',
            'landlord_name' => 'Petar Petrovic',
            'landlord_phone' => '+382 69 000 000',
            'landlord_bank_account' => 'ME25505000012345678951',
            'monthly_rent' => 450,
            'deposit' => 450,
            'rent_due_day' => 5,
            'contract_start_date' => '2026-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.monthly_rent', 450)
            ->assertJsonPath('data.rent_due_day', 5);
    }

    public function test_moving_a_worker_closes_the_previous_stay_and_keeps_history(): void
    {
        $this->actingAsAdmin();

        $first = House::factory()->create();
        $second = House::factory()->create();
        $worker = Employee::factory()->create();

        $this->postJson('/api/housing/occupancies', [
            'house_id' => $first->id,
            'employee_id' => $worker->id,
            'moved_in_at' => '2026-07-01',
            'room' => 'Room 1',
        ])->assertCreated()->assertJsonPath('data.is_current', true);

        // A move mid-month: the old stay is closed, not rewritten.
        $this->postJson('/api/housing/occupancies', [
            'house_id' => $second->id,
            'employee_id' => $worker->id,
            'moved_in_at' => '2026-07-15',
        ])->assertCreated();

        $stays = HouseOccupancy::query()->where('employee_id', $worker->id)->orderBy('moved_in_at')->get();

        $this->assertCount(2, $stays);
        $this->assertSame('2026-07-15', $stays[0]->moved_out_at->toDateString());
        $this->assertNull($stays[1]->moved_out_at);

        // Both houses show the worker as living there during July.
        $july = collect($this->getJson('/api/housing/occupancies?month=2026-07')->assertOk()->json('data'));
        $this->assertCount(2, $july);

        // Only the second house has a current occupant.
        $this->assertSame(0, $this->getJson("/api/houses/{$first->id}")->assertOk()->json('data.occupant_count'));
        $this->assertSame(1, $this->getJson("/api/houses/{$second->id}")->assertOk()->json('data.occupant_count'));
    }

    public function test_rent_generation_uses_active_houses_and_is_idempotent(): void
    {
        $this->actingAsAdmin();

        $house = House::factory()->create(['monthly_rent' => 500]);
        House::factory()->inactive()->create(['monthly_rent' => 400]);
        $noRent = House::factory()->create(['monthly_rent' => null]);

        $response = $this->postJson('/api/housing/rent/generate', ['month' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('meta.created_count', 1)
            ->assertJsonPath('meta.month', '2026-07-01');

        $this->assertSame($house->id, $response->json('data.0.house_id'));
        $this->assertSame('missing_monthly_rent', collect($response->json('meta.skipped'))
            ->firstWhere('house_id', $noRent->id)['reason']);

        $this->postJson('/api/housing/rent/generate', ['month' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('meta.created_count', 0)
            ->assertJsonPath('meta.already_existing_count', 1);

        $this->postJson('/api/housing/rent/generate', ['month' => '2026-08', 'preview' => true])
            ->assertOk()
            ->assertJsonPath('meta.preview', true)
            ->assertJsonPath('meta.created_count', 1);

        $this->assertSame(1, RentPayment::count());
    }

    public function test_rent_payments_update_status_and_cannot_exceed_the_amount_due(): void
    {
        $this->actingAsAdmin();
        $rent = RentPayment::factory()->create(['rent_amount_due' => 500]);
        $rent->recalculate();

        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 200, 'payment_date' => '2026-07-05', 'method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.remaining_amount', 300);

        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400, 'payment_date' => '2026-07-06', 'method' => 'nlb',
        ])->assertStatus(422);

        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 300, 'payment_date' => '2026-07-06', 'method' => 'nlb',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_rent_is_overdue_after_the_house_rent_due_day(): void
    {
        $this->actingAsAdmin();

        $house = House::factory()->create(['rent_due_day' => 5, 'monthly_rent' => 300]);
        $rent = RentPayment::factory()->create([
            'house_id' => $house->id,
            'month' => now()->subMonth()->startOfMonth()->toDateString(),
            'rent_amount_due' => 300,
        ]);
        $rent->recalculate();

        $row = collect($this->getJson('/api/housing/rent')->assertOk()->json('data'))
            ->firstWhere('id', $rent->id);

        $this->assertTrue($row['is_overdue']);
        $this->assertSame(
            now()->subMonth()->startOfMonth()->addDays(4)->toDateString(),
            $row['due_date'],
        );
    }

    public function test_one_rent_record_per_house_and_month(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();

        $payload = ['house_id' => $house->id, 'month' => '2026-07', 'rent_amount_due' => 400];

        $this->postJson('/api/housing/rent', $payload)->assertCreated();
        $this->postJson('/api/housing/rent', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_rent_and_bills_default_to_the_company_and_an_exception_needs_a_reason(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->postJson('/api/housing/rent', [
            'house_id' => $house->id, 'month' => '2026-07', 'rent_amount_due' => 400,
        ])->assertCreated()->assertJsonPath('data.cost_bearer', 'company');

        $this->postJson('/api/housing/rent', [
            'house_id' => $house->id, 'month' => '2026-08', 'rent_amount_due' => 400,
            'cost_bearer' => 'workers',
        ])->assertStatus(422)->assertJsonValidationErrors('exception_reason');

        $this->postJson('/api/housing/bills', [
            'house_id' => $house->id, 'bill_type' => 'electricity',
            'billing_period' => '2026-07', 'amount' => 80,
        ])->assertCreated()->assertJsonPath('data.cost_bearer', 'company');

        $this->postJson('/api/housing/bills', [
            'house_id' => $house->id, 'bill_type' => 'water',
            'billing_period' => '2026-07', 'amount' => 30, 'cost_bearer' => 'workers',
        ])->assertStatus(422)->assertJsonValidationErrors('exception_reason');
    }

    public function test_bill_type_must_be_canonical(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->postJson('/api/housing/bills', [
            'house_id' => $house->id,
            'bill_type' => 'struja', // translated label, not canonical
            'billing_period' => '2026-07',
            'amount' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors('bill_type');
    }

    public function test_bill_payment_sets_the_paid_date_and_status(): void
    {
        $this->actingAsAdmin();
        $bill = UtilityBill::factory()->create(['amount' => 120]);
        $bill->recalculate();

        $this->postJson("/api/housing/bills/{$bill->id}/payments", [
            'amount' => 120, 'payment_date' => '2026-07-20', 'method' => 'lovcen',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_date', '2026-07-20');
    }

    public function test_splitting_a_bill_is_an_explicit_exception_with_a_reason(): void
    {
        $this->actingAsAdmin();

        $house = House::factory()->create();
        $first = Employee::factory()->create();
        $second = Employee::factory()->create();
        HouseOccupancy::factory()->create(['house_id' => $house->id, 'employee_id' => $first->id]);
        HouseOccupancy::factory()->create(['house_id' => $house->id, 'employee_id' => $second->id]);

        $bill = UtilityBill::factory()->create([
            'house_id' => $house->id, 'amount' => 100, 'billing_period' => '2026-07-01',
        ]);
        $bill->recalculate();

        // No reason, no split.
        $this->postJson("/api/housing/bills/{$bill->id}/split")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, HousingDeduction::count());

        $response = $this->postJson("/api/housing/bills/{$bill->id}/split", [
            'reason' => 'Occupants agreed to cover July electricity',
        ])->assertCreated();

        $this->assertEqualsWithDelta(50, $response->json('meta.share'), 0.01);
        $this->assertSame(2, $response->json('meta.occupants'));

        $deductions = HousingDeduction::query()->get();
        $this->assertCount(2, $deductions);
        $this->assertEqualsWithDelta(50.0, (float) $deductions[0]->utility_share, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $deductions[0]->remaining_amount, 0.01);
        $this->assertSame('Occupants agreed to cover July electricity', $deductions[0]->reason);
        $this->assertSame('2026-07-01', $deductions[0]->month->toDateString());

        // The bill itself records that it is no longer a plain company cost.
        $bill->refresh();
        $this->assertSame('workers', $bill->cost_bearer);
        $this->assertNotNull($bill->exception_reason);
    }

    public function test_splitting_without_occupants_is_rejected(): void
    {
        $this->actingAsAdmin();
        $bill = UtilityBill::factory()->create();

        $this->postJson("/api/housing/bills/{$bill->id}/split", ['reason' => 'Nobody lives here'])
            ->assertStatus(422);

        $this->assertSame(0, HousingDeduction::count());
    }

    public function test_housing_deduction_requires_a_reason_and_derives_the_remainder(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();
        $worker = Employee::factory()->create();

        $this->postJson('/api/housing/deductions', [
            'employee_id' => $worker->id, 'house_id' => $house->id,
            'month' => '2026-07', 'rent_share' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson('/api/housing/deductions', [
            'employee_id' => $worker->id,
            'house_id' => $house->id,
            'month' => '2026-07',
            'rent_share' => 100,
            'utility_share' => 25,
            'amount_deducted' => 60,
            'reason' => 'Damage to the apartment',
        ])
            ->assertCreated()
            ->assertJsonPath('data.remaining_amount', 65)
            ->assertJsonPath('data.month', '2026-07-01');
    }

    public function test_bill_scan_can_be_attached_and_downloaded(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $bill = UtilityBill::factory()->create();

        $id = $this->postJson("/api/housing/bills/{$bill->id}/attachments", [
            'file' => UploadedFile::fake()->create('electricity.pdf', 30, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.kind', 'invoice')->json('data.id');

        $stored = $bill->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($stored->file_path);

        $this->get("/api/housing/bills/{$bill->id}/attachments/{$id}")
            ->assertOk()
            ->assertDownload('electricity.pdf');

        $this->deleteJson("/api/housing/bills/{$bill->id}")->assertOk();
        Storage::disk('local')->assertMissing($stored->file_path);
        $this->assertDatabaseCount('prilozi', 0);
    }

    public function test_summary_reports_cost_unpaid_and_overdue_per_house(): void
    {
        $this->actingAsAdmin();

        $house = House::factory()->create(['name' => 'Kuca 1', 'rent_due_day' => 5]);
        $worker = Employee::factory()->create();
        HouseOccupancy::factory()->create(['house_id' => $house->id, 'employee_id' => $worker->id]);

        $rent = RentPayment::factory()->create([
            'house_id' => $house->id, 'month' => '2026-07-01', 'rent_amount_due' => 500,
        ]);
        $rent->payments()->create([
            'amount' => 200, 'currency' => 'EUR', 'payment_date' => '2026-07-03', 'method' => 'cash',
        ]);
        $rent->recalculate();

        $bill = UtilityBill::factory()->overdue()->create([
            'house_id' => $house->id, 'billing_period' => '2026-07-01', 'amount' => 100,
        ]);
        $bill->recalculate();

        $response = $this->getJson('/api/housing/summary?month=2026-07')->assertOk();

        $this->assertSame('2026-07-01', $response->json('data.month'));
        $this->assertEqualsWithDelta(500, $response->json('data.houses.0.rent_due'), 0.01);
        $this->assertEqualsWithDelta(300, $response->json('data.houses.0.rent_unpaid'), 0.01);
        $this->assertEqualsWithDelta(100, $response->json('data.houses.0.bills_unpaid'), 0.01);
        $this->assertEqualsWithDelta(600, $response->json('data.houses.0.monthly_cost'), 0.01);
        $this->assertSame(1, $response->json('data.houses.0.occupant_count'));
        $this->assertSame(1, $response->json('data.houses.0.bills_overdue'));

        $this->assertEqualsWithDelta(600, $response->json('data.totals.monthly_cost'), 0.01);
        $this->assertEqualsWithDelta(0, $response->json('data.totals.charged_to_workers'), 0.01);
        $this->assertSame(1, $response->json('data.totals.occupants'));
        $this->assertCount(1, $response->json('data.alerts.overdue_bills'));
    }

    public function test_house_with_history_is_deactivated_not_deleted(): void
    {
        $this->actingAsAdmin();
        $rent = RentPayment::factory()->create();

        $this->deleteJson("/api/houses/{$rent->house_id}")->assertOk();

        $this->assertDatabaseHas('kuce', ['id' => $rent->house_id, 'is_active' => false]);

        $empty = House::factory()->create();
        $this->deleteJson("/api/houses/{$empty->id}")->assertOk();
        $this->assertDatabaseMissing('kuce', ['id' => $empty->id]);
    }

    public function test_housing_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/houses')->assertStatus(403);
        $this->getJson('/api/housing/summary')->assertStatus(403);
        $this->getJson('/api/housing/rent')->assertStatus(403);
    }
}
