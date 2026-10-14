<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Tenant\Location;
use App\Models\Tenant\User as TenantUser;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\BillingSettings;
use App\Services\Subscriptions\ResourceCounter;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The lock and the limits, against a real tenant and its real routes — the
 * API refuses, not just the screens.
 */
class SubscriptionEnforcementTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /** @param  array<string, int|null>  $limits */
    private function plan(array $modules = [], array $limits = [], array $attributes = []): Plan
    {
        $plan = Plan::query()->create([
            'slug' => 'plan-'.uniqid(),
            'name' => 'Growth',
            'status' => Plan::PUBLISHED,
            'monthly_price' => 149900,
            'annual_price' => 1499000,
            'trial_days' => 14,
            'grace_days' => 0,
            ...$attributes,
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => array_key_exists($key, $limits) ? $limits[$key] : null]);
        }

        $plan->modules()->sync(Module::query()->whereIn('key', $modules)->pluck('id'));

        return $plan;
    }

    private function assign(Organization $organization, Plan $plan): Subscription
    {
        return app(SubscriptionLifecycle::class)->assign($organization, $plan, Subscription::MONTHLY, Actor::system('Test'), 'Test plan.');
    }

    public function test_a_new_organization_starts_on_complimentary_and_works_as_before(): void
    {
        $organization = $this->provisionOrganization();

        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->sole();
        $this->assertSame(Subscription::COMPLIMENTARY, $subscription->billing_cycle);

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.subscription.locked', false)
            ->assertJsonPath('data.subscription.is_complimentary', true)
            ->assertJsonPath('data.subscription.can_manage', true);

        $this->getJson('/api/v1/tenant/dashboard')->assertOk();
    }

    public function test_a_lapsed_subscription_is_refused_everywhere_but_the_way_out_and_renewal_reopens_it(): void
    {
        $organization = $this->provisionOrganization();
        $subscription = $this->assign($organization, $this->plan(['appointments']));

        // Past the end. Nothing has run — the dates alone decide.
        $this->travelTo($subscription->expires_at->copy()->addHour());
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/dashboard')
            ->assertStatus(423)
            ->assertJsonPath('code', 'subscription_locked')
            ->assertJsonPath('subscription.locked', true);

        $this->getJson('/api/v1/tenant/doctors')->assertStatus(423);
        $this->postJson('/api/v1/tenant/locations', ['name' => 'X', 'code' => 'X-1', 'type' => Location::CLINIC])->assertStatus(423);
        $this->getJson('/api/v1/tenant/customers')->assertStatus(423);

        // Who am I, and why — so the app can show the expired page.
        $this->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.subscription.locked', true)
            ->assertJsonPath('data.subscription.lock_reason', 'expired');

        // Nothing was deleted while locked.
        $this->assertTrue($this->onTenant($organization, fn () => TenantUser::on('organization')->where('email', self::STAFF_EMAIL)->exists()));

        app(SubscriptionLifecycle::class)->renew($subscription->refresh(), Actor::system('Test'), 'Paid.');

        $this->getJson('/api/v1/tenant/dashboard')->assertOk();

        $this->postJson('/api/v1/tenant/auth/logout')->assertOk();
    }

    public function test_grace_is_full_access_by_default_and_read_only_when_the_super_admin_says_so(): void
    {
        $organization = $this->provisionOrganization();
        $subscription = $this->assign($organization, $this->plan([], [], ['grace_days' => 5]));

        $this->travelTo($subscription->expires_at->copy()->addDay());
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/locations', ['name' => 'Grace Clinic', 'code' => 'GR-1', 'type' => Location::CLINIC])
            ->assertCreated();

        app(BillingSettings::class)->save(['grace_access' => BillingSettings::GRACE_READ_ONLY]);
        app()->forgetScopedInstances();

        $this->getJson('/api/v1/tenant/locations')->assertOk();
        $this->postJson('/api/v1/tenant/locations', ['name' => 'Second', 'code' => 'GR-2', 'type' => Location::CLINIC])
            ->assertStatus(423)
            ->assertJsonPath('code', 'subscription_read_only');
    }

    public function test_renewal_never_lifts_an_administrative_suspension(): void
    {
        $organization = $this->provisionOrganization();
        $subscription = $this->assign($organization, $this->plan());

        app(OrganizationRepositoryInterface::class)->changeStatus($organization, Organization::SUSPENDED, 'Security review.');
        app(SubscriptionLifecycle::class)->renew($subscription, Actor::system('Test'), 'Paid.');

        $this->assertSame(Organization::SUSPENDED, $organization->refresh()->status);

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_branch_doctor_and_employee_limits_are_enforced_and_inactive_records_do_not_count(): void
    {
        $organization = $this->provisionOrganization();
        $used = app(ResourceCounter::class)->all($organization);

        $this->assign($organization, $this->plan(['appointments'], [
            Entitlements::BRANCHES => $used[Entitlements::BRANCHES] + 1,
            Entitlements::DOCTORS => 1,
            Entitlements::EMPLOYEES => $used[Entitlements::EMPLOYEES] + 1,
        ]));

        $this->signInAsOwner($organization);

        // Branches: one more fits, the next does not.
        $this->postJson('/api/v1/tenant/locations', ['name' => 'North', 'code' => 'N-1', 'type' => Location::CLINIC])->assertCreated();
        $this->postJson('/api/v1/tenant/locations', ['name' => 'South', 'code' => 'S-1', 'type' => Location::CLINIC])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        // Doctors: one; a deactivated one frees the place; reactivating it is refused.
        $first = $this->postJson('/api/v1/tenant/doctors', ['name' => 'Dr. One', 'specialisation' => 'General Physician', 'is_active' => true])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/tenant/doctors', ['name' => 'Dr. Two', 'specialisation' => 'General Physician', 'is_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('limit');

        $this->putJson("/api/v1/tenant/doctors/{$first['id']}", ['name' => 'Dr. One', 'specialisation' => 'General Physician', 'is_active' => false])->assertOk();
        $this->postJson('/api/v1/tenant/doctors', ['name' => 'Dr. Two', 'specialisation' => 'General Physician', 'is_active' => true])->assertCreated();
        $this->putJson("/api/v1/tenant/doctors/{$first['id']}", ['name' => 'Dr. One', 'specialisation' => 'General Physician', 'is_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('limit');

        // Employees.
        $person = fn (string $email) => [
            'name' => 'Person',
            'email' => $email,
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'role' => TenantUser::STAFF,
            'is_active' => true,
        ];

        $this->postJson('/api/v1/tenant/users', $person('one-'.uniqid().'@example.com'))->assertCreated();
        $this->postJson('/api/v1/tenant/users', $person('two-'.uniqid().'@example.com'))
            ->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_a_module_outside_the_plan_is_refused_by_the_api_and_a_revoke_beats_the_plan(): void
    {
        $organization = $this->provisionOrganization();
        $this->assign($organization, $this->plan([]));
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/doctors')->assertForbidden();

        $this->assign($organization, $this->plan(['appointments']));
        $this->getJson('/api/v1/tenant/doctors')->assertOk();

        OrganizationModule::query()->create([
            'organization_id' => $organization->id,
            'module_id' => Module::query()->where('key', 'appointments')->value('id'),
            'is_enabled' => false,
            'reason' => 'Revoked while a dispute is settled.',
        ]);

        $this->getJson('/api/v1/tenant/doctors')->assertForbidden();
    }
}
