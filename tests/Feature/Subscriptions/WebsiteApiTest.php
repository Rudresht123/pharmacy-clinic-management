<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Module;
use App\Models\Platform\ModulePrice;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\PublicRegistration;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The marketing website's API: what it may see, what it may never see, and
 * signing up through it — all from the master database.
 */
class WebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        Queue::fake();
        Mail::fake();

        OrganizationType::query()->create(['name' => 'Clinic', 'slug' => 'clinic-'.uniqid(), 'is_active' => true]);
    }

    private function plan(string $slug, string $status = Plan::PUBLISHED, bool $public = true, int $trial = 14): Plan
    {
        $plan = Plan::query()->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'status' => $status,
            'is_public' => $public,
            'monthly_price' => 149900,
            'annual_price' => 1499000,
            'trial_days' => $trial,
            'features' => [Entitlements::MOBILE_APP],
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => 10]);
        }

        $plan->modules()->sync(Module::query()->where('key', 'appointments')->pluck('id'));

        return $plan;
    }

    public function test_only_published_public_plans_are_shown_and_no_cost_or_margin_ever_is(): void
    {
        $this->plan('growth');
        $this->plan('draft-plan', Plan::DRAFT);
        $this->plan('private-deal', Plan::PUBLISHED, public: false);

        ModulePrice::query()->create([
            'module_id' => Module::query()->where('key', 'laboratory')->value('id'),
            'monthly_price' => 50000,
            'annual_price' => 500000,
            'provider_cost' => 12345,
            'effective_from' => now()->subDay(),
        ]);

        $plans = $this->getJson('/api/v1/website/plans')->assertOk();
        $this->assertSame(['growth'], array_column($plans->json('data'), 'slug'));

        $this->getJson('/api/v1/website/plans/draft-plan')->assertNotFound();
        $this->getJson('/api/v1/website/plans/private-deal')->assertNotFound();
        $this->getJson('/api/v1/website/plans/complimentary')->assertNotFound();

        foreach (['/api/v1/website/pricing', '/api/v1/website/modules', '/api/v1/website/addons', '/api/v1/website/plans/growth'] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('provider_cost', $body, $url);
            $this->assertStringNotContainsString('12345', $body, $url);
            $this->assertStringNotContainsString('margin', $body, $url);
        }
    }

    public function test_the_website_never_touches_a_tenant_database(): void
    {
        $this->plan('growth');
        $spy = $this->spy(TenantConnectionService::class);

        $this->getJson('/api/v1/website/pricing')->assertOk();
        $this->getJson('/api/v1/website/plans')->assertOk();
        $this->getJson('/api/v1/website/modules')->assertOk();
        $this->postJson('/api/v1/website/quote', ['plan' => 'growth', 'billing_cycle' => 'monthly'])->assertOk();

        $spy->shouldNotHaveReceived('connect');
        $spy->shouldNotHaveReceived('run');
    }

    public function test_a_quote_is_the_servers_and_ignores_any_price_sent_with_it(): void
    {
        $this->plan('growth');

        $this->postJson('/api/v1/website/quote', [
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'price' => 1,
            'total' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.subtotal', 149900)
            ->assertJsonPath('data.tax', 26982)
            ->assertJsonPath('data.total', 176882);
    }

    public function test_a_price_change_reaches_the_website_at_once(): void
    {
        $plan = $this->plan('growth');
        $this->getJson('/api/v1/website/plans/growth')->assertJsonPath('data.monthly_price', 149900);

        $this->actingAs(PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create(), 'platform')
            ->putJson("/api/v1/admin/billing/plans/{$plan->uuid}", [
                'name' => 'Growth',
                'monthly_price' => 199900,
                'annual_price' => 1999000,
                'trial_days' => 14,
                'grace_days' => 3,
                'is_public' => true,
                'limits' => array_fill_keys(Entitlements::limitKeys(), 10),
                'modules' => ['appointments'],
            ])->assertOk();

        $this->getJson('/api/v1/website/plans/growth')->assertJsonPath('data.monthly_price', 199900);
    }

    public function test_a_trial_sign_up_is_idempotent_and_goes_through_the_existing_onboarding(): void
    {
        $this->plan('growth');

        $payload = [
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'start_trial' => true,
            'organization_name' => 'Sunrise Clinic',
            'contact_name' => 'Dr. Mehta',
            'email' => 'owner@sunrise.test',
            'subdomain' => 'sunrise',
        ];

        $this->postJson('/api/v1/website/registrations', $payload)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $first = $this->withHeader('Idempotency-Key', 'signup-1')->postJson('/api/v1/website/registrations', $payload)->assertCreated();
        $again = $this->withHeader('Idempotency-Key', 'signup-1')->postJson('/api/v1/website/registrations', $payload)->assertCreated();

        $this->assertSame($first->json('data.reference'), $again->json('data.reference'));
        $this->assertSame(1, PublicRegistration::query()->count());
        $this->assertSame(1, Organization::query()->where('subdomain', 'sunrise')->count());

        $organization = Organization::query()->where('subdomain', 'sunrise')->sole();
        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->sole();
        $this->assertSame(Subscription::TRIAL, $subscription->status);

        $this->getJson('/api/v1/website/registration-status/'.$first->json('data.reference'))
            ->assertOk()
            ->assertJsonPath('data.status', PublicRegistration::PROVISIONING);

        // The same address cannot be taken twice.
        $this->withHeader('Idempotency-Key', 'signup-2')->postJson('/api/v1/website/registrations', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('subdomain');
    }

    public function test_a_paid_sign_up_becomes_an_organization_only_after_the_payment_is_verified(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'secret',
            'services.razorpay.base_url' => 'https://api.razorpay.test/v1',
        ]);

        $this->plan('growth');

        $reference = $this->withHeader('Idempotency-Key', 'paid-1')->postJson('/api/v1/website/registrations', [
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'organization_name' => 'Lotus Clinic',
            'contact_name' => 'Dr. Rao',
            'email' => 'owner@lotus.test',
            'subdomain' => 'lotus',
        ])->assertCreated()->assertJsonPath('data.status', PublicRegistration::PENDING_PAYMENT)->json('data.reference');

        $this->assertSame(0, Organization::query()->where('subdomain', 'lotus')->count());

        Http::fake([
            'api.razorpay.test/v1/orders' => Http::response(['id' => 'order_9', 'amount' => 176882, 'currency' => 'INR', 'status' => 'created']),
            'api.razorpay.test/v1/payments/pay_9' => Http::response(['id' => 'pay_9', 'order_id' => 'order_9', 'status' => 'captured', 'amount' => 176882, 'currency' => 'INR']),
        ]);

        $this->postJson('/api/v1/website/checkout', ['reference' => $reference])
            ->assertOk()
            ->assertJsonPath('data.checkout.order_id', 'order_9')
            ->assertJsonPath('data.total', 176882);

        // A browser claiming success without a valid signature changes nothing.
        $this->postJson('/api/v1/website/checkout/verify', [
            'reference' => $reference,
            'razorpay_order_id' => 'order_9',
            'razorpay_payment_id' => 'pay_9',
            'razorpay_signature' => 'forged',
        ])->assertStatus(422);
        $this->assertSame(0, Organization::query()->where('subdomain', 'lotus')->count());

        $this->postJson('/api/v1/website/checkout/verify', [
            'reference' => $reference,
            'razorpay_order_id' => 'order_9',
            'razorpay_payment_id' => 'pay_9',
            'razorpay_signature' => hash_hmac('sha256', 'order_9|pay_9', 'secret'),
        ])->assertOk()->assertJsonPath('data.status', PublicRegistration::PROVISIONING);

        $organization = Organization::query()->where('subdomain', 'lotus')->sole();
        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->sole();

        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertSame(Subscription::PAYMENT_PAID, $subscription->payment_status);
        $this->assertSame($organization->id, SubscriptionInvoice::query()->sole()->organization_id, 'the first invoice now belongs to the clinic');
    }
}
