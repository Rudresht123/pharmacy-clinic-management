<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\Subscription;
use App\Support\Platform\OrganizationCode;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The plan is chosen on the organization's registration page, so a new
 * organization starts on it from the first moment — no second step on the
 * billing screens. The tenant database is left to the (faked) queue: what is
 * tested is the subscription created in the same transaction.
 */
class OrganizationRegistrationPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Queue::fake();
    }

    private function admin(): self
    {
        return $this->actingAs(PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create(), 'platform');
    }

    private function plan(array $attributes = []): Plan
    {
        $plan = Plan::query()->create([
            'slug' => 'growth-'.uniqid(),
            'name' => 'Growth',
            'status' => Plan::PUBLISHED,
            'monthly_price' => 999900,
            'annual_price' => 9999000,
            'trial_days' => 14,
            'grace_days' => 3,
            ...$attributes,
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => null]);
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    private function payload(array $subscription = []): array
    {
        $type = OrganizationType::create(['name' => 'Clinic '.uniqid(), 'slug' => 'clinic-'.uniqid(), 'is_active' => true]);
        $unique = uniqid();

        return array_filter([
            'organization_name' => 'Plan Test '.$unique,
            'organization_code' => OrganizationCode::generate(),
            'organization_type_id' => $type->id,
            'subdomain' => 'plan-test-'.$unique,
            'email' => "owner-{$unique}@example.com",
            'password' => 'OwnerPass1!',
            'password_confirmation' => 'OwnerPass1!',
            'subscription' => $subscription ?: null,
        ]);
    }

    public function test_the_plan_chosen_at_registration_is_the_one_it_starts_on(): void
    {
        $plan = $this->plan();

        $this->admin()->postJson('/api/v1/admin/organizations', $this->payload([
            'plan_id' => $plan->id,
            'billing_cycle' => Subscription::ANNUAL,
            'price' => 8999000,
            'payment_status' => Subscription::PAYMENT_WAIVED,
        ]))->assertCreated();

        $organization = Organization::sole();
        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->sole();

        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame($plan->id, $organization->plan_id);
        $this->assertSame(Subscription::ANNUAL, $subscription->billing_cycle);
        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertSame(Subscription::PAYMENT_WAIVED, $subscription->payment_status);
        $this->assertSame(8999000, $subscription->snapshot['price']);
        $this->assertNotNull($subscription->expires_at);
    }

    public function test_a_trial_chosen_at_registration_counts_down_from_today(): void
    {
        $plan = $this->plan();

        // As the multipart form sends it: every value a string.
        $this->admin()->postJson('/api/v1/admin/organizations', $this->payload([
            'plan_id' => (string) $plan->id,
            'billing_cycle' => 'monthly',
            'trial' => '1',
        ]))->assertCreated();

        $subscription = Subscription::query()->where('is_current', true)->sole();

        $this->assertSame(Subscription::TRIAL, $subscription->status);
        $this->assertSame(Subscription::MONTHLY, $subscription->billing_cycle);
        $this->assertNotNull($subscription->trial_ends_at);
    }

    public function test_a_plan_off_sale_or_a_trial_it_does_not_have_is_refused_before_anything_is_made(): void
    {
        $archived = $this->plan(['status' => Plan::ARCHIVED]);
        $noTrial = $this->plan(['trial_days' => 0, 'name' => 'Enterprise']);

        $this->admin()->postJson('/api/v1/admin/organizations', $this->payload(['plan_id' => $archived->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription.plan_id');

        $this->admin()->postJson('/api/v1/admin/organizations', $this->payload(['plan_id' => $noTrial->id, 'trial' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription.trial' => 'The Enterprise plan has no trial.']);

        // "Paid" only ever comes from a payment.
        $this->admin()->postJson('/api/v1/admin/organizations', $this->payload(['plan_id' => $noTrial->id, 'payment_status' => Subscription::PAYMENT_PAID]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription.payment_status');

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_without_a_choice_it_still_starts_on_complimentary(): void
    {
        $this->admin()->postJson('/api/v1/admin/organizations', $this->payload())->assertCreated();

        $subscription = Subscription::query()->where('is_current', true)->sole();

        $this->assertSame(Plan::COMPLIMENTARY, $subscription->plan->slug);
        $this->assertSame(Subscription::COMPLIMENTARY, $subscription->billing_cycle);
    }
}
