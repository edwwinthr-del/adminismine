<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** All granular permissions in the system. */
    public const PERMISSIONS = [
        'users.manage',
        'roles.manage',
        'company.settings.manage',
        /*
         * The configuration surfaces added with the industry profiles. They were
         * folded into company.settings.manage at first, which quietly turned
         * "edit the company name and timezone" into "switch off Housing for
         * everyone, rename every screen and reshape the install" — one
         * permission covering four unrelated powers is exactly what rule 6 is
         * against.
         */
        'company.modules.manage',
        'company.terminology.manage',
        'company.vocabularies.manage',
        'company.profile.manage',
        'exchange_rates.manage',
        'payables.view',
        'payables.create',
        'payables.approve',
        'receivables.manage',
        'bank_transactions.manage',
        'employees.manage',
        'salary_payments.manage',
        'masters.manage',
        'worksites.manage',
        'attendance.submit',
        'attendance.approve',
        'overtime.approve',
        'worker_needs.manage',
        'mining_production.submit',
        'mining_production.approve',
        'machines.manage',
        'customs_documents.manage',
        'housing.manage',
        'travel.manage',
        'loans.manage',
        'notifications.configure',
        'imports.manage',
        'assistant.use',
        'reports.view',
        'reports.export',
        'audit_logs.view',
    ];

    /** Core roles that must never be deleted (protected via is_system). */
    public const SYSTEM_ROLES = [
        'Super Admin',
        'Admin',
        'Administration Office Worker',
        'Worker',
        'Viewer',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::SYSTEM_ROLES as $name) {
            Role::findOrCreate($name, 'web')->forceFill(['is_system' => true])->save();
        }

        $all = Permission::all();

        // Super Admin & Admin get every permission. Super Admin additionally
        // bypasses all gates via Gate::before (see AppServiceProvider).
        Role::findByName('Super Admin', 'web')->syncPermissions($all);
        Role::findByName('Admin', 'web')->syncPermissions($all);

        // Viewer: read-only defaults.
        Role::findByName('Viewer', 'web')->syncPermissions(['payables.view', 'reports.view']);

        // Administration Office Worker & Worker start with no permissions;
        // an Admin/Super Admin grants them specific permission parameters.

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
