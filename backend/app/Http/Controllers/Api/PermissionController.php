<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CompanyConfig;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->pluck('name');
        $config = app(CompanyConfig::class);

        /*
         * Permissions whose module this company has switched off.
         *
         * They stay in `data` and stay grantable on purpose. Disabling a module
         * **hides** it and never revokes anything (rows stay, foreign keys stay
         * valid, re-enabling finds everything where it was left) — so a grant
         * has to survive the module coming back. Dropping these from the list
         * would push an administrator to un-grant exactly what they should keep,
         * and they would have to remember to put it back.
         *
         * What they were missing is the reason. Offered with no explanation, an
         * admin grants `housing.manage`, watches it do nothing (the routes 404),
         * and reasonably concludes the permission system is broken. Naming them
         * lets the screen say "this module is hidden" instead.
         *
         * Derived, never declared: `Modules::forPermission()` already answers
         * which module owns a permission, so there is no second list to keep in
         * step with the catalogue.
         */
        $unavailable = $permissions
            ->filter(function (string $name) use ($config): bool {
                $module = Modules::forPermission($name);

                return $module !== null && ! $config->moduleEnabled($module);
            })
            ->values();

        return response()->json([
            'data' => $permissions->values(),
            // Grouped by module prefix (e.g. "payables") for UI grouping.
            'grouped' => $permissions->groupBy(fn (string $name) => explode('.', $name)[0]),
            'unavailable' => $unavailable,
        ]);
    }
}
