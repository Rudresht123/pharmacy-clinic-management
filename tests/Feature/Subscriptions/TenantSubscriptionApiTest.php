<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Tenant\MessageLog;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * The clinic's own subscription screens: reachable while locked by the
 * people who can renew, closed to everybody else, and buying only through an
 * invoice the server priced.
 */
class TenantSubscriptionApiTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /** @param  array<string, int|null>  $limits */
    private function plan(array $limits = []): Plan
    {
        $plan = Plan::query()->create([
            'slug' => 'growth-'.uniqid(),
            'name' => 'Growth',
            'status' => Plan::PUBLISHED,
            'monthly_price' => 100000,
            'annual_price' => 1000000,
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => array_key_exists($key, $limits) ? $limits[$key] : null]);
        }

        return $plan;
    }

    public function test_a_locked_owner_can_still_see_the_subscription_and_get_a_renewal_invoice(): void
    {
        $organization = $this->provisionOrganization();
        $subscription = app(SubscriptionLifecycle::class)->assign($organization, $this->plan(), Subscription::MONTHLY, Actor::system());

        $this->travelTo($subscription->expires_at->copy()->addDays(2));
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/dashboard')->assertStatus(423);

        $this->getJson('/api/v1/tenant/subscription')
            ->assertOk()
            ->assertJsonPath('data.status.locked', true)
            ->assertJsonPath('data.status.plan.name', 'Growth');

        // No gateway keys here: the invoice is issued, and the clinic is told
        // how to pay offline. The price is the server's.
        $renew = $this->postJson('/api/v1/tenant/subscription/renew', ['price' => 1])
            ->assertOk()
            ->assertJsonPath('data.invoice.total', 118000)
            ->assertJsonPath('data.checkout', null);

        $this->assertNotNull($renew->json('data.offline'));
        $this->assertSame(1, SubscriptionInvoice::query()->where('organization_id', $organization->id)->count());

        $this->getJson('/api/v1/tenant/subscription/invoices')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_staff_without_the_capability_are_refused(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/subscription')->assertForbidden();
        $this->postJson('/api/v1/tenant/subscription/renew')->assertForbidden();
    }

    public function test_an_exhausted_email_allowance_stops_the_send_and_says_why(): void
    {
        $organization = $this->provisionOrganization();

        // Faked only now: provisioning above runs on the queue.
        Queue::fake();

        app(SubscriptionLifecycle::class)->assign($organization, $this->plan([Entitlements::EMAILS => 0]), Subscription::MONTHLY, Actor::system());
        app()->forgetScopedInstances();

        $log = $this->onTenant($organization, fn () => app(EmailManager::class)->forOrganization($organization)->queue(
            EmailMessage::make(to: 'patient@example.com', subject: 'Hello', body: 'Body', template: 'test', variables: []),
        ));

        $this->assertSame(MessageLog::FAILED, $log->status);
        $this->assertStringContainsString('used up', (string) $log->failure_reason);
        Queue::assertNothingPushed();
    }
}
