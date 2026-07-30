<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use App\Support\NotificationTypes;
use Illuminate\Database\Seeder;

/**
 * Creates the rule row for every known type. Only missing rows are added, so
 * re-seeding never overwrites how an Admin has configured a rule.
 */
class NotificationRulesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (NotificationTypes::TYPES as $type => $defaults) {
            NotificationRule::firstOrCreate(
                ['type' => $type],
                [
                    'is_enabled' => true,
                    'timing' => $defaults['timing'],
                    'days_before' => $defaults['days_before'],
                    'severity' => $defaults['severity'],
                    'channels' => ['in_app'],
                    'recipient_roles' => $defaults['roles'],
                    'recipient_user_ids' => [],
                    'source' => 'seed',
                ],
            );
        }
    }
}
