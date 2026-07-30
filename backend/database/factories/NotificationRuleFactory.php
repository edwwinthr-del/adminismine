<?php

namespace Database\Factories;

use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    protected $model = NotificationRule::class;

    public function definition(): array
    {
        return [
            'type' => 'payables.overdue',
            'is_enabled' => true,
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'warning',
            'channels' => ['in_app'],
            'recipient_roles' => ['Admin'],
            'recipient_user_ids' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }
}
