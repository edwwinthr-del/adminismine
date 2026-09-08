<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Employee;
use App\Models\House;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\User;
use App\Models\WorkerNeed;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Modules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The parts of the app a company can decide it does not have.
 *
 * A module is identified by the permissions it owns, so one map drives every
 * surface: routes, reports, lookups, import entities, notifications and the
 * dashboard. These tests pin all six, plus the two rules that make the toggle
 * safe — the core cannot be switched off, and disabling hides without deleting.
 */
class ModuleToggleTest extends TestCase
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

    /** Everything except the named modules. */
    private function enableAllBut(string ...$off): void
    {
        CompanySettings::current()->update([
            'enabled_modules' => array_values(array_diff(Modules::keys(), $off)),
        ]);
    }

    public function test_an_install_that_never_configured_modules_has_them_all(): void
    {
        $this->actingAsAdmin();

        // Null in the column means everything: an existing install is unchanged,
        // and a module added in a later release is on rather than invisible.
        $this->assertNull(CompanySettings::current()->enabled_modules);

        $this->getJson('/api/houses')->assertOk();
        $this->getJson('/api/employees')->assertOk();
    }

    public function test_a_disabled_module_has_no_routes(): void
    {
        $this->actingAsAdmin();
        $this->enableAllBut('housing');

        // 404, not 403: the module does not exist for this company, and 403
        // would tell them what they are not buying.
        $this->getJson('/api/houses')->assertNotFound();
        $this->postJson('/api/houses', ['name' => 'Kuca 1'])->assertNotFound();
        $this->getJson('/api/housing/summary')->assertNotFound();

        // A neighbouring module is untouched.
        $this->getJson('/api/employees')->assertOk();
    }

    public function test_a_disabled_module_keeps_its_records(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->enableAllBut('housing');
        $this->getJson('/api/houses')->assertNotFound();

        // Hidden, not deleted — that is what makes the toggle safe to reach for.
        $this->assertDatabaseHas('kuce', ['id' => $house->id]);

        $this->enableAllBut();
        $this->getJson('/api/houses')->assertOk()->assertJsonPath('data.0.id', $house->id);
    }

    public function test_a_disabled_module_drops_out_of_the_reports_list(): void
    {
        $this->actingAsAdmin();

        $keys = fn (): array => collect($this->getJson('/api/reports')->json('data'))->pluck('key')->all();

        $this->assertContains('worker_housing_cost_report', $keys());

        $this->enableAllBut('housing');

        $this->assertNotContains('worker_housing_cost_report', $keys());
        // A core report is never affected.
        $this->assertContains('monthly_cashflow', $keys());
    }

    public function test_a_disabled_module_has_no_lookups(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/lookups/houses')->assertOk();

        $this->enableAllBut('housing');

        $this->getJson('/api/lookups/houses')->assertNotFound();
        $this->getJson('/api/lookups/suppliers')->assertOk();
    }

    public function test_a_disabled_module_drops_out_of_the_import_picker(): void
    {
        $this->actingAsAdmin();

        $entities = fn (): array => collect($this->getJson('/api/imports/entities')->json('data'))
            ->pluck('key')->all();

        $this->assertContains('employee', $entities());

        $this->enableAllBut('workers');

        $this->assertNotContains('employee', $entities());
        $this->getJson('/api/imports/template?entity=employee')->assertNotFound();
        // Payables are core.
        $this->assertContains('payable_invoice', $entities());
    }

    public function test_a_disabled_module_generates_no_notifications(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        WorkerNeed::factory()->create(['priority' => 'urgent', 'status' => 'open']);
        NotificationRule::factory()->create(['type' => 'worker_needs.urgent_open']);

        $this->enableAllBut('worker_needs');
        app(NotificationDispatcher::class)->scan();

        $this->assertSame(0, Notification::query()->count());

        // Switched back on, the same scan finds it.
        $this->enableAllBut();
        app(NotificationDispatcher::class)->scan();

        $this->assertSame(1, Notification::query()->count());
    }

    public function test_a_disabled_module_is_absent_from_the_dashboard_rather_than_zeroed(): void
    {
        $this->actingAsAdmin();
        House::factory()->create();

        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['data' => ['housing']]);

        $this->enableAllBut('housing');

        // A zero is still a claim about data that is not there.
        $this->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('data.housing');
    }

    public function test_the_core_cannot_be_switched_off(): void
    {
        $this->actingAsAdmin();

        // An empty set turns off every togglable module — and the app is still a
        // business system: money in, money out, who did it.
        CompanySettings::current()->update(['enabled_modules' => []]);

        $this->getJson('/api/payables')->assertOk();
        $this->getJson('/api/receivables')->assertOk();
        $this->getJson('/api/bank-transactions')->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports')->assertOk();
        $this->getJson('/api/audit-logs')->assertOk();
    }

    public function test_a_module_still_needs_its_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('payables.view');
        Sanctum::actingAs($user);

        // Enabling a module grants nobody anything (rule 6): the toggle is ANDed
        // with the permission, never a replacement for it.
        $this->getJson('/api/houses')->assertForbidden();
    }

    public function test_a_module_cannot_be_enabled_without_what_it_points_at(): void
    {
        $this->actingAsAdmin();

        // Attendance rows carry a non-nullable employee_id and worksite_id:
        // without workers it is a table of rows about nobody.
        $this->putJson('/api/company-settings/modules', [
            'enabled_modules' => array_values(array_diff(Modules::keys(), ['workers'])),
        ])->assertStatus(422)->assertJsonValidationErrors('enabled_modules');

        // Dropping the dependants along with it is fine.
        $this->putJson('/api/company-settings/modules', [
            'enabled_modules' => array_values(array_diff(
                Modules::keys(),
                ['workers', 'attendance', 'salaries', 'masters', 'worker_needs', 'housing'],
            )),
        ])->assertOk();
    }

    public function test_an_unknown_module_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/modules', ['enabled_modules' => ['housing', 'teleportation']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled_modules.1');
    }

    public function test_the_catalogue_reports_what_each_module_holds(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->count(3)->create();

        $rows = collect($this->getJson('/api/company-settings/modules')->assertOk()->json('data'))
            ->keyBy('key');

        $this->assertSame(3, $rows['workers']['records']);
        $this->assertTrue($rows['workers']['enabled']);
        $this->assertContains('workers', $rows['attendance']['requires']);
        $this->assertContains('attendance', $rows['workers']['required_by']);
    }

    public function test_switching_a_module_needs_its_own_permission(): void
    {
        // Correcting the company's phone number and switching Housing off for
        // everybody were the same permission at first. They are not the same
        // power, and one is not a reason to hold the other (rule 6).
        $user = User::factory()->create();
        $user->givePermissionTo('company.settings.manage');
        Sanctum::actingAs($user);

        $this->getJson('/api/company-settings/modules')->assertForbidden();
        $this->putJson('/api/company-settings/modules', ['enabled_modules' => Modules::keys()])
            ->assertForbidden();

        // Renaming the company still works — that is what they were given.
        $this->putJson('/api/company-settings', ['company_name' => 'Something Else DOO'])->assertOk();

        $user->givePermissionTo('company.modules.manage');
        $this->getJson('/api/company-settings/modules')->assertOk();
    }

    public function test_the_settings_endpoint_no_longer_takes_modules(): void
    {
        $this->actingAsAdmin();

        // The field moved to its own endpoint. Sending it here is quietly
        // ignored rather than half-applied, and this is what would fail if
        // somebody put it back on the general settings request without giving it
        // a permission again.
        $this->putJson('/api/company-settings', [
            'enabled_modules' => ['housing'],
        ])->assertOk();

        $this->assertNull(CompanySettings::current()->fresh()->enabled_modules);
    }

    /** @return array<string, array{0: string}> */
    public static function togglableModules(): array
    {
        return collect(Modules::keys())
            ->mapWithKeys(fn (string $module): array => [$module => [$module]])
            ->all();
    }

    /**
     * Every module gates its own routes, and switching it off takes them away.
     *
     * The routes are **read out of the route table** rather than listed here: a
     * hand-kept list would pass forever for a module whose routes were never
     * gated in the first place, which is exactly the mistake this is for. Adding
     * a module to the catalogue and forgetting `module:` on its route group now
     * fails here.
     */
    #[DataProvider('togglableModules')]
    public function test_every_module_gates_its_own_routes(string $module): void
    {
        $this->actingAsAdmin();

        $route = $this->firstPlainGetRouteFor($module);

        $this->assertNotNull(
            $route,
            "No parameterless GET route carries module:{$module} — the module is in the catalogue but its routes are not gated.",
        );

        // Not asserting 200: some of these want query parameters and answer 422
        // without them. The question here is only whether the gate is open —
        // anything that is not a 404 means the route exists for this company.
        $this->assertNotSame(404, $this->getJson('/'.$route)->status());

        // Off with its dependants, which is what the settings screen does: a
        // module whose requirements are gone is a table of rows about nothing.
        $this->enableAllBut($module, ...Modules::dependents($module));

        $this->getJson('/'.$route)->assertNotFound();

        $this->enableAllBut();
        $this->assertNotSame(404, $this->getJson('/'.$route)->status());
    }

    /**
     * The first GET route gated by this module that needs no parameters — the
     * cheapest thing to call that proves the gate is wired.
     */
    private function firstPlainGetRouteFor(string $module): ?string
    {
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! in_array("module:{$module}", $route->gatherMiddleware(), true)) {
                continue;
            }

            if (str_contains($route->uri(), '{')) {
                continue;
            }

            return $route->uri();
        }

        return null;
    }

    public function test_every_permission_belongs_to_at_most_one_module(): void
    {
        $seen = [];

        // The whole design rests on this: a permission answers "which module?",
        // so two modules claiming one would make that answer ambiguous.
        foreach (Modules::MODULES as $module => $definition) {
            foreach ($definition['permissions'] as $permission) {
                $this->assertArrayNotHasKey(
                    $permission,
                    $seen,
                    "{$permission} is claimed by ".($seen[$permission] ?? '?')." and {$module}.",
                );

                $seen[$permission] = $module;

                $this->assertContains(
                    $permission,
                    RolesAndPermissionsSeeder::PERMISSIONS,
                    "{$permission} is not a permission the app grants.",
                );
            }
        }
    }

    public function test_every_requirement_names_a_real_module(): void
    {
        foreach (Modules::MODULES as $module => $definition) {
            foreach ($definition['requires'] as $required) {
                $this->assertTrue(
                    Modules::exists($required),
                    "{$module} requires {$required}, which is not a module.",
                );
            }
        }
    }

    public function test_a_hidden_modules_permissions_are_still_offered_but_marked(): void
    {
        $this->actingAsAdmin();
        $this->enableAllBut('housing');

        $response = $this->getJson('/api/permissions')->assertOk();

        /*
         * Still grantable: disabling a module hides it and revokes nothing, so a
         * grant has to survive the module coming back. Dropping it from the list
         * would push an administrator to un-grant what they should keep.
         */
        $this->assertContains('housing.manage', $response->json('data'));

        // But named, so the screen can say why granting it does nothing today.
        $this->assertContains('housing.manage', $response->json('unavailable'));
    }

    public function test_nothing_is_marked_unavailable_when_every_module_is_on(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonPath('unavailable', []);
    }

    public function test_core_permissions_are_never_marked_unavailable(): void
    {
        $this->actingAsAdmin();
        $this->enableAllBut(...Modules::keys());

        // Payables, roles, settings and the rest belong to no module and cannot
        // be switched off — so nothing may report them as hidden.
        $unavailable = $this->getJson('/api/permissions')->assertOk()->json('unavailable');

        foreach ($unavailable as $permission) {
            $this->assertNotNull(
                Modules::forPermission($permission),
                "{$permission} belongs to no module, so it cannot be hidden.",
            );
        }
    }

    public function test_every_permission_a_hidden_module_owns_is_marked(): void
    {
        $this->actingAsAdmin();

        // Attendance owns three. Marking one and missing the others would leave
        // the screen explaining part of why a role does not work.
        $this->enableAllBut('attendance');

        $unavailable = $this->getJson('/api/permissions')->assertOk()->json('unavailable');

        foreach (Modules::MODULES['attendance']['permissions'] as $permission) {
            $this->assertContains($permission, $unavailable, "{$permission} was not marked hidden.");
        }
    }

    public function test_hiding_a_module_removes_no_permission_from_the_list(): void
    {
        $this->actingAsAdmin();

        $before = $this->getJson('/api/permissions')->assertOk()->json('data');

        $this->enableAllBut('housing', 'travel', 'loans');

        // Marked, never dropped. A list that shrank would push an administrator
        // to un-grant what they should keep.
        $this->assertSame($before, $this->getJson('/api/permissions')->assertOk()->json('data'));
    }

    public function test_a_grant_survives_the_module_being_switched_off_and_back_on(): void
    {
        $this->actingAsAdmin();

        $role = Role::create(['name' => 'Housing officer', 'guard_name' => 'web']);
        $role->givePermissionTo('housing.manage');

        $this->enableAllBut('housing');

        // Hidden, so the route is gone — but the grant is untouched, which is
        // the whole reason these permissions stay listed and grantable.
        $this->getJson('/api/houses')->assertNotFound();
        $this->assertTrue($role->fresh()->hasPermissionTo('housing.manage'));
        $this->assertContains('housing.manage', $this->getJson('/api/permissions')->json('data'));

        $this->enableAllBut();

        // Switched back on, it works again with nobody having re-granted it.
        $this->assertTrue($role->fresh()->hasPermissionTo('housing.manage'));
        $this->getJson('/api/houses')->assertOk();
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('unavailable', []);
    }

    public function test_a_hidden_modules_permission_can_still_be_granted(): void
    {
        $this->actingAsAdmin();
        $this->enableAllBut('housing');

        // Setting a company up before switching a module on is a real order of
        // events, so granting ahead of time has to work.
        $this->postJson('/api/roles', [
            'name' => 'Housing officer',
            'permissions' => ['housing.manage'],
        ])->assertCreated();

        $role = Role::query()->where('name', 'Housing officer')->sole();

        $this->assertContains('housing.manage', $role->permissions->pluck('name')->all());
    }
}
