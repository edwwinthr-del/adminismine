<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\ExchangeRate;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_columns_are_stamped_from_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = Supplier::factory()->create();

        $this->assertSame($user->id, $supplier->created_by);
        $this->assertSame($user->id, $supplier->updated_by);
        $this->assertTrue($supplier->createdBy->is($user));
    }

    public function test_audit_columns_are_null_without_an_authenticated_user(): void
    {
        $supplier = Supplier::factory()->create();

        $this->assertNull($supplier->created_by);
        $this->assertNull($supplier->updated_by);
    }

    public function test_company_settings_has_expected_defaults(): void
    {
        $settings = CompanySettings::current();

        $this->assertSame('AdminisMine DOO', $settings->company_name);
        $this->assertSame('EUR', $settings->base_currency);
    }

    public function test_exchange_rate_converts_between_try_and_eur(): void
    {
        $rate = ExchangeRate::factory()->create(['rate' => 40]);

        // 1 EUR = 40 TRY
        $this->assertEqualsWithDelta(2.5, $rate->toBase(100), 0.0001);   // 100 TRY -> 2.5 EUR
        $this->assertEqualsWithDelta(4000, $rate->toQuote(100), 0.0001); // 100 EUR -> 4000 TRY
    }
}
