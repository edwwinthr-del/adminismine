<?php

namespace Database\Seeders;

use App\Models\CompanySettings;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(NotificationRulesSeeder::class);

        // Single-company: ensure the settings row exists.
        CompanySettings::current();

        // The only login a fresh database has. Stated explicitly rather than
        // taken from the factory so the credentials are the same every time,
        // and firstOrCreate so re-running `db:seed` on a live database never
        // resets a password someone has since changed.
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@test.test'],
            [
                'name' => 'Super Admin',
                'password' => 'password', // hashed by the model's `hashed` cast
                'locale' => 'en',
                'is_active' => true,
            ],
        );
        $superAdmin->assignRole(User::SUPER_ADMIN);
    }
}
