<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\WorkerNeed;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\NotificationTypes;
use Database\Seeders\NotificationRulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(bool $authenticate = true): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        if ($authenticate) {
            Sanctum::actingAs($user);
        }

        return $user;
    }

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    /** An overdue payable, and a rule that notifies Admins about it. */
    private function overdueInvoice(float $amount = 500): PayableInvoice
    {
        $supplier = Supplier::factory()->create(['name' => 'Acme DOO']);

        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => $amount,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
        $invoice->recalculate();

        return $invoice;
    }

    public function test_seeding_creates_a_rule_for_every_known_type(): void
    {
        $this->seed(NotificationRulesSeeder::class);

        $this->assertSame(
            count(NotificationTypes::TYPES),
            NotificationRule::query()->count(),
        );

        // Re-seeding must not disturb how an Admin configured a rule.
        NotificationRule::query()->where('type', 'payables.overdue')->update(['is_enabled' => false]);
        $this->seed(NotificationRulesSeeder::class);

        $this->assertFalse(NotificationRule::query()->where('type', 'payables.overdue')->first()->is_enabled);
    }

    public function test_an_overdue_invoice_notifies_the_rule_recipients(): void
    {
        $admin = $this->admin(false);
        $this->overdueInvoice(500);
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        $result = $this->dispatcher()->scan();

        $this->assertSame(1, $result['created']);

        $notification = Notification::query()->where('user_id', $admin->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('payables.overdue', $notification->type);
        $this->assertSame('unread', $notification->status);
        $this->assertSame('Acme DOO', $notification->data['supplier']);
        $this->assertSame(500, $notification->data['amount']);
    }

    public function test_a_second_scan_does_not_duplicate_the_same_issue(): void
    {
        $this->admin(false);
        $this->overdueInvoice();
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        $this->dispatcher()->scan();
        $second = $this->dispatcher()->scan();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_paying_the_invoice_resolves_the_notification_on_the_next_scan(): void
    {
        $this->admin(false);
        $invoice = $this->overdueInvoice(500);
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        $this->dispatcher()->scan();
        $this->assertSame('unread', Notification::query()->first()->status);

        $invoice->payments()->create([
            'amount' => 500,
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ]);
        $invoice->recalculate();

        $result = $this->dispatcher()->scan();

        $this->assertSame(1, $result['resolved']);
        $this->assertSame('resolved', Notification::query()->first()->status);
    }

    public function test_an_issue_that_comes_back_reopens_the_notification(): void
    {
        $this->admin(false);
        $invoice = $this->overdueInvoice(500);
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        $this->dispatcher()->scan();

        $payment = $invoice->payments()->create([
            'amount' => 500,
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ]);
        $invoice->recalculate();
        $this->dispatcher()->scan();
        $this->assertSame('resolved', Notification::query()->first()->status);

        // The payment turns out to be wrong and is removed.
        $payment->delete();
        $invoice->recalculate();
        $result = $this->dispatcher()->scan();

        $this->assertSame(1, $result['created']);
        $this->assertSame('unread', Notification::query()->first()->status);
        // Still one row: reopened rather than duplicated.
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_a_dismissed_notification_stays_dismissed_across_scans(): void
    {
        $this->admin(false);
        $this->overdueInvoice();
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        $this->dispatcher()->scan();
        Notification::query()->first()->dismiss();

        $this->dispatcher()->scan();

        $this->assertSame('dismissed', Notification::query()->first()->status);
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_a_disabled_rule_generates_nothing(): void
    {
        $this->admin(false);
        $this->overdueInvoice();
        NotificationRule::factory()->disabled()->create(['type' => 'payables.overdue']);

        $this->dispatcher()->scan();

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_rule_with_no_recipients_generates_nothing(): void
    {
        $this->admin(false);
        $this->overdueInvoice();
        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => [],
            'recipient_user_ids' => [],
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * Naming a user is how you include someone who does not hold the *role* —
     * that is the point of the field and it still works. What it is not is a way
     * around the permission: a role is not a permission (rule 6).
     */
    public function test_a_named_user_is_notified_even_without_the_role(): void
    {
        $this->admin(false);
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('payables.view');
        $this->overdueInvoice();

        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => [],
            'recipient_user_ids' => [$outsider->id],
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(1, Notification::query()->where('user_id', $outsider->id)->count());
    }

    /**
     * The leak this closes: a notification's `data` carries the supplier name,
     * the invoice number and the outstanding amount, so delivering one to
     * somebody who cannot open `/payables` hands them figures the app otherwise
     * refuses them. Whoever configures a rule chooses among people who may
     * already see the data; they never grant sight of it.
     */
    public function test_a_named_user_without_the_permission_is_not_notified(): void
    {
        $this->admin(false);
        $outsider = User::factory()->create();
        $outsider->assignRole('Worker'); // seeded with no permissions at all
        $this->overdueInvoice();

        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => [],
            'recipient_user_ids' => [$outsider->id],
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(0, Notification::query()->where('user_id', $outsider->id)->count());
    }

    /** A role named on the rule does not carry the data either, if it cannot see it. */
    public function test_a_role_without_the_permission_is_not_notified(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('Worker');
        $this->overdueInvoice();

        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => ['Worker'],
            'recipient_user_ids' => [],
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(0, Notification::query()->where('user_id', $worker->id)->count());
    }

    /** A permission held directly counts, exactly as it does everywhere else. */
    public function test_a_direct_grant_is_enough_to_be_notified(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('payables.view');
        $this->overdueInvoice();

        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => [],
            'recipient_user_ids' => [$viewer->id],
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(1, Notification::query()->where('user_id', $viewer->id)->count());
    }

    /** Custom reminders carry no module data, so they are not permission-gated. */
    public function test_a_custom_reminder_reaches_a_user_with_no_permissions(): void
    {
        $author = $this->admin();
        $recipient = User::factory()->create();
        $recipient->assignRole('Worker');

        $this->postJson('/api/notifications/reminders', [
            'title' => 'Bring the fuel receipts',
            'user_ids' => [$recipient->id],
        ])->assertCreated();

        $this->assertSame(1, Notification::query()->where('user_id', $recipient->id)->count());
        $this->assertNotNull($author);
    }

    public function test_a_user_who_opted_out_is_skipped(): void
    {
        $admin = $this->admin(false);
        $this->overdueInvoice();
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        UserNotificationPreference::create([
            'user_id' => $admin->id,
            'type' => 'payables.overdue',
            'is_enabled' => false,
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_days_before_timing_only_fires_inside_its_window(): void
    {
        $this->admin(false);

        $supplier = Supplier::factory()->create();
        $near = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
        $near->recalculate();
        $far = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'due_date' => now()->addDays(40)->toDateString(),
        ]);
        $far->recalculate();

        NotificationRule::factory()->create([
            'type' => 'payables.unpaid',
            'timing' => 'days_before',
            'days_before' => 7,
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame($near->id, Notification::query()->first()->subject_id);
    }

    public function test_a_summary_rule_collapses_everything_into_one_notification(): void
    {
        $this->admin(false);

        $supplier = Supplier::factory()->create();
        foreach ([100, 200, 300] as $amount) {
            $invoice = PayableInvoice::factory()->create([
                'supplier_id' => $supplier->id,
                'original_amount' => $amount,
                'due_date' => now()->addDays(5)->toDateString(),
            ]);
            $invoice->recalculate();
        }

        NotificationRule::factory()->create([
            'type' => 'payables.unpaid',
            'timing' => 'weekly_summary',
        ]);

        $this->dispatcher()->scan();

        $notifications = Notification::query()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame(3, $notifications->first()->data['count']);
        $this->assertSame(600, $notifications->first()->data['total']);
        $this->assertNull($notifications->first()->subject_id);
    }

    public function test_urgent_worker_needs_are_reported_as_critical(): void
    {
        $this->admin(false);

        WorkerNeed::factory()->create(['priority' => 'urgent', 'status' => 'open']);
        WorkerNeed::factory()->create(['priority' => 'normal', 'status' => 'open']);

        NotificationRule::factory()->create([
            'type' => 'worker_needs.urgent_open',
            'severity' => 'critical',
        ]);

        $this->dispatcher()->scan();

        $notifications = Notification::query()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('critical', $notifications->first()->severity);
    }

    public function test_unapproved_attendance_is_grouped_by_day(): void
    {
        $this->admin(false);

        foreach (range(1, 3) as $ignored) {
            AttendanceRecord::factory()->create([
                'date' => '2026-07-20',
                'approval_status' => 'submitted',
            ]);
        }
        AttendanceRecord::factory()->create([
            'date' => '2026-07-21',
            'approval_status' => 'submitted',
        ]);

        NotificationRule::factory()->create(['type' => 'attendance.unapproved']);

        $this->dispatcher()->scan();

        // One per day waiting, not one per worker per day.
        $this->assertSame(2, Notification::query()->count());
        $this->assertSame(
            3,
            Notification::query()->where('due_date', '2026-07-20')->first()->data['count'],
        );
    }

    public function test_expiring_machine_papers_produce_one_notification_per_document(): void
    {
        $this->admin(false);

        Machine::factory()->create([
            'registration_expiry' => now()->addDays(10)->toDateString(),
            'insurance_expiry' => now()->addDays(20)->toDateString(),
        ]);
        // Far in the future — outside the window.
        Machine::factory()->create(['registration_expiry' => now()->addDays(200)->toDateString()]);

        NotificationRule::factory()->create([
            'type' => 'machines.document_expiring',
            'timing' => 'days_before',
            'days_before' => 30,
        ]);

        $this->dispatcher()->scan();

        $this->assertSame(2, Notification::query()->count());
        $this->assertEqualsCanonicalizing(
            ['registration_expiry', 'insurance_expiry'],
            Notification::query()->get()->pluck('data.document')->all(),
        );
    }

    public function test_expiring_worker_documents_are_reported_per_document(): void
    {
        $this->admin(false);

        Employee::factory()->create([
            'status' => 'active',
            'work_permit_expiry' => now()->addDays(10)->toDateString(),
            'medical_exam_expiry' => now()->addDays(400)->toDateString(),
        ]);

        NotificationRule::factory()->create([
            'type' => 'employees.document_expiring',
            'timing' => 'days_before',
            'days_before' => 60,
        ]);

        $this->dispatcher()->scan();

        $notifications = Notification::query()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('work_permit_expiry', $notifications->first()->data['document']);
    }

    public function test_a_user_only_ever_sees_their_own_notifications(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();

        Notification::create([
            'user_id' => $other->id,
            'type' => 'custom.reminder',
            'data' => ['title' => 'Not yours'],
            'dedupe_key' => 'custom.reminder:other',
        ]);
        $mine = Notification::create([
            'user_id' => $admin->id,
            'type' => 'custom.reminder',
            'data' => ['title' => 'Mine'],
            'dedupe_key' => 'custom.reminder:mine',
        ]);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.unread', 1);

        // Someone else's notification is not addressable at all.
        $this->putJson('/api/notifications/'.Notification::query()->where('user_id', $other->id)->first()->id.'/read')
            ->assertNotFound();
    }

    public function test_notifications_move_through_read_dismiss_and_resolve(): void
    {
        $admin = $this->admin();

        $notification = Notification::create([
            'user_id' => $admin->id,
            'type' => 'custom.reminder',
            'data' => ['title' => 'Check the safe'],
            'dedupe_key' => 'custom.reminder:1',
        ]);

        $this->putJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.status', 'read');

        $this->putJson("/api/notifications/{$notification->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed');

        $this->putJson("/api/notifications/{$notification->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        // Dismissed and resolved rows drop out of the live list but stay in history.
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/notifications?include_history=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_mark_all_read_clears_the_bell(): void
    {
        $admin = $this->admin();

        foreach (range(1, 3) as $index) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'custom.reminder',
                'data' => ['title' => "Reminder {$index}"],
                'dedupe_key' => "custom.reminder:{$index}",
            ]);
        }

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 3);

        $this->putJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread', 0)
            ->assertJsonPath('data.open', 3);
    }

    public function test_a_custom_reminder_reaches_the_named_recipients(): void
    {
        $author = $this->admin();
        $colleague = User::factory()->create();

        $this->postJson('/api/notifications/reminders', [
            'title' => 'Renew the Niksic lease',
            'body' => 'Landlord wants an answer this week.',
            'due_date' => '2026-08-01',
            'severity' => 'warning',
            'user_ids' => [$colleague->id],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'custom.reminder')
            ->assertJsonPath('data.0.severity', 'warning')
            // The author's own words are stored verbatim, never translated.
            ->assertJsonPath('data.0.data.title', 'Renew the Niksic lease');

        $this->assertSame(1, Notification::query()->where('user_id', $colleague->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $author->id)->count());
    }

    public function test_a_reminder_with_no_recipients_goes_to_its_author(): void
    {
        $author = $this->admin();

        $this->postJson('/api/notifications/reminders', ['title' => 'Call the accountant'])
            ->assertCreated();

        $this->assertSame(1, Notification::query()->where('user_id', $author->id)->count());
    }

    public function test_admins_can_reconfigure_a_rule(): void
    {
        $this->admin();
        $this->seed(NotificationRulesSeeder::class);

        $this->putJson('/api/settings/notification-rules', [
            'rules' => [[
                'type' => 'payables.overdue',
                'is_enabled' => false,
                'timing' => 'days_before',
                'days_before' => 14,
                'severity' => 'critical',
                'channels' => ['in_app'],
                'recipient_roles' => ['Admin', 'Viewer'],
                'recipient_user_ids' => [],
            ]],
        ])->assertOk();

        $rule = NotificationRule::query()->where('type', 'payables.overdue')->first();
        $this->assertFalse($rule->is_enabled);
        $this->assertSame('days_before', $rule->timing);
        $this->assertSame(14, $rule->days_before);
        $this->assertSame('critical', $rule->severity);
        $this->assertEqualsCanonicalizing(['Admin', 'Viewer'], $rule->recipient_roles);
    }

    /**
     * Refused where it is typed, rather than accepted and then silently dropped
     * at delivery — an administrator who names someone must not walk away
     * believing that person is covered.
     */
    public function test_naming_a_user_who_cannot_see_the_module_is_refused(): void
    {
        $this->admin();
        $this->seed(NotificationRulesSeeder::class);

        $outsider = User::factory()->create(['name' => 'Warehouse Worker']);
        $outsider->assignRole('Worker');

        $this->putJson('/api/settings/notification-rules', [
            'rules' => [[
                'type' => 'payables.overdue',
                'is_enabled' => true,
                'timing' => 'after_due',
                'days_before' => null,
                'severity' => 'warning',
                'channels' => ['in_app'],
                'recipient_roles' => [],
                'recipient_user_ids' => [$outsider->id],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('rules.0.recipient_user_ids.0');

        // Granting the permission is what makes it possible.
        $outsider->givePermissionTo('payables.view');

        $this->putJson('/api/settings/notification-rules', [
            'rules' => [[
                'type' => 'payables.overdue',
                'is_enabled' => true,
                'timing' => 'after_due',
                'days_before' => null,
                'severity' => 'warning',
                'channels' => ['in_app'],
                'recipient_roles' => [],
                'recipient_user_ids' => [$outsider->id],
            ]],
        ])->assertOk();
    }

    public function test_a_days_before_rule_must_say_how_many_days(): void
    {
        $this->admin();

        $this->putJson('/api/settings/notification-rules', [
            'rules' => [[
                'type' => 'payables.overdue',
                'is_enabled' => true,
                'timing' => 'days_before',
                'days_before' => null,
                'severity' => 'warning',
                'channels' => ['in_app'],
                'recipient_roles' => ['Admin'],
                'recipient_user_ids' => [],
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rules.0.days_before');
    }

    public function test_a_day_count_is_dropped_when_the_timing_does_not_use_one(): void
    {
        $this->admin();
        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'timing' => 'days_before',
            'days_before' => 14,
        ]);

        $this->putJson('/api/settings/notification-rules', [
            'rules' => [[
                'type' => 'payables.overdue',
                'is_enabled' => true,
                'timing' => 'after_due',
                'days_before' => 14,
                'severity' => 'warning',
                'channels' => ['in_app'],
                'recipient_roles' => ['Admin'],
                'recipient_user_ids' => [],
            ]],
        ])->assertOk();

        $this->assertNull(NotificationRule::query()->where('type', 'payables.overdue')->first()->days_before);
    }

    public function test_personal_preferences_are_saved_and_can_be_cleared(): void
    {
        $admin = $this->admin();

        $this->getJson('/api/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.0.is_enabled', null);

        $this->putJson('/api/me/notification-preferences', [
            'preferences' => [['type' => 'payables.overdue', 'is_enabled' => false]],
        ])->assertOk();

        $this->assertSame(1, UserNotificationPreference::query()->where('user_id', $admin->id)->count());

        // Handing the decision back to the rule removes the override entirely.
        $this->putJson('/api/me/notification-preferences', [
            'preferences' => [['type' => 'payables.overdue', 'is_enabled' => null]],
        ])->assertOk();

        $this->assertSame(0, UserNotificationPreference::query()->where('user_id', $admin->id)->count());
    }

    public function test_rule_configuration_requires_the_configure_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/settings/notification-rules')->assertForbidden();
        $this->putJson('/api/settings/notification-rules', ['rules' => []])->assertForbidden();
        $this->postJson('/api/notifications/scan')->assertForbidden();
        $this->postJson('/api/notifications/reminders', ['title' => 'x'])->assertForbidden();

        // But everyone may read their own centre and set their own preferences.
        $this->getJson('/api/notifications')->assertOk();
        $this->getJson('/api/me/notification-preferences')->assertOk();
    }

    public function test_admins_can_trigger_a_scan_from_the_api(): void
    {
        $this->admin();
        $this->overdueInvoice();
        NotificationRule::factory()->create(['type' => 'payables.overdue']);

        $this->postJson('/api/notifications/scan')
            ->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    /**
     * The shipped configuration on a fresh install: every seeded rule names the
     * role `Admin`, and the only account `migrate --seed` creates is a Super
     * Admin. The role query is literal, so nothing matched and the app raised
     * its first notification for nobody — with no way to tell why.
     */
    public function test_a_rule_whose_roles_match_nobody_still_reaches_a_super_admin(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $this->overdueInvoice();
        $this->seed(NotificationRulesSeeder::class);

        $created = $this->dispatcher()->scan();

        $this->assertGreaterThan(0, $created);
        $this->assertTrue(
            Notification::where('user_id', $superAdmin->id)->where('type', 'payables.overdue')->exists()
        );
    }

    /** A configured rule that does match somebody is left alone. */
    public function test_the_fallback_does_not_fire_when_the_rule_reaches_someone(): void
    {
        $admin = $this->admin(authenticate: false);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $this->overdueInvoice();
        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => ['Admin'],
        ]);

        $this->dispatcher()->scan();

        $this->assertTrue(Notification::where('user_id', $admin->id)->exists());
        $this->assertFalse(Notification::where('user_id', $superAdmin->id)->exists());
    }

    /**
     * A deactivated account cannot read its bell, so writing to it only builds a
     * backlog that greets the person if they are ever restored.
     */
    public function test_a_deactivated_user_stops_receiving_notifications(): void
    {
        $active = $this->admin(authenticate: false);
        $deactivated = $this->admin(authenticate: false);
        $deactivated->forceFill(['is_active' => false])->save();

        $this->overdueInvoice();
        NotificationRule::factory()->create([
            'type' => 'payables.overdue',
            'recipient_roles' => ['Admin'],
        ]);

        $this->dispatcher()->scan();

        $this->assertTrue(Notification::where('user_id', $active->id)->exists());
        $this->assertFalse(Notification::where('user_id', $deactivated->id)->exists());
    }
}
