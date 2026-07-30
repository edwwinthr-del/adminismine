<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyAndExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_authenticated_user_can_read_company_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/company-settings')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'AdminisMine DOO')
            ->assertJsonPath('data.base_currency', 'EUR');
    }

    public function test_updating_company_settings_requires_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        Sanctum::actingAs($viewer);

        $this->putJson('/api/company-settings', ['company_name' => 'Hacked'])->assertStatus(403);
    }

    public function test_admin_can_update_company_name(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/company-settings', ['company_name' => 'AdminisMine 2'])
            ->assertOk()
            ->assertJsonPath('data.company_name', 'AdminisMine 2');
    }

    public function test_sync_pulls_and_stores_a_rate(): void
    {
        Http::fake([
            'api.frankfurter.app/*' => Http::response([
                'amount' => 1, 'base' => 'EUR', 'date' => '2026-07-24',
                'rates' => ['TRY' => 40.5],
            ]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/exchange-rates/sync', ['quotes' => ['TRY']])
            ->assertCreated()
            ->assertJsonPath('data.0.quote_currency', 'TRY');

        $rate = ExchangeRate::latestFor('TRY');
        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(40.5, (float) $rate->rate, 0.0001);
        $this->assertFalse($rate->is_manual);
    }

    public function test_manual_override_requires_a_reason(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/exchange-rates/manual', ['quote_currency' => 'TRY', 'rate' => 42])
            ->assertStatus(422);
    }

    public function test_manual_override_is_stored_and_wins_over_a_later_sync(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/exchange-rates/manual', [
            'quote_currency' => 'TRY', 'rate' => 42, 'override_reason' => 'Central bank fixing',
        ])->assertCreated()->assertJsonPath('data.is_manual', true);

        Http::fake([
            'api.frankfurter.app/*' => Http::response([
                'base' => 'EUR', 'date' => now()->toDateString(), 'rates' => ['TRY' => 99],
            ]),
        ]);
        (new ExchangeRateService)->sync(['TRY']);

        $rate = ExchangeRate::latestFor('TRY');
        $this->assertTrue($rate->is_manual);
        $this->assertEqualsWithDelta(42, (float) $rate->rate, 0.0001);
        $this->assertSame('Central bank fixing', $rate->override_reason);
    }

    public function test_sync_requires_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        Sanctum::actingAs($viewer);

        $this->postJson('/api/exchange-rates/sync')->assertStatus(403);
    }
}
