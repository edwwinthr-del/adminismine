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

        // Default Super Admin account. Password is 'password' (factory default).
        $superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@adminismine.local',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $superAdmin->assignRole('Super Admin');
    }
}
