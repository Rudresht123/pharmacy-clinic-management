<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionReminderDelivery;
use App\Services\Modules\ModuleAccess;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\SubscriptionClock;
use App\Services\Subscriptions\SubscriptionFacts;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The contract itself: dates, statuses, locks, and what it grants — decided
 * from the master database alone, so none of this needs a tenant.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        // A fixed morning in IST, so calendar-day counts are predictable.
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function lifecycle(): SubscriptionLifecycle
    {
        return app(SubscriptionLifecycle::class);
    }

    private function entitlements(): EntitlementService
    {
        // Fresh each time: SubscriptionFacts memoizes per request.
        app()->forgetScopedInstances();

        return app(EntitlementService::class);
    }

    /** @param  array<string, int|null>  $limits */
    private function plan(string $slug, array $modules = [], array $limits = [], array $attributes = []): Plan
    {
        $plan = Plan::query()->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'status' => Plan::PUBLISHED,
            'is_public' => true,
            'monthly_price' => 149900,
            'annual_price' => 1499000,
            'trial_days' => 14,
            'grace_days' => 3,
            'features' => [Entitlements::VIDEO_CONSULTATION],
            ...$attributes,
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => $limits[$key] ?? 10]);
        }

        $plan->modules()->sync(Module::query()->whereIn('key', $modules)->pluck('id'));

        return $plan;
    }

    private function organization(): Organization
    {
        return Organization::factory()->active()->create(['timezone' => 'Asia/Kolkata']);
    }

    private function admin(): Actor
    {
        return Actor::system('Test admin');
    }

    /*
    |--------------------------------------------------------------------------
    | A1 — existing organizations stay exactly as they are
    |--------------------------------------------------------------------------
    */

    public function test_an_organization_with_no_subscription_is_never_locked(): void
    {
        $organization = $this->organization();

        $this->assertNull(Subscription::query()->where('organization_id', $organization->id)->first());
        $this->assertFalse($this->entitlements()->isLocked($organization));
        $this->assertNull($this->entitlements()->getResourceLimit($organization, Entitlements::DOCTORS));
        $this->assertTrue($this->entitlements()->hasFeature($organization, Entitlements::VIDEO_CONSULTATION));
    }

    public function test_the_rollout_puts_every_organization_on_complimentary_without_changing_its_modules(): void
    {
        $organization = $this->organization();
        $trashed = $this->organization();
        $trashed->delete();

        $appointments = Module::query()->where('key', 'appointments')->firstOrFail();
        $pharmacy = Module::query()->where('key', 'pharmacy')->firstOrFail();
        $laboratory = Module::query()->where('key', 'laboratory')->firstOrFail();

        // Enabled, revoked and lapsed bindings — the mix a real clinic has.
        OrganizationModule::query()->create(['organization_id' => $organization->id, 'module_id' => $appointments->id, 'is_enabled' => true]);
        OrganizationModule::query()->create(['organization_id' => $organization->id, 'module_id' => $pharmacy->id, 'is_enabled' => false]);
        OrganizationModule::query()->create([
            'organization_id' => $organization->id,
            'module_id' => $laboratory->id,
            'is_enabled' => true,
            'expires_at' => now()->subDays(3),
        ]);

        $before = app(ModuleAccess::class)->enabled($organization);

        $migration = require database_path('migrations/masterdb/2026_10_27_000400_backfill_complimentary_subscriptions.php');
        $migration->up();
        $migration->up(); // and again: it must be safe to replay

        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->sole();

        $this->assertSame(Subscription::COMPLIMENTARY, $subscription->billing_cycle);
        $this->assertNull($subscription->expires_at);
        $this->assertSame(1, Subscription::query()->where('organization_id', $trashed->id)->count());

        $this->assertSame($before, $this->freshModuleAccess()->enabled($organization));
        $this->assertContains('appointments', $before);
        $this->assertNotContains('pharmacy', $before);
        $this->assertNotContains('laboratory', $before);

        // Ten years on, still not locked.
        $this->assertFalse($this->entitlements()->isLocked($organization, now()->addYears(10)));
    }

    /*
    |--------------------------------------------------------------------------
    | A2 — module precedence
    |--------------------------------------------------------------------------
    */

    public function test_plan_modules_apply_and_manual_overrides_beat_them_both_ways(): void
    {
        $organization = $this->organization();
        $plan = $this->plan('growth', ['appointments', 'prescriptions', 'medicines']);

        $this->lifecycle()->assign($organization, $plan, Subscription::MONTHLY, $this->admin(), 'Sold.');

        $enabled = $this->freshModuleAccess()->enabled($organization);
        $this->assertContains('appointments', $enabled);
        $this->assertContains('prescriptions', $enabled);
        $this->assertNotContains('pharmacy', $enabled);

        $prescriptions = Module::query()->where('key', 'prescriptions')->firstOrFail();
        $pharmacy = Module::query()->where('key', 'pharmacy')->firstOrFail();

        // A revoke beats the plan; a grant beats its absence.
        OrganizationModule::query()->create(['organization_id' => $organization->id, 'module_id' => $prescriptions->id, 'is_enabled' => false, 'reason' => 'Dispute']);
        OrganizationModule::query()->create(['organization_id' => $organization->id, 'module_id' => $pharmacy->id, 'is_enabled' => true, 'reason' => 'Goodwill']);

        $enabled = $this->freshModuleAccess()->enabled($organization);
        $this->assertNotContains('prescriptions', $enabled);
        $this->assertContains('pharmacy', $enabled);

        $sources = $this->freshModuleAccess()->sources($organization);
        $this->assertSame('manual_revoke', $sources['prescriptions']);
        $this->assertSame('manual_grant', $sources['pharmacy']);
        $this->assertSame('plan', $sources['appointments']);
        $this->assertSame('core', $sources['billing']);
    }

    public function test_a_lapsed_manual_grant_falls_back_to_the_plan_not_to_off(): void
    {
        $organization = $this->organization();
        $plan = $this->plan('growth', ['appointments']);
        $this->lifecycle()->assign($organization, $plan, Subscription::MONTHLY, $this->admin());

        $appointments = Module::query()->where('key', 'appointments')->firstOrFail();

        // Revoked, but the revoke ran out yesterday: the plan's answer is back.
        OrganizationModule::query()->create([
            'organization_id' => $organization->id,
            'module_id' => $appointments->id,
            'is_enabled' => false,
            'expires_at' => now()->subDays(2),
        ]);

        $this->assertContains('appointments', $this->freshModuleAccess()->enabled($organization));
    }

    public function test_a_module_add_on_is_granted_and_survives_a_plan_change(): void
    {
        $organization = $this->organization();
        $starter = $this->plan('starter', ['appointments']);
        $growth = $this->plan('growth', ['appointments', 'prescriptions', 'medicines']);

        $subscription = $this->lifecycle()->assign($organization, $starter, Subscription::MONTHLY, $this->admin());
        $this->lifecycle()->addAddon($subscription, 'module', 'laboratory', 1, 50000, $this->admin(), 'Bought lab.');

        $this->assertContains('laboratory', $this->freshModuleAccess()->enabled($organization));
        $this->assertSame('addon', $this->freshModuleAccess()->sources($organization)['laboratory']);

        $this->lifecycle()->assign($organization, $growth, Subscription::MONTHLY, $this->admin(), 'Upgrade.');

        $this->assertContains('laboratory', $this->freshModuleAccess()->enabled($organization));
    }

    /*
    |--------------------------------------------------------------------------
    | Trial, term, grace, lock — A3: from the dates, scheduler or not
    |--------------------------------------------------------------------------
    */

    public function test_a_trial_counts_down_in_calendar_days_and_locks_when_it_ends(): void
    {
        $organization = $this->organization();
        $plan = $this->plan('growth', ['appointments'], [], ['trial_days' => 14, 'grace_days' => 5]);

        $subscription = $this->lifecycle()->startTrial($organization, $plan, $this->admin());

        // 14 days from the 15th: the 15th … the 28th, to 23:59:59.
        $this->assertSame('2026-10-28 23:59:59', $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::TRIAL, $this->entitlements()->getSubscriptionStatus($organization));
        $this->assertSame(13, $this->entitlements()->getDaysRemaining($organization));

        // The last day: "expires today".
        $this->assertSame(0, $this->entitlements()->getDaysRemaining($organization, Carbon::parse('2026-10-28 22:00', 'Asia/Kolkata')));
        $this->assertFalse($this->entitlements()->isLocked($organization, Carbon::parse('2026-10-28 23:59:00', 'Asia/Kolkata')));

        // A trial has no grace: locked the next morning, without the scheduler.
        $this->assertTrue($this->entitlements()->isLocked($organization, Carbon::parse('2026-10-29 00:00:01', 'Asia/Kolkata')));
    }

    public function test_a_paid_term_goes_through_grace_and_locks_when_grace_ends_without_the_scheduler(): void
    {
        $organization = $this->organization();
        $plan = $this->plan('growth', [], [], ['grace_days' => 3]);

        $subscription = $this->lifecycle()->assign($organization, $plan, Subscription::MONTHLY, $this->admin());

        // A month from the 15th of October runs to the 14th of November.
        $this->assertSame('2026-11-14 23:59:59', $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-17 23:59:59', $subscription->grace_ends_at->format('Y-m-d H:i:s'));

        $this->travelTo(Carbon::parse('2026-11-16 10:00', 'Asia/Kolkata'));
        $this->assertSame(Subscription::GRACE, $this->entitlements()->getSubscriptionStatus($organization));
        $this->assertTrue($this->entitlements()->canAccessApplication($organization));
        $this->assertSame(1, $this->entitlements()->status($organization)['grace_days_remaining']);

        // One second past grace: locked, and nothing has run.
        $this->travelTo(Carbon::parse('2026-11-18 00:00:01', 'Asia/Kolkata'));
        $this->assertTrue($this->entitlements()->isLocked($organization));
        $this->assertSame(Subscription::ACTIVE, $subscription->refresh()->status, 'the stored status has not been touched');
    }

    public function test_the_scheduler_writes_each_transition_once(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth', [], [], ['grace_days' => 2]), Subscription::MONTHLY, $this->admin());

        $this->travelTo($subscription->expires_at->copy()->addHour());
        $this->assertSame(Subscription::GRACE, $this->lifecycle()->tick($subscription->refresh()));
        $this->assertNull($this->lifecycle()->tick($subscription->refresh()), 'nothing to do the second time');

        $this->travelTo($subscription->grace_ends_at->copy()->addMinute());
        $this->assertSame(Subscription::EXPIRED, $this->lifecycle()->tick($subscription->refresh()));
        $this->lifecycle()->tick($subscription->refresh());
        $this->lifecycle()->tick($subscription->refresh());

        $subscription->refresh();
        $this->assertSame(Subscription::EXPIRED, $subscription->status);
        $this->assertSame(Subscription::LOCK_EXPIRED, $subscription->lock_reason);
        $this->assertNotNull($subscription->locked_at);

        $events = SubscriptionEvent::query()->where('subscription_id', $subscription->id)->pluck('type')->all();
        $this->assertSame(1, count(array_keys($events, SubscriptionEvent::GRACE_STARTED)));
        $this->assertSame(1, count(array_keys($events, SubscriptionEvent::EXPIRED)));
        $this->assertSame(1, count(array_keys($events, SubscriptionEvent::LOCKED)));
    }

    /*
    |--------------------------------------------------------------------------
    | Renewal, extension, suspension
    |--------------------------------------------------------------------------
    */

    public function test_renewing_a_locked_subscription_unlocks_it_from_now(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth'), Subscription::MONTHLY, $this->admin());

        $this->travelTo($subscription->grace_ends_at->copy()->addDays(10));
        $this->lifecycle()->tick($subscription->refresh());
        $this->assertTrue($this->entitlements()->isLocked($organization));

        $renewed = $this->lifecycle()->renew($subscription->refresh(), $this->admin(), 'Paid by bank transfer.');

        $this->assertFalse($this->entitlements()->isLocked($organization));
        $this->assertNull($renewed->locked_at);
        $this->assertTrue($renewed->current_period_start->isSameDay(now()));
        $this->assertSame(Subscription::PAYMENT_PAID, $renewed->payment_status);
    }

    public function test_renewing_early_adds_the_new_term_after_the_current_one(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth'), Subscription::MONTHLY, $this->admin());
        $oldEnd = $subscription->expires_at->copy();

        $renewed = $this->lifecycle()->renew($subscription, $this->admin());

        $this->assertTrue($renewed->current_period_start->greaterThan($oldEnd));
        $this->assertSame('2026-12-14', $renewed->expires_at->toDateString());
    }

    public function test_a_billing_suspension_survives_renewal_and_an_admin_suspension_is_never_touched(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth'), Subscription::MONTHLY, $this->admin());

        $this->lifecycle()->suspend($subscription, $this->admin(), 'Cheque bounced.');
        $this->assertSame(Subscription::SUSPENDED, $this->entitlements()->getSubscriptionStatus($organization));

        $this->lifecycle()->renew($subscription->refresh(), $this->admin());
        $this->assertTrue($this->entitlements()->isLocked($organization), 'paying does not lift a suspension');

        $this->lifecycle()->unlock($subscription->refresh(), $this->admin(), 'Cleared.');
        $this->assertFalse($this->entitlements()->isLocked($organization));

        // The organization's own (security) status is not the lifecycle's.
        $organization->forceFill(['status' => Organization::SUSPENDED])->save();
        $this->lifecycle()->renew($subscription->refresh(), $this->admin());
        $this->assertSame(Organization::SUSPENDED, $organization->refresh()->status);
    }

    public function test_extending_moves_the_end_and_lifts_an_expiry_lock(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth', [], [], ['grace_days' => 0]), Subscription::MONTHLY, $this->admin());

        $this->travelTo($subscription->expires_at->copy()->addDay());
        $this->lifecycle()->tick($subscription->refresh());
        $this->assertTrue($this->entitlements()->isLocked($organization));

        $this->lifecycle()->extend($subscription->refresh(), now()->addDays(10), $this->admin(), 'Goodwill while they pay.');

        $this->assertFalse($this->entitlements()->isLocked($organization));
        $this->assertSame(10, $this->entitlements()->getDaysRemaining($organization));
        $this->assertSame('Goodwill while they pay.', SubscriptionEvent::query()->where('type', SubscriptionEvent::EXTENDED)->value('reason'));
    }

    public function test_cancelling_at_period_end_keeps_access_until_the_term_and_grace_run_out(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth', [], [], ['grace_days' => 0]), Subscription::MONTHLY, $this->admin());

        $this->lifecycle()->cancel($subscription, $this->admin(), 'Closing the clinic.');
        $this->assertFalse($this->entitlements()->isLocked($organization));

        $this->travelTo($subscription->expires_at->copy()->addMinute());
        $this->assertSame(Subscription::CANCELLED, $this->entitlements()->getSubscriptionStatus($organization));
        $this->assertTrue($this->entitlements()->isLocked($organization));
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshots, overrides, credits
    |--------------------------------------------------------------------------
    */

    public function test_repricing_a_plan_does_not_change_a_running_contract(): void
    {
        $organization = $this->organization();
        $plan = $this->plan('growth', [], ['doctors' => 5]);
        $subscription = $this->lifecycle()->assign($organization, $plan, Subscription::MONTHLY, $this->admin());

        $plan->update(['monthly_price' => 999900]);
        $plan->limits()->where('key', 'doctors')->update(['value' => 1]);

        $this->assertSame(149900, $this->entitlements()->getEffectivePrice($organization, 'plan'));
        $this->assertSame(5, $this->entitlements()->getResourceLimit($organization, Entitlements::DOCTORS));
        $this->assertSame(149900, $subscription->refresh()->snapshotPrice());
    }

    public function test_an_override_sets_a_limit_and_is_seen_on_the_next_request(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth', [], ['doctors' => 5]), Subscription::MONTHLY, $this->admin());

        $this->assertSame(5, $this->entitlements()->getResourceLimit($organization, Entitlements::DOCTORS));

        $this->lifecycle()->overrideLimit($subscription, Entitlements::DOCTORS, 12, $this->admin(), 'Seasonal locums.');
        $this->assertSame(12, $this->entitlements()->getResourceLimit($organization, Entitlements::DOCTORS));

        $this->lifecycle()->overrideLimit($subscription->refresh(), Entitlements::DOCTORS, null, $this->admin(), 'Unlimited for the pilot.');
        $this->assertNull($this->entitlements()->getResourceLimit($organization, Entitlements::DOCTORS));

        $this->assertSame(2, SubscriptionEvent::query()->where('type', SubscriptionEvent::LIMIT_OVERRIDDEN)->count());
    }

    public function test_granted_credits_add_to_this_months_allowance(): void
    {
        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth', [], ['whatsapp_messages' => 500]), Subscription::MONTHLY, $this->admin());

        $this->assertSame(500, $this->entitlements()->getRemainingQuota($organization, Entitlements::WHATSAPP_MESSAGES));

        $this->lifecycle()->grantCredits($subscription, Entitlements::WHATSAPP_MESSAGES, 250, $this->admin(), 'Apology for downtime.');

        $this->assertSame(750, $this->entitlements()->getRemainingQuota($organization, Entitlements::WHATSAPP_MESSAGES));
    }

    public function test_each_reminder_goes_once_and_the_processor_is_safe_to_run_repeatedly(): void
    {
        Mail::fake();

        $organization = $this->organization();
        $subscription = $this->lifecycle()->assign($organization, $this->plan('growth', [], [], ['grace_days' => 2]), Subscription::MONTHLY, $this->admin());
        $this->assertSame('2026-11-14', $subscription->expires_at->toDateString());

        $run = function (string $at) {
            $this->travelTo(Carbon::parse($at, 'Asia/Kolkata'));
            $this->artisan('subscriptions:process')->assertSuccessful();
            $this->artisan('subscriptions:process')->assertSuccessful();
        };

        // A dry run sends and changes nothing.
        $this->travelTo(Carbon::parse('2026-11-12 10:00', 'Asia/Kolkata'));
        $this->artisan('subscriptions:process --dry-run')->assertSuccessful();
        $this->assertSame(0, SubscriptionReminderDelivery::query()->count());

        $run('2026-11-12 10:00');   // two days left → the 3-day reminder
        $run('2026-11-14 10:00');   // the last day
        $run('2026-11-15 10:00');   // grace, one day of it left
        $run('2026-11-17 10:00');   // grace over: locked

        $kinds = SubscriptionReminderDelivery::query()->orderBy('id')->pluck('kind')->all();
        $this->assertSame(['before_3', 'expires_today', 'grace_1', 'locked'], $kinds);
        $this->assertSame(4, SubscriptionReminderDelivery::query()->where('status', SubscriptionReminderDelivery::SENT)->count());

        $this->assertSame(Subscription::EXPIRED, $subscription->refresh()->status);
        $this->assertSame(1, SubscriptionEvent::query()->where('type', SubscriptionEvent::LOCKED)->count());
    }

    public function test_the_countdown_rule_is_calendar_days_in_the_organizations_timezone(): void
    {
        $clock = new SubscriptionClock;
        $subscription = new Subscription([
            'expires_at' => Carbon::parse('2026-10-16 23:59:59', 'Asia/Kolkata'),
            'status' => Subscription::ACTIVE,
        ]);

        // 23:30 on the 15th in India is still the 15th: one day left.
        $this->assertSame(1, $clock->daysRemaining($subscription, 'Asia/Kolkata', Carbon::parse('2026-10-15 23:30', 'Asia/Kolkata')));
        // Ten minutes later it is the 16th: expires today.
        $this->assertSame(0, $clock->daysRemaining($subscription, 'Asia/Kolkata', Carbon::parse('2026-10-16 00:10', 'Asia/Kolkata')));
    }

    private function freshModuleAccess(): ModuleAccess
    {
        app()->forgetScopedInstances();

        return new ModuleAccess(app(SubscriptionFacts::class));
    }
}
